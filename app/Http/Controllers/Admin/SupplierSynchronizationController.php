<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Enums\PriceListImportStatus;
use App\Enums\TradeMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveSupplierMappingRequest;
use App\Http\Requests\UpdateExternalSupplierProfileRequest;
use App\Http\Requests\UpdateSupplierSynchronizationRequest;
use App\Jobs\DownloadExternalPricePage;
use App\Jobs\RefreshExternalSupplierDirectory;
use App\Models\ExternalAvailability;
use App\Models\ExternalDownload;
use App\Models\ExternalServiceIncident;
use App\Models\ExternalServiceState;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Services\AuditLogger;
use App\Services\ExternalServiceControl;
use App\Services\ExternalServiceUnavailable;
use App\Services\ExternalSupplierDirectory;
use App\Services\IcorpClient;
use App\Services\IcorpCredentials;
use App\Services\IcorpFailure;
use App\Services\NewestSupplierCandidates;
use App\Services\PriceList\ActivationDispatcher;
use App\Services\PriceList\CatalogFingerprint;
use App\Services\PriceList\SampleWorkbookReader;
use App\Services\PriceListTjClient;
use App\Services\SupplierAutomaticSetup;
use App\Services\SupplierDirectoryContext;
use App\Services\SupplierImportSettings;
use App\Services\SupplierInboxEvidence;
use App\Services\SupplierMappingApproval;
use App\Services\SupplierSourcePolicy;
use App\Services\SupplierSuggestionMatcher;
use App\Services\SupplierSynchronizationService;
use App\Services\SupplierSynchronizationStatus;
use App\Services\SupplierVersions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SupplierSynchronizationController extends Controller
{
    public function index(Request $request, SupplierSuggestionMatcher $matcher, SupplierMappingApproval $approval): View
    {
        $request->validate(SupplierDirectoryContext::fields(), $this->validationMessages());
        $tab = (str_starts_with((string) old('_sync_form'), 'mapping-') || str_starts_with((string) old('_sync_form'), 'unmap-') || str_starts_with((string) old('_sync_form'), 'approve-')) ? 'external' : ($request->input('tab') ?: ($request->hasAny(['q', 'service', 'filter', 'edit']) ? 'external' : 'suppliers'));
        $directoryCounts = ExternalSupplierPartner::query()->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source');
        $allSuppliers = Organization::where('type', OrganizationType::Wholesaler)->orderBy('name')->get();
        $allPartners = ExternalSupplierPartner::with('supplier')->orderBy('name')->orderBy('id')->get();
        $reviewSuggestions = $matcher->directory($allPartners, $allSuppliers);
        $suggestions = $allPartners->mapWithKeys(fn (ExternalSupplierPartner $partner): array => [$partner->id => $reviewSuggestions[$partner->id]['internal']]);
        $partners = $this->filterDirectory($allPartners, $request->all(), $reviewSuggestions);
        $restoredReviewId = preg_match('/^(?:approve|mapping)-(\d+)$/D', (string) old('_sync_form'), $reviewMatch) ? (int) $reviewMatch[1] : null;
        $restoredReview = $allPartners->firstWhere('id', $restoredReviewId);
        $mappedSupplierIds = $partners->pluck('supplier_organization_id')->push($restoredReview?->supplier_organization_id)->filter()->unique();
        $mappedPartners = ExternalSupplierPartner::whereIn('supplier_organization_id', $mappedSupplierIds)->orderBy('source')->get()->groupBy('supplier_organization_id');
        $configurations = SupplierSynchronization::all()->keyBy('supplier_organization_id');
        $partnerEvidence = $allPartners->mapWithKeys(fn (ExternalSupplierPartner $record): array => [$record->id => $approval->fingerprint($record)]);
        $supplierEvidence = $allSuppliers->mapWithKeys(fn (Organization $record): array => [$record->id => $approval->fingerprint($record, (int) ($configurations->get($record->id)?->version ?? 0))]);
        $partners = $partners->unique(fn (ExternalSupplierPartner $partner): string => $partner->supplier_organization_id ? 'supplier-'.$partner->supplier_organization_id : 'partner-'.$partner->id)->values();
        $page = min(max(1, $request->integer('page', 1)), max(1, (int) ceil($partners->count() / 25)));
        $partners = new LengthAwarePaginator($partners->forPage($page, 25)->values(), $partners->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);
        if ($restoredReview) {
            $visible = $partners->getCollection();
            $sameRow = fn (ExternalSupplierPartner $record): bool => $record->id === $restoredReview->id
                || ($restoredReview->supplier_organization_id && $record->supplier_organization_id === $restoredReview->supplier_organization_id);
            $partners->setCollection($visible->contains($sameRow)
                ? $visible->map(fn (ExternalSupplierPartner $record): ExternalSupplierPartner => $sameRow($record) ? $restoredReview : $record)
                : $visible->prepend($restoredReview));
        }
        $supplierStatuses = app(SupplierSynchronizationStatus::class)->forSuppliers($allSuppliers, $configurations);
        $suppliers = Organization::where('type', OrganizationType::Wholesaler)
            ->when($request->filled('supplier_q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('supplier_q').'%'))
            ->when($request->filled('supplier_status'), fn ($query) => $query->whereIn('id', $supplierStatuses->filter(fn (array $status): bool => $request->input('supplier_status') === 'attention' ? $status['attention'] : $status['state'] === $request->input('supplier_status'))->keys()))
            ->with('activePriceListImport')->orderBy('name')->orderBy('id')->paginate(25, ['*'], 'supplier_page')->withQueryString();
        $listingDates = ExternalAvailability::orderByDesc('last_checked_at')->orderByDesc('id')->get()->unique('external_supplier_partner_id')->keyBy('external_supplier_partner_id');
        $statuses = collect(['icorp', 'pricelist_tj'])->mapWithKeys(fn (string $source): array => [$source => Cache::get('external-prices:status:'.$source, ['status' => 'unknown'])]);

        try {
            $icorpLogin = app(IcorpCredentials::class)->current()['phone'];
        } catch (IcorpFailure) {
            $icorpLogin = '';
        }

        return view('admin.supplier-synchronizations.index', compact('listingDates', 'supplierStatuses', 'icorpLogin', 'tab', 'partners', 'suppliers', 'allSuppliers', 'suggestions', 'statuses', 'directoryCounts', 'mappedPartners', 'configurations', 'reviewSuggestions', 'allPartners', 'partnerEvidence', 'supplierEvidence', 'restoredReview'));
    }

    public function approve(ApproveSupplierMappingRequest $request, SupplierMappingApproval $approval): RedirectResponse
    {
        try {
            $supplier = $approval->approve($request->validated());
        } catch (ValidationException $exception) {
            if (($context = SupplierDirectoryContext::valid($request)) !== null) {
                $exception->redirectTo(route('admin.supplier-sync.index', $context));
            }
            throw $exception;
        }

        return $this->mappingResponse($request, $supplier, 'Выбранные сопоставления подтверждены.');
    }

    /**
     * @param  Collection<int, ExternalSupplierPartner>  $partners
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $suggestions
     * @return Collection<int, ExternalSupplierPartner>
     */
    private function filterDirectory(Collection $partners, array $context, array $suggestions): Collection
    {
        return $partners->filter(function (ExternalSupplierPartner $partner) use ($context, $suggestions): bool {
            if (isset($context['q']) && $context['q'] !== '' && ! str_contains(mb_strtolower($partner->name), mb_strtolower($context['q']))) {
                return false;
            }
            if (! empty($context['service']) && $partner->source !== $context['service']) {
                return false;
            }

            return match ($context['filter'] ?? null) {
                'unmapped' => ! $partner->supplier_organization_id,
                'confirmed' => (bool) $partner->supplier_organization_id,
                'suggested' => $suggestions[$partner->id]['has'],
                'ambiguous' => $suggestions[$partner->id]['ambiguous'],
                default => true,
            };
        });
    }

    private function mappingResponse(Request $request, Organization $supplier, string $message): RedirectResponse
    {
        $context = SupplierDirectoryContext::valid($request);
        if ($context === null) {
            return redirect()->route('admin.supplier-import-profiles.edit', $supplier)->with('success', $message);
        }
        $partners = ExternalSupplierPartner::orderBy('name')->orderBy('id')->get();
        $suggestions = app(SupplierSuggestionMatcher::class)->directory($partners, Organization::where('type', OrganizationType::Wholesaler)->get());
        $filtered = $this->filterDirectory($partners, $context, $suggestions);
        $removed = ! $filtered->contains('supplier_organization_id', $supplier->id);
        $total = $filtered->unique(fn (ExternalSupplierPartner $partner): string => $partner->supplier_organization_id ? 'supplier-'.$partner->supplier_organization_id : 'partner-'.$partner->id)->count();
        if (isset($context['page'])) {
            $context['page'] = min((int) $context['page'], max(1, (int) ceil($total / 25)));
        }

        return redirect()->route('admin.supplier-sync.index', $context)
            ->with('mapping_success', ['name' => $supplier->name, 'id' => $supplier->id, 'removed' => $removed]);
    }

    public function refresh(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', 'in:icorp,pricelist_tj']], $this->validationMessages());
        $audit->log('supplier_directory.refresh_requested', $request->user(), [], $data);
        try {
            RefreshExternalSupplierDirectory::dispatch($data['source'])->onQueue(config('price-list-imports.queue'));
        } catch (Throwable $exception) {
            return back()->with('warning', 'Справочник не обновлён: '.$this->connectionFailureLabel(ExternalSupplierDirectory::failureReason($exception)));
        }

        return back()->with('success', 'Обновление справочника поставлено в очередь.');
    }

    public function map(Request $request, ExternalSupplierPartner $partner, AuditLogger $audit): RedirectResponse
    {
        try {
            $mode = $request->input('mapping_mode');
            $data = $request->validate([
                ...SupplierDirectoryContext::rules(),
                'mapping_mode' => ['sometimes', 'required', 'in:existing,new'],
                'supplier_organization_id' => [Rule::excludeIf($mode === 'new'), Rule::requiredIf($mode === 'existing'), 'nullable', 'integer', Rule::exists('organizations', 'id')->where('type', OrganizationType::Wholesaler->value)],
                'name' => [Rule::excludeIf($mode === 'existing'), Rule::requiredIf($mode === 'new'), 'required_without:supplier_organization_id', 'nullable', 'string', 'max:255'],
                'city' => [Rule::excludeIf($mode === 'existing'), 'nullable', 'string', 'max:255'], 'phone' => [Rule::excludeIf($mode === 'existing'), 'nullable', 'string', 'max:32', 'unique:organizations,phone'],
                'confirmed' => ['required', 'accepted'],
            ], $this->validationMessages());
            $supplier = DB::transaction(function () use ($partner, $data, $audit): Organization {
                $supplierId = $data['supplier_organization_id'] ?? null;
                Organization::whereIn('id', array_filter([$supplierId, $partner->supplier_organization_id]))->orderBy('id')->lockForUpdate()->get();
                $expectedSupplierId = $partner->supplier_organization_id;
                $partner = ExternalSupplierPartner::whereKey($partner->id)->lockForUpdate()->firstOrFail();
                if ($partner->supplier_organization_id !== $expectedSupplierId) {
                    throw ValidationException::withMessages(['supplier_organization_id' => 'Сопоставление уже изменилось. Обновите страницу и проверьте поставщика.']);
                }
                $supplier = $supplierId ? Organization::findOrFail($supplierId) : Organization::create([
                    'name' => $data['name'], 'city' => $data['city'] ?? null, 'phone' => $data['phone'] ?? null,
                    'type' => OrganizationType::Wholesaler, 'status' => 'active', 'supplier_mode' => TradeMode::Supplier->value,
                ]);
                if (! $supplierId) {
                    $audit->log('organization.supplier_created', $supplier, [], $supplier->only(['name', 'city', 'phone', 'type', 'status']));
                }
                if (ExternalSupplierPartner::where('source', $partner->source)->where('supplier_organization_id', $supplier->id)->whereKeyNot($partner->id)->exists()) {
                    throw ValidationException::withMessages(['supplier_organization_id' => 'У поставщика уже подтверждено сопоставление с этим сервисом.']);
                }
                $before = $partner->only('supplier_organization_id');
                $partner->update(['supplier_organization_id' => $supplier->id, 'profile_version' => $partner->profile_version + 1]);
                $configurationDefaults = ['sources' => SupplierSynchronization::defaultSources(), 'source_conversion' => ['new_configuration' => true, 'reason' => 'selection_required']];
                if (! $supplierId) {
                    $configurationDefaults = ['selection_mode' => 'newest_available', 'fetch_interval_minutes' => 60, 'freshness_hours' => 24,
                        'sources' => collect(SupplierSynchronization::defaultSources())->map(fn (array $settings): array => array_replace($settings, ['enabled' => false]))->all()];
                }
                SupplierSynchronization::firstOrCreate(['supplier_organization_id' => $supplier->id], $configurationDefaults);
                SupplierSynchronization::whereIn('supplier_organization_id', array_filter([$before['supplier_organization_id'], $supplier->id]))->increment('version');
                $audit->log($before['supplier_organization_id'] ? 'supplier_mapping.remapped' : 'supplier_mapping.confirmed', $partner, $before, $partner->only('supplier_organization_id'));

                return $supplier;
            }, 3);
        } catch (ValidationException $exception) {
            if (($context = SupplierDirectoryContext::valid($request)) !== null) {
                $exception->redirectTo(route('admin.supplier-sync.index', $context));
            }
            throw $exception;
        }

        return $this->mappingResponse($request, $supplier, 'Сопоставление подтверждено. Автоматическую загрузку можно включить после настройки профилей.');
    }

    public function workspaceMapping(Request $request, Organization $supplier): RedirectResponse
    {
        abort_unless($supplier->type === OrganizationType::Wholesaler, 404);
        $data = $request->validate(['partner_id' => ['required', 'integer', 'exists:external_supplier_partners,id'], 'confirmed' => ['required', 'accepted']], $this->validationMessages());
        $partner = ExternalSupplierPartner::findOrFail($data['partner_id']);
        abort_if($partner->supplier_organization_id && $partner->supplier_organization_id !== $supplier->id, 409);
        $request->merge(['mapping_mode' => 'existing', 'supplier_organization_id' => $supplier->id]);
        $this->map($request, $partner, app(AuditLogger::class));

        return redirect()->route('admin.supplier-sync.show', [$supplier, 'tab' => 'sources'])->with('success', 'Сопоставление подтверждено. Настройте профиль и отдельно разрешите использование источника.');
    }

    public function unmap(Request $request, ExternalSupplierPartner $partner, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'expected_supplier_organization_id' => ['required', 'integer', 'min:1'],
        ], $this->validationMessages());
        DB::transaction(function () use ($partner, $data, $audit): void {
            Organization::whereKey($data['expected_supplier_organization_id'])->lockForUpdate()->firstOrFail();
            $partner = ExternalSupplierPartner::whereKey($partner->id)->lockForUpdate()->firstOrFail();
            if ($partner->supplier_organization_id !== (int) $data['expected_supplier_organization_id']) {
                throw ValidationException::withMessages(['confirmed' => 'Сопоставление уже изменилось. Обновите страницу и проверьте поставщика.']);
            }
            $before = $partner->only('supplier_organization_id');
            $partner->update(['supplier_organization_id' => null, 'profile_version' => $partner->profile_version + 1]);
            SupplierSynchronization::where('supplier_organization_id', $before['supplier_organization_id'])->increment('version');
            $audit->log('supplier_mapping.removed', $partner, $before, $partner->only('supplier_organization_id'));
        }, 3);

        return back()->with('success', 'Сопоставление удалено. Поставщик, справочник и активный каталог сохранены.');
    }

    public function show(Request $request, Organization $supplier, SupplierSourcePolicy $policy, SupplierSynchronizationStatus $status): View|JsonResponse
    {
        abort_unless($supplier->type === OrganizationType::Wholesaler, 404);
        $request->validate(['tab' => ['nullable', 'in:overview,sources,review,history'], 'page' => ['nullable', 'integer', 'min:1']], $this->validationMessages());
        $tab = ($request->input('tab') ?: 'overview');
        $supplier->load(['importProfile', 'activePriceListImport']);
        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->first()
            ?? new SupplierSynchronization(['automatic_enabled' => false, 'paused' => false, 'selection_mode' => 'newest_available', 'fetch_interval_minutes' => 60, 'freshness_hours' => 24, 'sources' => collect(SupplierSynchronization::defaultSources())->map(fn (array $settings): array => array_replace($settings, ['enabled' => false]))->all()]);
        $supplierStatus = $status->forSupplier($supplier, $configuration);
        if ($request->expectsJson()) {
            return response()->json($supplierStatus);
        }
        $partners = ExternalSupplierPartner::where('supplier_organization_id', $supplier->id)->orderBy('source')->get();
        $previews = PriceListImport::where('supplier_organization_id', $supplier->id)->where('status', PriceListImportStatus::Preview)->latest('id')->get();
        $submittedForm = (string) old('_sync_form');
        if ($previews->contains(fn (PriceListImport $preview): bool => $submittedForm === 'date-'.$preview->id)) {
            $tab = 'review';
        }
        $runs = SupplierSyncRun::where('supplier_organization_id', $supplier->id)->with('attempts.import')->latest('id')->paginate(20, ['*'], 'page', $tab === 'history' ? max(1, $request->integer('page', 1)) : 1)->withQueryString();

        $reviewReasons = $previews->mapWithKeys(fn (PriceListImport $preview): array => [$preview->id => $policy->reason($preview) ?? 'review_pending']);
        $sources = collect(SupplierSynchronization::defaultSources())->map(fn (array $settings, string $source): array => array_replace($settings, is_array($configuration->sources[$source] ?? null) ? $configuration->sources[$source] : []))->sortBy('priority');

        $serviceStates = ExternalServiceState::whereIn('source', ['email', ...$partners->pluck('source')->all()])->get();
        $downloads = ExternalDownload::where('supplier_organization_id', $supplier->id)->latest('id')->limit(20)->get();
        $observations = ExternalAvailability::whereIn('external_supplier_partner_id', $partners->pluck('id'))->orderByDesc('last_checked_at')->orderByDesc('id')->get()->unique('external_supplier_partner_id');
        $incidents = ExternalServiceIncident::whereIn('source', $serviceStates->pluck('source'))->whereNull('resolved_at')->get();

        $activeDateNeedsReview = ! app(SupplierVersions::class)->enabled() && $configuration->usesNewestAvailable() && $supplier->activePriceListImport
            && app(NewestSupplierCandidates::class)->publicationReason($supplier->activePriceListImport) !== null;
        $conflictReviews = ! app(SupplierVersions::class)->enabled() && $configuration->reviewsConflicts() && $tab === 'review' ? $previews->mapWithKeys(fn (PriceListImport $preview): array => [$preview->id => [
            'token' => app(NewestSupplierCandidates::class)->comparisonToken($preview, $configuration),
            'others' => app(NewestSupplierCandidates::class)->reviewComparisons($preview, $configuration),
            'differences' => collect(app(NewestSupplierCandidates::class)->reviewComparisons($preview, $configuration))->mapWithKeys(fn (PriceListImport $other): array => [$other->id => app(CatalogFingerprint::class)->difference($preview, $other)]),
        ]]) : collect();
        $automaticSetup = app(SupplierAutomaticSetup::class)->status($supplier);
        $settingsData = $tab === 'sources' ? app(SupplierImportProfileController::class)->workspaceData($request, $supplier->id, app(SampleWorkbookReader::class)) : [];

        return view('admin.supplier-synchronizations.show', array_merge($settingsData, compact('automaticSetup', 'activeDateNeedsReview', 'conflictReviews', 'supplierStatus', 'supplier', 'configuration', 'partners', 'runs', 'previews', 'tab', 'sources', 'reviewReasons', 'serviceStates', 'downloads', 'observations', 'incidents')));
    }

    public function retryDownload(Request $request, ExternalDownload $download, AuditLogger $audit): RedirectResponse
    {
        abort_unless(app(SupplierVersions::class)->enabled(), 422);
        DB::transaction(function () use ($download, $audit): void {
            $supplier = Organization::whereKey($download->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $download = ExternalDownload::whereKey($download->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($download->status, ['failed', 'cancelled'], true) && ! $download->worker_token, 422);
            $observation = ExternalAvailability::findOrFail($download->external_availability_id);
            abort_if(app(SupplierVersions::class)->freshnessReason($supplier, $observation->published_at) !== null
                || app(SupplierInboxEvidence::class)->exists($supplier->id, $observation->published_at), 422);
            $claim = app(SupplierVersions::class)->claim($supplier, $observation->published_at, $download->source);
            abort_unless($claim && $claim->external_download_id === $download->id, 422);
            $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail();
            $partner = ExternalSupplierPartner::findOrFail($download->snapshot['partner_id']);
            $state = ExternalServiceState::findOrFail($download->source);
            abort_unless($supplier->external_fetch_enabled && $configuration->automatic_enabled && ! $configuration->paused
                && $state->status !== 'paused' && ! $state->cooldown_until?->isFuture()
                && $partner->supplier_organization_id === $supplier->id && $partner->source === $download->source && $partner->external_id === $download->external_id && $partner->profile_active, 422);
            $before = $download->only(['status', 'retry_count', 'snapshot']);
            $snapshot = array_replace($download->snapshot, ['configuration_version' => $configuration->version, 'profile_version' => $partner->profile_version, 'profile' => $partner->profile_configuration]);
            $download->update(['status' => 'retry', 'retry_count' => 0, 'next_retry_at' => null, 'reason' => null, 'snapshot' => $snapshot]);
            $claim->update(['state' => 'reserved']);
            DownloadExternalPricePage::dispatch($download->id)->onQueue(config('price-list-imports.queue'))->afterCommit();
            $audit->log('supplier_download.retry_requested', $download, $before, $download->only(['status', 'retry_count', 'snapshot']));
        }, 3);

        return back()->with('success', 'Повтор существующей попытки поставлен в очередь.');
    }

    public function externalFetching(Request $request, Organization $supplier, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['external_fetch_enabled' => ['required', 'boolean'], 'exclusion_reason' => ['nullable', 'string', 'max:255'], 'expected_version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($supplier, $data, $audit): void {
            $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            abort_unless($supplier->type === OrganizationType::Wholesaler, 404);
            $configuration = SupplierSynchronization::firstOrNew(['supplier_organization_id' => $supplier->id]);
            if ((int) ($configuration->version ?? 0) !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'Настройки изменились. Обновите страницу.']);
            }
            $before = $supplier->only(['external_fetch_enabled', 'external_fetch_exclusion_reason', 'external_fetch_overridden']);
            $supplier->update(['external_fetch_enabled' => $data['external_fetch_enabled'], 'external_fetch_exclusion_reason' => $data['external_fetch_enabled'] ? null : ($data['exclusion_reason'] ?? 'administrator'), 'external_fetch_overridden' => true]);
            $configuration->fill(['selection_mode' => 'daily_versions', 'sources' => SupplierSynchronization::defaultSources(), 'automatic_enabled' => $data['external_fetch_enabled'], 'paused' => false, 'settings_confirmed' => true, 'selected_source' => null, 'workflow_policy' => null, 'fetch_interval_minutes' => 60, 'freshness_hours' => 24, 'version' => ($configuration->version ?? 0) + 1])->save();
            $audit->log('supplier_external_fetch.overridden', $supplier, $before, $supplier->only(['external_fetch_enabled', 'external_fetch_exclusion_reason', 'external_fetch_overridden']));
        }, 3);

        return back()->with('success', 'Настройки внешней загрузки сохранены. Почта и ручная загрузка доступны независимо.');
    }

    public function update(UpdateSupplierSynchronizationRequest $request, Organization $supplier, SupplierImportSettings $settings): RedirectResponse
    {
        $settings->update($supplier, $request->validated());

        return back()->with('success', 'Настройки синхронизации сохранены.');
    }

    public function profile(UpdateExternalSupplierProfileRequest $request, ExternalSupplierPartner $partner, SupplierImportSettings $settings): RedirectResponse
    {
        $settings->updateProfile((int) $request->validated('expected_supplier_organization_id'), $partner, $request->validated());

        return back()->with('success', 'Профиль источника сохранён.');
    }

    public function fetch(Request $request, Organization $supplier, SupplierSynchronizationService $service, AuditLogger $audit): RedirectResponse
    {
        abort_unless($supplier->type === OrganizationType::Wholesaler, 404);
        $data = $request->validate([
            'expected_version' => ['required_with:expected_source', 'integer', 'min:1'],
            'expected_source' => ['required_with:expected_version', 'in:icorp,pricelist_tj'],
        ], $this->validationMessages());
        $run = $service->request($supplier, expectedVersion: isset($data['expected_version']) ? (int) $data['expected_version'] : null, expectedSource: $data['expected_source'] ?? null);
        $audit->log('supplier_sync.manual_requested', $run);

        return back()->with('success', 'Проверка поставлена в очередь. Подходящий более новый прайс будет загружен и опубликован. Повторные запросы объединяются.')->with('supplier_import_requested', $supplier->id);
    }

    public function connect(Request $request, string $source, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'take_over' => ['prohibited'],
            'phone' => $source === 'icorp' ? ['sometimes', 'required', 'string', 'max:32', 'regex:/^(?:\+?992[ -]?)?\d[\d -]{7,14}\d$/'] : ['prohibited'],
            'password' => [$source === 'icorp' ? 'nullable' : 'prohibited', 'string', 'max:1024'],
        ], [...$this->validationMessages(), 'phone.regex' => 'Введите телефон iCorp в формате 9 цифр или +992 и 9 цифр.']);
        if ($request->filled('password') && ! $request->filled('phone')) {
            throw ValidationException::withMessages(['phone' => 'Введите телефон iCorp.']);
        }
        if ($request->has('phone') && strlen(app(IcorpCredentials::class)->normalize($request->string('phone')->toString())) !== 9) {
            throw ValidationException::withMessages(['phone' => 'Введите телефон iCorp в формате 9 цифр или +992 и 9 цифр.']);
        }

        return $this->establishConnection($request, $source, $audit, false);
    }

    public function takeover(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']], $this->validationMessages());

        return $this->establishConnection($request, 'icorp', $audit, true);
    }

    private function establishConnection(Request $request, string $source, AuditLogger $audit, bool $takeOver): RedirectResponse
    {
        abort_unless(in_array($source, ['icorp', 'pricelist_tj'], true), 404);
        $audit->log($takeOver ? 'supplier_sync.takeover_requested' : 'supplier_sync.connection_requested', $request->user(), [], ['source' => $source]);
        $credentialsSaved = false;
        try {
            Cache::lock('external-prices:'.$source, 7200)->block(1, function () use ($source, $takeOver, $request, $audit, &$credentialsSaved): void {
                if ($source === 'icorp') {
                    if (! $takeOver && $request->has('phone')) {
                        app(IcorpCredentials::class)->save($request->string('phone')->toString(), $request->input('password'));
                        $credentialsSaved = true;
                        $audit->log('supplier_sync.credentials_updated', $request->user(), [], ['source' => 'icorp']);
                    }
                    app(IcorpClient::class)->connect($takeOver);
                } else {
                    app(PriceListTjClient::class)->connect();
                }
            });
            Cache::forever('external-prices:status:'.$source, ['status' => 'connected', 'checked_at' => now()->toIso8601String()]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $reason = $exception instanceof ExternalServiceUnavailable ? $exception->reason : ExternalSupplierDirectory::failureReason($exception);
            if (in_array($reason, ['authentication', 'permission', 'challenge', 'takeover_required'], true)) {
                app(ExternalServiceControl::class)->pause($source, $reason);
            }
            Cache::forever('external-prices:status:'.$source, ['status' => $reason, 'checked_at' => now()->toIso8601String()]);

            return back()->with('warning', ($credentialsSaved ? 'Данные сохранены, подключение не выполнено: ' : 'Подключение не выполнено: ').$this->connectionFailureLabel($reason));
        }

        return back()->with('success', 'Подключение установлено.');
    }

    public function confirmDate(Request $request, PriceListImport $import, AuditLogger $audit): RedirectResponse
    {

        abort_if(app(SupplierVersions::class)->enabled(), 422, 'Одна версия в день; замена одинаковой даты недоступна.');
        $data = $this->publicationConfirmation($request);
        DB::transaction(function () use ($import, $data, $request, $audit): void {
            Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $import = PriceListImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($import->status, [PriceListImportStatus::Preview, PriceListImportStatus::Completed], true), 422);
            $before = $import->only(['source_published_at', 'source_date_precision']);
            $date = $data['date'];
            $import->update(['source_published_at' => $date, 'inventory_at' => $date, 'source_date_precision' => $data['precision'],
                'source_metadata' => array_replace($import->source_metadata ?? [], ['date_confirmed_by' => $request->user()->id, 'date_evidence' => $data['evidence'], 'confirmed_precision' => $data['precision']])]);
            $audit->log('supplier_sync.date_confirmed', $import, $before, ['published_at' => $date->toIso8601String(), 'evidence' => $data['evidence']]);
            app(ActivationDispatcher::class)->dispatch($import, $request->user()->id);
        }, 3);

        return back()->with('success', 'Дата подтверждена. Перед активацией будут повторно проверены настройки и выбранный источник.');
    }

    public function confirmAvailabilityDate(Request $request, ExternalAvailability $availability, AuditLogger $audit): RedirectResponse
    {

        abort_if(app(SupplierVersions::class)->enabled(), 422, 'Даты берутся только из списка поставщиков.');
        $request->validate(['version_key' => ['required', 'string']], $this->validationMessages());
        $data = $this->publicationConfirmation($request) + $request->only('version_key');
        $partner = ExternalSupplierPartner::findOrFail($availability->external_supplier_partner_id);
        $supplierId = $partner->supplier_organization_id;
        DB::transaction(function () use ($availability, $partner, $supplierId, $request, $data, $audit): void {
            Organization::whereKey($partner->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $partner = ExternalSupplierPartner::whereKey($partner->id)->lockForUpdate()->firstOrFail();
            $current = ExternalAvailability::whereKey($availability->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->available && $current->version_key === $data['version_key'] && $partner->supplier_organization_id === $supplierId && $current->id === ExternalAvailability::where('external_supplier_partner_id', $partner->id)->orderByDesc('last_checked_at')->orderByDesc('id')->value('id'), 409);
            $before = $current->toArray();
            $date = $data['date'];
            $current->update(['published_at' => $date, 'date_precision' => $data['precision'], 'evidence' => array_replace($current->evidence, [
                'confirmed_published_at' => $date->toIso8601String(), 'date_confirmed_by' => $request->user()->id, 'date_evidence' => $data['evidence'], 'confirmed_precision' => $data['precision']])]);
            $audit->log('supplier_sync.availability_date_confirmed', $current, $before, $current->toArray());
        }, 3);

        return back()->with('success', 'Дата публикации подтверждена. Запросите загрузку для повторной проверки.');
    }

    public function resolveConflict(Request $request, PriceListImport $import, AuditLogger $audit): RedirectResponse
    {

        abort_if(app(SupplierVersions::class)->enabled(), 422, 'Одна версия в день; замена одинаковой даты недоступна.');
        $data = $request->validate(['comparison_token' => ['required', 'string', 'size:64'], 'evidence' => ['required', 'string', 'max:1000']], $this->validationMessages());
        DB::transaction(function () use ($request, $import, $data, $audit): void {
            Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $import = PriceListImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $configuration = SupplierSynchronization::where('supplier_organization_id', $import->supplier_organization_id)->firstOrFail();
            $candidates = app(NewestSupplierCandidates::class);
            abort_unless($configuration->reviewsConflicts() && $import->status === PriceListImportStatus::Preview
                && in_array(app(SupplierSourcePolicy::class)->reason($import), ['review_equal_date_conflict', 'review_date_precision'], true), 422);
            abort_unless(hash_equals($candidates->comparisonToken($import, $configuration), $data['comparison_token']), 409);
            $before = $import->source_metadata;
            $import->update(['source_metadata' => array_replace($before ?? [], ['conflict_review' => [
                'candidate' => $candidates->reviewToken($import, $configuration),
                'others' => array_map(fn ($other): string => $candidates->reviewToken($other, $configuration), $candidates->reviewComparisons($import, $configuration)),
                'actor_id' => $request->user()->id, 'evidence' => $data['evidence'], 'reviewed_at' => now()->toIso8601String(),
            ]])]);
            $audit->log('supplier_sync.conflict_resolved', $import, $before ?? [], $import->source_metadata);
            app(ActivationDispatcher::class)->dispatch($import, $request->user()->id);
        }, 3);

        return back()->with('success', 'Выбор версии записан. Перед публикацией настройки и актуальность будут проверены повторно.');
    }

    public function reject(PriceListImport $import, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($import, $audit): void {
            Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $import = PriceListImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            abort_unless($import->status === PriceListImportStatus::Preview, 422);
            $import->update(['status' => PriceListImportStatus::Failed, 'failed_at' => now(), 'failure_stage' => 'review', 'failure_message' => 'Отклонено администратором.']);
            $audit->log('supplier_sync.preview_rejected', $import);
        }, 3);

        return back()->with('success', 'Предпросмотр отклонён. Активный каталог сохранён.');
    }

    /** @return array{date: CarbonImmutable, precision: string, evidence: string} */
    private function publicationConfirmation(Request $request): array
    {
        $value = $request->input('published_at');
        $inferred = is_string($value) && strlen($value) === 10 ? 'day' : (is_string($value) && strlen($value) === 16 ? 'minute' : 'second');
        $precision = $request->input('precision', $inferred);
        $format = match ($inferred) {
            'day' => 'Y-m-d', 'minute' => 'Y-m-d\\TH:i', default => 'Y-m-d\\TH:i:s'
        };
        $data = $request->validate(['published_at' => ['required', 'date_format:'.$format], 'precision' => ['sometimes', 'in:day,minute,second'], 'evidence' => ['required', 'string', 'max:1000']], $this->validationMessages());
        if (array_search($precision, ['day', 'minute', 'second'], true) > array_search($inferred, ['day', 'minute', 'second'], true)) {
            throw ValidationException::withMessages(['precision' => 'Указанная дата не содержит такой точности.']);
        }
        $date = CarbonImmutable::createFromFormat('!'.$format, $data['published_at'], config('price-list-imports.timezone'));
        $date = match ($precision) {
            'day' => $date->startOfDay(), 'minute' => $date->startOfMinute(), default => $date
        };
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['published_at' => 'Дата не может быть в будущем.']);
        }

        return ['date' => $date, 'precision' => $precision, 'evidence' => $data['evidence']];
    }

    private function connectionFailureLabel(string $reason): string
    {
        return match ($reason) {
            'authentication' => 'требуется авторизация.',
            'permission' => 'нет доступа.',
            'transport' => 'ошибка соединения.',
            'takeover_required' => 'аккаунт занят на другом устройстве. Используйте отдельный принудительный вход после согласования.',
            default => 'ошибка чтения данных.',
        };
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return [
            'required' => 'Заполните это поле.',
            'required_without' => 'Заполните это поле.',
            'accepted' => 'Подтвердите действие.',
            'in' => 'Выберите допустимое значение.',
            'exists' => 'Выберите существующего поставщика.',
            'integer' => 'Введите целое число.',
            'numeric' => 'Введите число.',
            'boolean' => 'Выберите состояние.',
            'string' => 'Введите текст.',
            'max' => 'Допустимое максимальное значение: :max.',
            'min' => 'Допустимое минимальное значение: :min.',
            'unique' => 'Этот телефон уже используется.',
            'date_format' => 'Укажите корректные дату и время.',
            'before_or_equal' => 'Дата не может быть в будущем.',
            'prohibited' => 'Это действие недоступно в обычном подключении.',
        ];
    }
}
