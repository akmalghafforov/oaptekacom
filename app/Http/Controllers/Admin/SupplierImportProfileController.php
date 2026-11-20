<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierImportProfileSampleRequest;
use App\Http\Requests\UpdateSupplierImportProfileRequest;
use App\Models\Organization;
use App\Models\SupplierImportProfile;
use App\Services\AuditLogger;
use App\Services\GmailProfileDrafts;
use App\Services\PriceList\ProfileValidator;
use App\Services\PriceList\SampleWorkbookReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;
use Throwable;

class SupplierImportProfileController extends Controller
{
    public function index(int $supplier): View
    {
        $organization = Organization::where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $profiles = $organization->importProfiles()->orderBy('name')->orderBy('id')->get();
        $senderEmails = $organization->senderAddresses()->orderBy('normalized_email')->pluck('email')->implode("\n");

        return view('admin.supplier-import-profiles.index', compact('organization', 'profiles', 'senderEmails'));
    }

    public function updateChannel(Request $request, int $supplier, string $channel, AuditLogger $audit): RedirectResponse
    {
        $field = $channel.'_import_profile_id';
        $validated = $request->validate($channel === 'senders'
            ? ['sender_emails' => ['present', 'nullable', 'string', 'max:4000']]
            : [$field => ['present', 'nullable', 'integer']]);
        DB::transaction(function () use ($supplier, $channel, $audit, $field, $validated): void {
            $organization = Organization::where('type', OrganizationType::Wholesaler)->lockForUpdate()->findOrFail($supplier);
            if ($channel === 'senders') {
                $before = ['sender_emails' => $organization->senderAddresses()->orderBy('normalized_email')->pluck('normalized_email')->all()];
                $after = ['sender_emails' => $this->senderEmails($validated['sender_emails'])];
                $this->saveSenderEmails($organization, $after['sender_emails']);
            } else {
                $profileId = $validated[$field];
                if ($profileId !== null && ! $organization->importProfiles()->whereKey($profileId)->where('is_active', true)->where($channel.'_enabled', true)->exists()) {
                    throw ValidationException::withMessages([$field => 'Выберите доступный профиль этого поставщика.']);
                }
                $before = $organization->only($field);
                $after = [$field => $profileId === null ? null : (int) $profileId];
                $organization->update($after);
            }
            $audit->log('supplier_import_profile.'.$channel.'_settings_updated', $organization, $before, $after);
        });

        return back()->with('success', 'Настройки импорта сохранены.');
    }

