<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierImportProfileSampleRequest;
use App\Http\Requests\UpdateSupplierImportProfileRequest;
use App\Models\Organization;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Services\AuditLogger;
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
    public function edit(int $supplier, SampleWorkbookReader $reader): View
    {
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $profile = $organization->importProfile ?? new SupplierImportProfile(['name' => 'Основной профиль', 'file_type' => 'xlsx', 'configuration' => ProfileValidator::defaults()]);
        $senderEmails = $organization->senderAddresses()->orderBy('normalized_email')->pluck('email')->implode("\n");
        $preview = null;
        if ($profile->sample_metadata) {
            try {
                $preview = $reader->preview(
                    $profile->sample_metadata['path'],
                    $profile->sample_metadata['extension'],
                    old('worksheet', $profile->configuration['worksheet'] ?? null),
                );
            } catch (Throwable) {
                session()->flash('warning', 'Сохранённый образец не удалось прочитать. Загрузите его заново.');
            }
        }

        return view('admin.supplier-import-profiles.edit', compact('organization', 'profile', 'senderEmails', 'preview'));
    }

    public function storeSample(StoreSupplierImportProfileSampleRequest $request, int $supplier, SampleWorkbookReader $reader): RedirectResponse
    {
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
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

        return back()->with('success', 'Образец сохранён. Теперь сопоставьте столбцы.');
    }

    public function samplePreview(Request $request, int $supplier, SampleWorkbookReader $reader): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $metadata = $organization->importProfile?->sample_metadata;
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
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $profile = $organization->importProfile;
        $metadata = $profile?->sample_metadata;
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
        $configuration = $validator->validate($configuration);
        $emails = collect(preg_split('/\R/u', (string) $request->validated('sender_emails')))->map(fn (string $email): string => mb_strtolower(trim($email)))->filter()->unique()->values();
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || SupplierSenderAddress::query()->where('normalized_email', $email)->where('supplier_organization_id', '!=', $organization->id)->exists()) {
                throw ValidationException::withMessages(['sender_emails' => 'Адрес '.$email.' неверен или уже назначен другому поставщику.']);
            }
        }
        $before = $profile?->only(['name', 'file_type', 'configuration']) ?? [];
        DB::transaction(function () use ($organization, $profile, $configuration, $request, $emails): void {
            $profile->update(['name' => $request->validated('name'), 'file_type' => $request->validated('file_type'), 'configuration' => $configuration, 'updated_by' => $request->user()->id, 'is_active' => true]);
            $organization->senderAddresses()->whereNotIn('normalized_email', $emails)->delete();
            foreach ($emails as $email) {
                SupplierSenderAddress::updateOrCreate(['normalized_email' => $email], ['email' => $email, 'supplier_organization_id' => $organization->id]);
            }
        });
        $audit->log('supplier_import_profile.updated', $profile, $before, $profile->only(['name', 'file_type', 'configuration']));

        return back()->with('success', 'Профиль импорта сохранён.');
    }
}
