<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierImportProfileSampleRequest;
use App\Http\Requests\UpdateExternalSupplierProfileRequest;
use App\Http\Requests\UpdateSupplierImportProfileRequest;
use App\Http\Requests\UpdateSupplierSynchronizationRequest;
use App\Models\AuditEvent;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Models\SupplierSynchronization;
use App\Services\AuditLogger;
use App\Services\GmailProfileDrafts;
use App\Services\IcorpCredentials;
use App\Services\IcorpFailure;
use App\Services\PriceList\ProfileValidator;
use App\Services\PriceList\SampleWorkbookReader;
use App\Services\SupplierImportSettings;
use App\Services\SupplierVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;
use Throwable;

class SupplierImportProfileController extends Controller
{
    public function edit(Request $request, int $supplier, SampleWorkbookReader $reader): RedirectResponse
    {
        Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        app(GmailProfileDrafts::class)->get($request, $supplier);
        $request->session()->reflash();

        return redirect()->route('admin.supplier-sync.show', ['supplier' => $supplier, 'tab' => 'sources', ...$request->only('gmail_draft')]);
    }

    /** @return array<string, mixed> */
    public function workspaceData(Request $request, int $supplier, SampleWorkbookReader $reader): array
    {
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $profile = $organization->importProfile ?? new SupplierImportProfile(['name' => 'Основной профиль', 'file_type' => 'xlsx', 'configuration' => ProfileValidator::defaults()]);
        $senderEmails = $organization->senderAddresses()->orderBy('normalized_email')->pluck('email')->implode("\n");
        $draft = app(GmailProfileDrafts::class)->get($request, $supplier);
        if ($draft) {
            $profile = clone $profile;
            $profile->sample_metadata = $draft['sample'];
            $profile->file_type = $draft['sample']['extension'];
            $profile->attachment_filename_filter = $draft['sample']['filename'];
            $senderEmails = collect(explode("\n", $senderEmails))->push($draft['sender'])->filter()->unique()->implode("\n");
        }
        $preview = null;
        if ($profile->sample_metadata) {
            try {
                $worksheetNames = array_column($profile->sample_metadata['worksheets'], 'name');
                $worksheet = old('worksheet', $profile->configuration['worksheet'] ?? null);
                if (! in_array($worksheet, $worksheetNames, true)) {
                    $worksheet = null;
                }
                $preview = $reader->preview(
                    $profile->sample_metadata['path'],
                    $profile->sample_metadata['extension'],
                    $worksheet,
                );
            } catch (Throwable) {
                session()->flash('warning', 'Сохранённый образец не удалось прочитать. Загрузите его заново.');
            }
        }

        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier)->first()
            ?? new SupplierSynchronization(['automatic_enabled' => false, 'paused' => false, 'selection_mode' => 'newest_available', 'fetch_interval_minutes' => 60, 'freshness_hours' => 24, 'sources' => collect(SupplierSynchronization::defaultSources())->map(fn (array $settings): array => array_replace($settings, ['enabled' => false]))->all()]);
        $partners = ExternalSupplierPartner::where('supplier_organization_id', $supplier)->orderBy('source')->get();
        $sources = collect(SupplierSynchronization::defaultSources())->map(fn (array $settings, string $source): array => array_replace($settings, is_array($configuration->sources[$source] ?? null) ? $configuration->sources[$source] : []))->sortBy('priority');
        $readiness = collect(array_keys(SupplierSynchronization::defaultSources()))->mapWithKeys(fn (string $source): array => [$source => app(SupplierImportSettings::class)->readinessReason($organization, $source, true)]);