    private function target(Request $request, Organization $organization, ?array $draft): ?SupplierImportProfile
    {
        $target = $request->query('profile');
        if ($draft) {
            $draftTarget = $draft['profile'] ?? null;
            abort_if($target !== null && (string) ($draftTarget ?? 'new') !== $target, 404);

            return $draftTarget === null ? null : $organization->importProfiles()->findOrFail($draftTarget);
        }

        if ($target === 'new') {
            return null;
        }

        if ($target !== null) {
            $profileId = is_string($target) ? filter_var($target, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            abort_if($profileId === false, 404);

            return $organization->importProfiles()->findOrFail($profileId);
        }

        $inbox = $organization->importProfiles()->find($organization->inbox_import_profile_id);
        if ($inbox) {
            return $inbox;
        }
        $profiles = $organization->importProfiles()->limit(2)->get();
        abort_if($profiles->count() > 1, 404);

        return $profiles->first();
    }

    /** @return list<string> */
    private function senderEmails(?string $input): array
    {
        $emails = collect(preg_split('/\R/u', (string) $input))->map(fn (string $email): string => mb_strtolower(trim($email)))->filter()->unique()->sort()->values()->all();
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['sender_emails' => 'Адрес '.$email.' неверен.']);
            }
        }

        return $emails;
    }

    /** @param list<string> $emails */
    private function saveSenderEmails(Organization $organization, array $emails): void
    {
        $organization->senderAddresses()->whereNotIn('normalized_email', $emails)->delete();
        foreach ($emails as $email) {
            $organization->senderAddresses()->updateOrCreate(['normalized_email' => $email], ['email' => $email]);
        }
    }

    public function edit(Request $request, int $supplier, SampleWorkbookReader $reader): View
    {
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $draft = app(GmailProfileDrafts::class)->get($request, $supplier);
        $profile = $this->target($request, $organization, $draft) ?? new SupplierImportProfile(['name' => 'Основной профиль', 'file_type' => 'xlsx', 'configuration' => ProfileValidator::defaults()]);
        $senderEmails = $organization->senderAddresses()->orderBy('normalized_email')->pluck('email')->implode("\n");
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

        $target = $draft ? null : ($profile->id ?? 'new');
        $profileRoute = ['supplier' => $organization, 'profile' => $target];

        return view('admin.supplier-import-profiles.edit', compact('organization', 'profile', 'senderEmails', 'preview', 'draft', 'profileRoute'));
    }

    public function storeSample(StoreSupplierImportProfileSampleRequest $request, int $supplier, SampleWorkbookReader $reader): RedirectResponse
    {
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $this->target($request, $organization, null);
        $upload = $request->file('sample');
        $extension = strtolower($upload->getClientOriginalExtension());
        $path = $upload->storeAs('supplier-import-samples/'.$organization->id, Str::uuid().'.'.$extension, config('price-list-imports.disk'));

        abort_unless($path, 500);
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
            [$profile, $previousPath] = DB::transaction(function () use ($organization, $request, $metadata, $extension): array {
                $organization = Organization::lockForUpdate()->findOrFail($organization->id);
                $profile = $this->target($request, $organization, null) ?? new SupplierImportProfile([
                    'supplier_organization_id' => $organization->id,
                    'name' => 'Основной профиль',
                    'configuration' => ProfileValidator::defaults(),
                    'is_active' => false,
                ]);
                $previousPath = $profile->sample_metadata['path'] ?? null;
                $before = $profile->only(['sample_metadata', 'file_type']);
                $profile->fill(['file_type' => $extension, 'sample_metadata' => $metadata, 'updated_by' => $request->user()->id])->save();
                app(AuditLogger::class)->log('supplier_import_profile.sample_updated', $profile, $before, $profile->only(['sample_metadata', 'file_type']));

                return [$profile, $previousPath];
            });
            if ($previousPath && $previousPath !== $path) {
                Storage::disk(config('price-list-imports.disk'))->delete($previousPath);
            }
        } catch (ValidationException $exception) {
            Storage::disk(config('price-list-imports.disk'))->delete($path);
            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk(config('price-list-imports.disk'))->delete($path);
            report($exception);
            throw ValidationException::withMessages(['sample' => 'Файл не удалось прочитать как таблицу.']);
        }

        return redirect()->route('admin.supplier-import-profiles.edit', ['supplier' => $supplier, 'profile' => $profile->id])
            ->with('success', 'Образец сохранён. Теперь сопоставьте столбцы.');
    }

    public function samplePreview(Request $request, int $supplier, SampleWorkbookReader $reader): JsonResponse
    {
        abort_unless($request->user()?->isAdminMode(), 403);
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $draft = app(GmailProfileDrafts::class)->get($request, $supplier);
        $profile = $this->target($request, $organization, $draft);
        $metadata = $draft['sample'] ?? $profile?->sample_metadata;
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
        $draft = app(GmailProfileDrafts::class)->get($request, $supplier);

        return DB::transaction(function () use ($request, $supplier, $validator, $audit, $draft): RedirectResponse {
            $organization = Organization::where('type', OrganizationType::Wholesaler)->lockForUpdate()->findOrFail($supplier);

            return $this->saveProfile($request, $organization, $validator, $audit, $draft);
        });
    }

    private function saveProfile(UpdateSupplierImportProfileRequest $request, Organization $organization, ProfileValidator $validator, AuditLogger $audit, ?array $draft): RedirectResponse
    {
        if ($draft && ($draft['profile'] ?? null) !== $organization->inbox_import_profile_id) {
            throw ValidationException::withMessages(['sample' => 'Профиль входящей почты изменился. Откройте настройку вложения заново.']);
        }
        $profile = $this->target($request, $organization, $draft);
        $metadata = $draft['sample'] ?? $profile?->sample_metadata;
        if (! $metadata) {
            throw ValidationException::withMessages(['sample' => 'Сначала загрузите образец прайс-листа.']);
        }
        if ($request->has('sample_checksum') && $request->validated('sample_checksum') !== ($metadata['checksum'] ?? null)) {
            throw ValidationException::withMessages(['sample' => 'Образец изменился. Откройте профиль заново.']);
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
        $configuration = $validator->validate($configuration);
        $legacy = ! $request->query->has('profile') && ! $draft;
        $emails = $legacy && $request->has('sender_emails') ? $this->senderEmails($request->validated('sender_emails')) : null;
        if ($draft && $request->validated('file_type') !== $metadata['extension']) {
            throw ValidationException::withMessages(['file_type' => 'Тип файла должен совпадать с образцом.']);
        }
        $fields = ['name', 'file_type', 'configuration', 'attachment_filename_filter', 'is_active', 'inbox_enabled', 'manual_enabled'];
        $before = $profile?->only($fields) ?? [];
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
            $profile->fill([
                'sample_metadata' => $metadata,
                'attachment_filename_filter' => $request->validated('attachment_filename_filter'),
                'name' => $request->validated('name'),
                'file_type' => $request->validated('file_type'),
                'configuration' => $configuration,
                'updated_by' => $request->user()->id,
                'is_active' => $draft || $legacy || $request->boolean('is_active', $profile->is_active ?? false),
                'inbox_enabled' => $draft || $request->boolean('inbox_enabled', $legacy || ($profile->inbox_enabled ?? false)),
                'manual_enabled' => $request->boolean('manual_enabled', $legacy || ($profile->manual_enabled ?? false)),
            ])->save();
            $audit->log('supplier_import_profile.updated', $profile, $before, $profile->only($fields));

            if ($emails !== null) {
                $senderBefore = $organization->senderAddresses()->orderBy('normalized_email')->pluck('normalized_email')->all();
                $this->saveSenderEmails($organization, $emails);
                $audit->log('supplier_import_profile.inbox_settings_updated', $organization, ['sender_emails' => $senderBefore], ['sender_emails' => $emails]);
            }
            if ($draft) {
                $defaultsBefore = $organization->only(['inbox_import_profile_id', 'manual_import_profile_id']);
                $organization->inbox_import_profile_id ??= $profile->id;
                $organization->save();
                $organization->senderAddresses()->firstOrCreate(['normalized_email' => $draft['sender']], ['email' => $draft['sender']]);
                $audit->log('supplier_import_profile.defaults_updated', $organization, $defaultsBefore, $organization->only(array_keys($defaultsBefore)) + ['added_sender' => $draft['sender'] ?? null]);
            }
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
    }
}
