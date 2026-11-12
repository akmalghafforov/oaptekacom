<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\IngestionContext;
use App\DataTransferObjects\StoredImportFile;
use App\Enums\OrganizationType;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Enums\SupplierFileType;
use App\Http\Requests\StorePriceListImportRequest;
use App\Jobs\PreparePriceListImport;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategory;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;
use App\Models\SupplierSyncRun;
use App\Services\AuditLogger;
use App\Services\ImportFileValidator;
use App\Services\ImportProgress;
use App\Services\PriceList\ActivationDispatcher;
use App\Services\PriceList\CategorizationReportExporter;
use App\Services\SupplierPriceListIngestor;
use App\Services\SupplierSourcePolicy;
use App\Services\SupplierSynchronizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PriceListImportController extends Controller
{
    public function index(Request $request, ImportProgress $progress): View|JsonResponse
    {
        $this->authorize('viewAny', PriceListImport::class);
        $tab = $request->query('tab') === 'services' ? 'services' : 'files';
        $sources = $tab === 'services'
            ? [PriceListImportSource::Icorp, PriceListImportSource::PriceListTj]
            : [PriceListImportSource::Manual, PriceListImportSource::Email];
        $query = PriceListImport::query()->whereIn('source_type', $sources)
            ->when(! $request->user()->isAdminMode(), fn ($query) => $query->where('supplier_organization_id', $request->user()->activeOrganizationId()))
            ->select(['id', 'created_at'])->selectRaw("'import' as kind")->toBase();

        if ($tab === 'services') {
            $runs = SupplierSyncRun::query()->where('trigger', '!=', 'mailbox')
                ->whereDoesntHave('attempts', fn ($query) => $query->whereNotNull('price_list_import_id'))
                ->where(function ($query) use ($sources): void {
                    $query->whereIn('configuration_snapshot->selected_source', $sources)
                        ->orWhere(function ($query) use ($sources): void {
                            $query->whereNull('configuration_snapshot->selected_source')
                                ->whereHas('attempts', fn ($query) => $query->whereIn('source', $sources));
                        });
                })
                ->when(! $request->user()->isAdminMode(), fn ($query) => $query->where('supplier_organization_id', $request->user()->activeOrganizationId()))
                ->select(['id', 'created_at'])->selectRaw("'run' as kind");
            $query->unionAll($runs->toBase());
        }

        $history = DB::query()->fromSub($query, 'history')->orderByDesc('created_at')->orderByDesc('id')->orderBy('kind')
            ->paginate(20)->appends(['tab' => $tab]);
        $items = $history->getCollection();
        $imports = PriceListImport::with('supplier')->withCount('rows')->findMany($items->where('kind', 'import')->pluck('id'))->keyBy('id');
        $runs = SupplierSyncRun::with(['supplier', 'attempts'])->findMany($items->where('kind', 'run')->pluck('id'))->keyBy('id');
        $history->setCollection($items->map(function ($item) use ($imports, $runs, $progress, $request): array {
            $entry = $item->kind === 'import' ? $progress->import($imports[$item->id]) : $progress->run($runs[$item->id]);
            if ($item->kind === 'run' && ! $request->user()->isAdminMode()) {
                $entry['url'] = null;
            }

            return $entry;
        }));
        if ($request->expectsJson()) {
            return response()->json(['entries' => $history->items()])->header('Cache-Control', 'private, no-store');
        }

        return view('price-list-imports.index', ['tab' => $tab, 'history' => $history, 'suppliers' => $request->user()->isAdminMode() ? Organization::where('type', OrganizationType::Wholesaler)->where('status', 'active')->with('importProfile')->orderBy('name')->get() : collect()]);
    }

    public function store(StorePriceListImportRequest $request, SupplierPriceListIngestor $ingestor): RedirectResponse
    {
        $supplier = $request->user()->isAdminMode()
            ? Organization::query()->where('type', OrganizationType::Wholesaler)->where('status', 'active')->findOrFail($request->integer('supplier_organization_id'))
            : $request->user()->activeOrganization();
        abort_unless($supplier?->type === OrganizationType::Wholesaler, 404);
        if (! $supplier->importProfile()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['file' => 'Для поставщика не настроен активный профиль импорта.']);
        }
        $upload = $request->file('file');
        app(ImportFileValidator::class)->validate($upload->getRealPath(), $upload->getClientOriginalName(), SupplierFileType::from(strtolower($upload->getClientOriginalExtension())), $supplier->importProfile->configuration);
        $extension = strtolower($upload->getClientOriginalExtension());
        $path = $upload->storeAs('price-list-imports/'.$supplier->id, Str::uuid().'.'.$extension, config('price-list-imports.disk'));
        $stored = new StoredImportFile(config('price-list-imports.disk'), $path, $upload->getClientOriginalName(), $upload->getMimeType() ?: 'application/octet-stream', $upload->getSize(), hash_file('sha256', Storage::disk(config('price-list-imports.disk'))->path($path)));
        $import = $ingestor->ingest($supplier, $stored, new IngestionContext(PriceListImportSource::Manual, $request->user(), receivedAt: CarbonImmutable::now(config('price-list-imports.timezone'))));

        return redirect()->route('price-list-imports.show', $import)->with('success', 'Файл принят и поставлен в очередь автоматической обработки и публикации.');
    }

    public function show(Request $request, int $import): View|JsonResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('view', $importModel);
        $importModel->load(['supplier'])->loadCount('rows');
        $progressEntries = [app(ImportProgress::class)->import($importModel)];
        if ($request->expectsJson()) {
            return response()->json(['entries' => $progressEntries, 'status' => $importModel->status->value, 'label' => $importModel->status->label(), 'counts' => $importModel->only(['total_rows', 'valid_rows', 'error_rows', 'warning_rows', 'skipped_rows'])]);
        }
        $rows = $importModel->rows()->when($request->string('disposition')->isNotEmpty(), fn ($query) => $query->where('disposition', $request->string('disposition')->toString()))->when($request->string('categorization_status')->isNotEmpty(), fn ($query) => $query->where('categorization_status', $request->string('categorization_status')->toString()))->orderBy('source_row')->paginate(50)->withQueryString();

        return view('price-list-imports.show', ['import' => $importModel->load('supplier'), 'progressEntries' => $progressEntries, 'sourceReason' => app(SupplierSourcePolicy::class)->reason($importModel), 'rows' => $rows, 'productCategories' => ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->get()]);
    }

    public function categorizationReport(Request $request, int $import, CategorizationReportExporter $exporter): StreamedResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('view', $importModel);

        return $exporter->export($importModel);
    }

    public function download(Request $request, int $import): StreamedResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('view', $importModel);

        return Storage::disk(config('price-list-imports.disk'))->download($importModel->file_path, $importModel->original_filename);
    }

    public function retry(Request $request, int $import, ActivationDispatcher $activationDispatcher): RedirectResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('retry', $importModel);
        abort_unless($importModel->status === PriceListImportStatus::Failed, 422);
        if (in_array($importModel->source_type->value, ['icorp', 'pricelist_tj'], true)) {
            app(SupplierSynchronizationService::class)->request($importModel->supplier);

            return back()->with('success', 'Новая загрузка выбранного источника поставлена в очередь.');
        }
        $activationFailure = in_array($importModel->failure_stage, ['activation', 'activation_materialization', 'activation_cutover'], true);
        $importModel->update([
            'status' => $activationFailure ? PriceListImportStatus::Preview : PriceListImportStatus::Pending,
            'failed_at' => null, 'failure_stage' => null, 'failure_message' => null,
        ]);
        if ($activationFailure) {
            $activationDispatcher->dispatch($importModel->fresh(), $request->user()->id);
        } else {
            $importModel->rows()->delete();
            PreparePriceListImport::dispatch($importModel->fresh())->onQueue(config('price-list-imports.queue'))->afterCommit();
        }

        return back()->with('success', 'Повторная обработка запущена.');
    }

    public function replay(Request $request, int $import, SupplierPriceListIngestor $ingestor): RedirectResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('replay', $importModel);
        abort_if(in_array($importModel->source_type->value, ['icorp', 'pricelist_tj'], true), 422);
        abort_unless(in_array($importModel->status, [PriceListImportStatus::Completed, PriceListImportStatus::Superseded], true), 422);
        if (! $importModel->supplier->importProfile()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['file' => 'Для поставщика не настроен активный профиль импорта.']);
        }
        $disk = Storage::disk(config('price-list-imports.disk'));
        if (! $disk->exists($importModel->file_path)) {
            throw ValidationException::withMessages(['file' => 'Исходный файл импорта недоступен. Загрузите файл заново.']);
        }
        $extension = strtolower(pathinfo($importModel->original_filename, PATHINFO_EXTENSION));
        $path = 'price-list-imports/'.$importModel->supplier_organization_id.'/'.Str::uuid().'.'.$extension;
        abort_unless($disk->copy($importModel->file_path, $path), 500);
        $stored = new StoredImportFile(config('price-list-imports.disk'), $path, $importModel->original_filename, $importModel->mime_type, $disk->size($path), $importModel->sha256);
        $replay = $ingestor->ingest($importModel->supplier, $stored, new IngestionContext(PriceListImportSource::Manual, $request->user(), receivedAt: CarbonImmutable::now(config('price-list-imports.timezone'))));

        return redirect()->route('price-list-imports.show', $replay)->with('success', 'Повторная обработка исходного файла запущена с текущим профилем поставщика. Результаты предыдущего импорта сохранены в истории.');
    }

    public function override(Request $request, int $import, int $row, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isAdminMode(), 403);
        $importModel = PriceListImport::findOrFail($import);
        abort_unless($importModel->status === PriceListImportStatus::Preview, 422);
        $importRow = PriceListImportRow::query()->whereBelongsTo($importModel, 'import')->findOrFail($row);
        $validated = $request->validate([
            'medicine_id' => ['nullable', 'integer', 'exists:medicines,id', 'required_without:categories'],
            'categories' => ['nullable', 'array', 'min:1', 'distinct', 'required_without:medicine_id'],
            'categories.*' => ['string', Rule::exists('product_categories', 'code')->where('is_active', true)],
        ]);
        $before = $importRow->only(['medicine_id', 'supplier_product_id', 'disposition', 'assigned_category', 'categorization_status']);
        if (! empty($validated['categories'])) {
            $categories = ProductCategory::query()->whereIn('code', $validated['categories'])->orderBy('sort_order')->get();
            $codes = $categories->pluck('code')->all();
            $candidates = $categories->map(fn (ProductCategory $category): array => ['code' => $category->code, 'label' => $category->label, 'confidence' => 100, 'decision' => 'accepted', 'source' => 'manual'])->all();
            $equivalentRows = $importModel->rows()->where('normalized_product_name', $importRow->normalized_product_name);
            $equivalentRows->update(['assigned_category' => $categories->first()->label, 'assigned_categories' => json_encode($codes), 'category_candidates' => json_encode($candidates, JSON_UNESCAPED_UNICODE), 'categorization_status' => 'manual_locked', 'categorization_confidence' => 100, 'categorization_evidence' => json_encode(['manual_override_by' => $request->user()->id], JSON_UNESCAPED_UNICODE)]);
            if ($importRow->medicine !== null) {
                $medicine = $importRow->medicine;
                $medicine->update(['category' => $categories->first()->label, 'category_status' => 'manual', 'category_confidence' => 100, 'category_assigned_at' => now(), 'categories_locked_at' => now(), 'categories_locked_by' => $request->user()->id]);
                $medicine->categories()->sync($categories->mapWithKeys(fn (ProductCategory $category): array => [$category->id => ['source' => 'manual', 'confidence' => 100, 'evidence' => json_encode(['row_id' => $importRow->id]), 'assigned_by' => $request->user()->id]])->all());
            }
            $audit->log('price_list_import.category_overridden', $importRow, $before, $importRow->only(['assigned_category', 'categorization_status']));

            return back()->with('success', 'Набор категорий товара сохранён и заблокирован для автоматических изменений.');
        }

        $medicine = Medicine::query()->where('supplier_organization_id', $importModel->supplier_organization_id)->findOrFail($validated['medicine_id']);
        $values = $importRow->parsed_values;
        $supplierProduct = SupplierProduct::firstOrCreate(
            ['supplier_organization_id' => $importModel->supplier_organization_id, 'normalized_name' => $values['normalized_name']],
            ['medicine_id' => $medicine->id, 'supplier_sku' => $values['sku'] ?? null, 'normalized_sku' => $values['normalized_sku'] ?? null, 'original_name' => $values['name'], 'match_key' => $values['normalized_name']],
        );
        abort_if($supplierProduct->medicine_id !== $medicine->id, 422, 'Этот товар поставщика уже сопоставлен с другим лекарством.');
        SupplierProductAlias::updateOrCreate(['supplier_organization_id' => $importModel->supplier_organization_id, 'normalized_name' => $values['normalized_name']], ['supplier_product_id' => $supplierProduct->id, 'created_by' => $request->user()->id]);
        $importRow->update(['medicine_id' => $medicine->id, 'supplier_product_id' => $supplierProduct->id, 'planned_action' => PriceListRowAction::Match, 'disposition' => $importRow->warnings ? PriceListRowDisposition::Warning : PriceListRowDisposition::Valid, 'errors' => []]);
        $importModel->update([
            'valid_rows' => $importModel->rows()->whereIn('disposition', ['valid', 'warning'])->count(),
            'error_rows' => $importModel->rows()->where('disposition', 'error')->count(),
            'warning_rows' => $importModel->rows()->where('disposition', 'warning')->count(),
        ]);
        $audit->log('price_list_import.match_overridden', $importRow, $before, $importRow->only(['medicine_id', 'supplier_product_id', 'disposition']));

        return back()->with('success', 'Сопоставление сохранено и будет использовано в следующих импортах.');
    }

    private function scoped(Request $request, int $id): PriceListImport
    {
        return PriceListImport::query()->when(! $request->user()->isAdminMode(), fn ($query) => $query->where('supplier_organization_id', $request->user()->activeOrganizationId()))->findOrFail($id);
    }
}