        $unmappedPartners = ExternalSupplierPartner::whereNull('supplier_organization_id')->orderBy('name')->get();
        try {
            $icorpLogin = app(IcorpCredentials::class)->current()['phone'];
        } catch (IcorpFailure) {
            $icorpLogin = '';
        }
        $connectionStatuses = collect(['icorp', 'pricelist_tj'])->mapWithKeys(fn (string $source): array => [$source => Cache::get('external-prices:status:'.$source, ['status' => 'unknown'])]);
        $dispatchPaused = app(SupplierVersions::class)->paused();
        $externalFetchAudit = AuditEvent::where('subject_type', Organization::class)->where('subject_id', $organization->id)->whereIn('event', ['supplier_external_fetch.overridden', 'supplier_external_fetch.seeded'])->latest('id')->limit(20)->get();

        return compact('externalFetchAudit', 'icorpLogin', 'connectionStatuses', 'unmappedPartners', 'organization', 'profile', 'senderEmails', 'preview', 'draft', 'configuration', 'partners', 'sources', 'readiness', 'dispatchPaused');
    }

    public function storeSample(StoreSupplierImportProfileSampleRequest $request, int $supplier, SampleWorkbookReader $reader): RedirectResponse
    {
        return DB::transaction(function () use ($request, $supplier, $reader): RedirectResponse {
            $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->lockForUpdate()->findOrFail($supplier);
            $upload = $request->file('sample');
            $extension = strtolower($upload->getClientOriginalExtension());
            $path = $upload->storeAs('supplier-import-samples/'.$organization->id, Str::uuid().'.'.$extension, config('price-list-imports.disk'));

            try {
                $inspection = $reader->inspect($path, $extension);
                foreach ($inspection['worksheets'] as $worksheet) {
                    if ($worksheet['highest_row'] > config('price-list-imports.max_rows') || $worksheet['highest_column'] > config('price-list-imports.max_columns')) {
                        throw ValidationException::withMessages(['sample' => 'Образец превышает допустимый размер таблицы.']);
                    }
                }
                if (collect($inspection['worksheets'])->every(fn (array $worksheet): bool => $worksheet['highest_row'] === 0 || $worksheet['highest_column'] === 0)) {
                    throw ValidationException::withMessages(['sample' => 'В образце нет читаемых ячеек.']);
                }

                $metadata = [
                    'path' => $path,
                    'filename' => $upload->getClientOriginalName(),
                    'extension' => $extension,
                    'size' => $upload->getSize(),
                    'checksum' => hash_file('sha256', Storage::disk(config('price-list-imports.disk'))->path($path)),
                    'worksheets' => $inspection['worksheets'],
                ];
                $profile = $organization->importProfile;
                $previousPath = $profile?->sample_metadata['path'] ?? null;
                SupplierImportProfile::updateOrCreate(
                    ['supplier_organization_id' => $organization->id],
                    [
                        'name' => $profile?->name ?? 'Основной профиль',
                        'file_type' => $extension,
                        'configuration' => $profile?->configuration ?? ProfileValidator::defaults(),
                        'sample_metadata' => $metadata,
                        'updated_by' => $request->user()->id,
                        'is_active' => $profile?->is_active ?? false,
                    ],
                );
                SupplierSynchronization::where('supplier_organization_id', $organization->id)->increment('version');
                if ($previousPath && $previousPath !== $path) {
                    DB::afterCommit(fn () => Storage::disk(config('price-list-imports.disk'))->delete($previousPath));
                }
            } catch (ValidationException $exception) {
                Storage::disk(config('price-list-imports.disk'))->delete($path);
                throw $exception;
            } catch (Throwable $exception) {
                Storage::disk(config('price-list-imports.disk'))->delete($path);
                report($exception);
                throw ValidationException::withMessages(['sample' => 'Файл не удалось прочитать как таблицу.']);
            }

            return back()->with('success', 'Образец сохранён. Теперь сопоставьте столбцы.');
        }, 3);
    }

    public function samplePreview(Request $request, int $supplier, SampleWorkbookReader $reader): JsonResponse
    {
        abort_unless($request->user()?->isAdminMode(), 403);
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $draft = app(GmailProfileDrafts::class)->get($request, $supplier);
        $metadata = $draft['sample'] ?? $organization->importProfile?->sample_metadata;
        if (! $metadata) {
            throw ValidationException::withMessages(['sample' => 'Сначала загрузите образец прайс-листа.']);
        }
        $validated = $request->validate(['worksheet' => ['required', 'string', 'max:255']]);

        try {
            return response()->json($reader->preview($metadata['path'], $metadata['extension'], $validated['worksheet']));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['worksheet' => $exception->getMessage()]);
        }
    }

    public function update(UpdateSupplierImportProfileRequest $request, int $supplier, ProfileValidator $validator, AuditLogger $audit): RedirectResponse
    {
        return DB::transaction(function () use ($request, $supplier, $validator, $audit): RedirectResponse {
            $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->lockForUpdate()->findOrFail($supplier);
            $profile = $organization->importProfile;
            $draft = app(GmailProfileDrafts::class)->get($request, $supplier);
            $metadata = $draft['sample'] ?? $profile?->sample_metadata;
            if (! $metadata) {
                throw ValidationException::withMessages(['sample' => 'Сначала загрузите образец прайс-листа.']);
            }
            $worksheet = collect($metadata['worksheets'])->firstWhere('name', $request->validated('worksheet'));
            if ($worksheet === null) {
                throw ValidationException::withMessages(['worksheet' => 'Выбранный лист недоступен в образце.']);
            }
            $dataRow = (int) $request->validated('data_row');
            if ($dataRow > min(100, (int) $worksheet['highest_row'])) {
                throw ValidationException::withMessages(['data_row' => 'Выберите одну из показанных строк образца.']);
            }

            $validColumns = [];
            for ($column = 1; $column <= (int) $worksheet['highest_column']; $column++) {
                $validColumns[] = Coordinate::stringFromColumnIndex($column);
            }
            $columnMappings = $request->validated('column_mappings');
            if (array_diff(array_keys($columnMappings), $validColumns) !== []) {
                throw ValidationException::withMessages(['column_mappings' => 'Сопоставление содержит неизвестный столбец.']);
            }
            $selectedAttributes = array_values(array_filter($columnMappings, fn (mixed $field): bool => is_string($field) && $field !== '' && $field !== 'ignore'));
            if (count($selectedAttributes) !== count(array_unique($selectedAttributes))) {
                throw ValidationException::withMessages(['column_mappings' => 'Каждый атрибут можно выбрать только один раз.']);
            }
            $mapping = [];
            foreach ($columnMappings as $column => $field) {
                if ($field && $field !== 'ignore') {
                    $mapping[$field] = $column;
                }
            }
            if (! isset($mapping['name'], $mapping['price'])) {
                throw ValidationException::withMessages(['column_mappings' => 'Укажите столбцы для названия товара и цены.']);
            }
            if ($request->validated('matching_strategy') === 'sku' && ! isset($mapping['sku'])) {
                throw ValidationException::withMessages(['column_mappings' => 'Для сопоставления строго по SKU укажите столбец SKU.']);
            }
            $configuration = array_replace($profile->configuration ?? ProfileValidator::defaults(), [
                'worksheet' => $request->validated('worksheet'),
                'header_row' => $dataRow > 1 ? $dataRow - 1 : null,
                'data_row' => $dataRow,
                'mapping' => $mapping,
                'decimal_separator' => $request->validated('decimal_separator'),
                'matching_strategy' => $request->validated('matching_strategy'),
                'activation_mode' => 'automatic',
            ]);
            if ($request->has('publication_source')) {
                $publicationSource = $request->validated('publication_source');
                $pattern = $request->validated('publication_pattern');
                if ($publicationSource === 'filename' && @preg_match($pattern, '') === false) {
                    throw ValidationException::withMessages(['publication_pattern' => 'Введите корректное регулярное выражение с первой группой для даты.']);
                }
                $configuration['inventory'] = ['source' => $publicationSource === 'review' ? 'upload_time' : $publicationSource,
                    'cell' => strtoupper($request->validated('publication_cell') ?? ''), 'pattern' => $pattern, 'group' => 1,
                    'formats' => array_values(array_filter(array_map('trim', explode(',', $request->validated('publication_formats') ?? 'd.m.Y,Y-m-d'))))];
            }
            $configuration = $validator->validate($configuration);
            $emails = collect(preg_split('/\R/u', (string) $request->validated('sender_emails')))->map(fn (string $email): string => mb_strtolower(trim($email)))->filter()->unique()->values();
            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw ValidationException::withMessages(['sender_emails' => 'Адрес '.$email.' неверен.']);
                }
            }
            if ($draft && $request->validated('file_type') !== $metadata['extension']) {
                throw ValidationException::withMessages(['file_type' => 'Тип файла должен совпадать с образцом.']);
            }
            $before = $profile?->only(['name', 'file_type', 'configuration', 'attachment_filename_filter']) ?? [];
            $previousPath = $profile?->sample_metadata['path'] ?? null;
            if ($draft) {
                $target = 'supplier-import-samples/'.$organization->id.'/'.Str::uuid().'.'.$metadata['extension'];
                if (! Storage::disk(config('price-list-imports.disk'))->copy($metadata['path'], $target)) {
                    throw ValidationException::withMessages(['sample' => 'Не удалось сохранить образец. Повторите попытку.']);
                }
                $metadata['path'] = $target;
            }
            $profile ??= new SupplierImportProfile(['supplier_organization_id' => $organization->id]);
            try {
                DB::transaction(function () use ($organization, $profile, $configuration, $request, $emails, $metadata, $audit, $before): void {
                    SupplierSynchronization::where('supplier_organization_id', $organization->id)->increment('version');
                    $profile->fill(['sample_metadata' => $metadata, 'attachment_filename_filter' => $request->validated('attachment_filename_filter'), 'name' => $request->validated('name'), 'file_type' => $request->validated('file_type'), 'configuration' => $configuration, 'updated_by' => $request->user()->id, 'is_active' => true])->save();
                    $organization->senderAddresses()->whereNotIn('normalized_email', $emails)->delete();
                    foreach ($emails as $email) {
                        SupplierSenderAddress::updateOrCreate(['normalized_email' => $email, 'supplier_organization_id' => $organization->id], ['email' => $email]);
                    }
                    $audit->log('supplier_import_profile.updated', $profile, $before, $profile->only(['name', 'file_type', 'configuration', 'attachment_filename_filter']));
                });
            } catch (Throwable $exception) {
                if ($draft) {
                    Storage::disk(config('price-list-imports.disk'))->delete($metadata['path']);
                }
                throw $exception;
            }
            if ($draft) {
                DB::afterCommit(function () use ($request, $draft, $previousPath, $metadata): void {
                    $request->session()->forget('gmail_profile_drafts.'.$request->input('gmail_draft'));
                    Storage::disk(config('price-list-imports.disk'))->delete($draft['sample']['path']);
                    if ($previousPath && $previousPath !== $metadata['path']) {
                        Storage::disk(config('price-list-imports.disk'))->delete($previousPath);
                    }
                });

                return redirect()->route('admin.gmail.show', $draft['message'])->with('success', 'Профиль и маршрутизация сохранены. Нажмите «Повторить импорт».');
            }

            return back()->with('success', 'Профиль импорта сохранён.');
        }, 3);
    }

    public function settings(UpdateSupplierSynchronizationRequest $request, Organization $supplier, SupplierImportSettings $settings): RedirectResponse
    {
        $settings->update($supplier, $request->validated());

        return back()->with('success', 'Настройки источника сохранены.');
    }

    public function externalProfile(UpdateExternalSupplierProfileRequest $request, Organization $supplier, ExternalSupplierPartner $partner, SupplierImportSettings $settings): RedirectResponse
    {
        $settings->updateProfile($supplier->id, $partner, $request->validated());

        return back()->with('success', 'Профиль источника сохранён.');
    }
}
