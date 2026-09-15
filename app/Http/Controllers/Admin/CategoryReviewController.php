<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PriceListImportRow;
use App\Models\ProductCategory;
use App\Services\AuditLogger;
use App\Services\PriceList\ProductCategoryClassifier;
use App\Services\PriceList\ProductCategoryRuleSetResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryReviewController extends Controller
{
    public function index(Request $request): View
    {
        $rows = PriceListImportRow::query()->with('import.supplier')->whereIn('categorization_status', ['review_required', 'unmatched'])
            ->when($request->filled('supplier'), fn ($query) => $query->whereHas('import', fn ($import) => $import->where('supplier_organization_id', $request->integer('supplier'))))
            ->when($request->filled('status'), fn ($query) => $query->where('categorization_status', $request->string('status')->toString()))
            ->when($request->filled('category'), fn ($query) => $query->whereJsonContains('category_candidates', ['code' => $request->string('category')->toString()]))
            ->when($request->filled('age'), fn ($query) => $query->where('created_at', '<=', now()->subDays($request->integer('age'))))
            ->latest()->paginate(50)->withQueryString();

        return view('admin.category-reviews.index', ['rows' => $rows, 'categories' => ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->get()]);
    }

    public function unlock(Medicine $medicine, ProductCategoryClassifier $classifier, ProductCategoryRuleSetResolver $resolver, AuditLogger $audit): RedirectResponse
    {
        $before = ['categories' => $medicine->categories()->pluck('code')->all(), 'locked_at' => $medicine->categories_locked_at];
        $ruleSet = $resolver->current();
        $result = $classifier->classify($medicine->name, $ruleSet, ['form' => $medicine->form, 'dosage' => $medicine->dosage, 'unit' => $medicine->unit_of_measure]);
        $categories = ProductCategory::query()->whereIn('code', array_column($result['assignments'], 'code'))->get();
        $candidates = collect($result['assignments'])->keyBy('code');
        $medicine->categories()->sync($categories->mapWithKeys(fn (ProductCategory $category): array => [$category->id => ['source' => 'automatic', 'confidence' => $candidates[$category->code]['confidence'], 'rule_set_id' => $ruleSet->id, 'rule_set_checksum' => $ruleSet->checksum, 'evidence' => json_encode($candidates[$category->code], JSON_UNESCAPED_UNICODE)]])->all());
        $medicine->update(['category' => $categories->sortBy('sort_order')->first()?->label ?? 'Не распознано', 'category_status' => $result['status'], 'category_confidence' => $result['confidence'], 'category_evidence' => $result['evidence'], 'category_rule_set_id' => $ruleSet->id, 'category_rule_set_checksum' => $ruleSet->checksum, 'category_assigned_at' => now(), 'categories_locked_at' => null, 'categories_locked_by' => null]);
        $audit->log('medicine.categories_unlocked', $medicine, $before, ['categories' => $categories->pluck('code')->all(), 'status' => $result['status']]);

        return back()->with('success', 'Категории разблокированы и рассчитаны заново.');
    }
}
