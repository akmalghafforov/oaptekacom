<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\IngestionContext;
use App\DataTransferObjects\StoredImportFile;
use App\Enums\OrganizationType;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Http\Requests\StorePriceListImportRequest;
use App\Jobs\PreparePriceListImport;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;
use App\Services\AuditLogger;
use App\Services\PriceList\ImportActivator;
use App\Services\SupplierPriceListIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PriceListImportController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', PriceListImport::class);
        $query = PriceListImport::query()->with('supplier')->latest();
        if (! $request->user()->isAdmin()) {
            $query->where('supplier_organization_id', $request->user()->organization_id);
        }

        return view('price-list-imports.index', ['imports' => $query->paginate(20), 'suppliers' => $request->user()->isAdmin() ? Organization::where('type', OrganizationType::Wholesaler)->where('status', 'active')->orderBy('name')->get() : collect()]);
    }

    public function store(StorePriceListImportRequest $request, SupplierPriceListIngestor $ingestor): RedirectResponse
    {
        $supplier = $request->user()->isAdmin()
            ? Organization::query()->where('type', OrganizationType::Wholesaler)->where('status', 'active')->findOrFail($request->integer('supplier_organization_id'))
            : $request->user()->organization;
        abort_unless($supplier?->type === OrganizationType::Wholesaler, 404);
        if (! $supplier->importProfile()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['file' => 'Для поставщика не настроен активный профиль импорта.']);
        }
        $upload = $request->file('file');
        $extension = strtolower($upload->getClientOriginalExtension());
        $path = $upload->storeAs('price-list-imports/'.$supplier->id, Str::uuid().'.'.$extension, config('price-list-imports.disk'));
        $stored = new StoredImportFile(config('price-list-imports.disk'), $path, $upload->getClientOriginalName(), $upload->getMimeType() ?: 'application/octet-stream', $upload->getSize(), hash_file('sha256', Storage::disk(config('price-list-imports.disk'))->path($path)));
        $import = $ingestor->ingest($supplier, $stored, new IngestionContext(PriceListImportSource::Manual, $request->user(), receivedAt: CarbonImmutable::now(config('price-list-imports.timezone'))));

        return redirect()->route('price-list-imports.show', $import)->with('success', 'Файл принят и поставлен в очередь.');
    }

    public function show(Request $request, int $import): View|JsonResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('view', $importModel);
        if ($request->expectsJson()) {
            return response()->json(['status' => $importModel->status->value, 'label' => $importModel->status->label(), 'counts' => $importModel->only(['total_rows', 'valid_rows', 'error_rows', 'warning_rows', 'skipped_rows'])]);
        }
        $rows = $importModel->rows()->when($request->string('disposition')->isNotEmpty(), fn ($query) => $query->where('disposition', $request->string('disposition')->toString()))->orderBy('source_row')->paginate(50)->withQueryString();

        return view('price-list-imports.show', ['import' => $importModel->load('supplier'), 'rows' => $rows]);
    }

    public function download(Request $request, int $import): StreamedResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('view', $importModel);

        return Storage::disk(config('price-list-imports.disk'))->download($importModel->file_path, $importModel->original_filename);
    }

    public function retry(Request $request, int $import): RedirectResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('retry', $importModel);
        abort_unless($importModel->status === PriceListImportStatus::Failed, 422);
        $importModel->rows()->delete();
        $importModel->update(['status' => PriceListImportStatus::Pending, 'failed_at' => null, 'failure_message' => null]);
        PreparePriceListImport::dispatch($importModel)->onQueue(config('price-list-imports.queue'))->afterCommit();

        return back()->with('success', 'Повторная обработка запущена.');
    }

    public function commit(Request $request, int $import, ImportActivator $activator): RedirectResponse
    {
        $importModel = $this->scoped($request, $import);
        $this->authorize('commit', $importModel);
        $activator->activate($importModel, $request->user());

        return back()->with('success', 'Прайс-лист активирован.');
    }

    public function override(Request $request, int $import, int $row, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $importModel = PriceListImport::findOrFail($import);
        $importRow = PriceListImportRow::query()->whereBelongsTo($importModel, 'import')->findOrFail($row);
        $medicine = Medicine::findOrFail($request->validate(['medicine_id' => ['required', 'integer', 'exists:medicines,id']])['medicine_id']);
        $values = $importRow->parsed_values;
        $matchKey = $values['normalized_sku'] ?? $values['normalized_name'];
        $supplierProduct = SupplierProduct::firstOrCreate(
            ['supplier_organization_id' => $importModel->supplier_organization_id, 'match_key' => $matchKey],
            ['medicine_id' => $medicine->id, 'supplier_sku' => $values['sku'] ?? null, 'normalized_sku' => $values['normalized_sku'] ?? null, 'original_name' => $values['name'], 'normalized_name' => $values['normalized_name']],
        );
        abort_if($supplierProduct->medicine_id !== $medicine->id, 422, 'Этот товар поставщика уже сопоставлен с другим лекарством.');
        SupplierProductAlias::updateOrCreate(['supplier_organization_id' => $importModel->supplier_organization_id, 'normalized_name' => $values['normalized_name']], ['supplier_product_id' => $supplierProduct->id, 'created_by' => $request->user()->id]);
        $before = $importRow->only(['medicine_id', 'supplier_product_id', 'disposition']);
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
        return PriceListImport::query()->when(! $request->user()->isAdmin(), fn ($query) => $query->where('supplier_organization_id', $request->user()->organization_id))->findOrFail($id);
    }
}
