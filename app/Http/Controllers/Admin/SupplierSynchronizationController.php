<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Enums\PriceListImportStatus;
use App\Enums\TradeMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveSupplierMappingRequest;
use App\Http\Requests\UpdateExternalSupplierProfileRequest;
use App\Http\Requests\UpdateSupplierSynchronizationRequest;
use App\Jobs\RefreshExternalSupplierDirectory;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Services\AuditLogger;
use App\Services\ExternalSupplierDirectory;
use App\Services\IcorpClient;
use App\Services\IcorpCredentials;
use App\Services\IcorpFailure;
use App\Services\PriceList\ActivationDispatcher;
use App\Services\PriceListTjClient;
use App\Services\SupplierDirectoryContext;
use App\Services\SupplierImportSettings;
use App\Services\SupplierMappingApproval;
use App\Services\SupplierSourcePolicy;
use App\Services\SupplierSuggestionMatcher;
use App\Services\SupplierSynchronizationService;
use Carbon\CarbonImmutable;
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
        $tab = (str_starts_with((string) old('_sync_form'), 'mapping-') || str_starts_with((string) old('_sync_form'), 'unmap-') || str_starts_with((string) old('_sync_form'), 'approve-')) ? 'external' : ($request->input('tab') ?: 'external');
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
        $suppliers = Organization::where('type', OrganizationType::Wholesaler)
            ->when($request->filled('supplier_q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('supplier_q').'%'))
            ->orderBy('name')->orderBy('id')->paginate(25, ['id', 'name'], 'supplier_page')->withQueryString();
        $statuses = collect(['icorp', 'pricelist_tj'])->mapWithKeys(fn (string $source): array => [$source => Cache::get('external-prices:status:'.$source, ['status' => 'unknown'])]);

        try {
            $icorpLogin = app(IcorpCredentials::class)->current()['phone'];
        } catch (IcorpFailure) {
            $icorpLogin = '';
        }

        return view('admin.supplier-synchronizations.index', compact('icorpLogin', 'tab', 'partners', 'suppliers', 'allSuppliers', 'suggestions', 'statuses', 'directoryCounts', 'mappedPartners', 'configurations', 'reviewSuggestions', 'allPartners', 'partnerEvidence', 'supplierEvidence', 'restoredReview'));
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
                SupplierSynchronization::firstOrCreate(['supplier_organization_id' => $supplier->id], ['sources' => SupplierSynchronization::defaultSources(), 'source_conversion' => ['new_configuration' => true, 'reason' => 'selection_required']]);
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

    public function show(Request $request, Organization $supplier, SupplierSourcePolicy $policy): View|RedirectResponse
    {
        abort_unless($supplier->type === OrganizationType::Wholesaler, 404);
        $request->validate(['tab' => ['nullable', 'in:overview,sources,review,history'], 'page' => ['nullable', 'integer', 'min:1']], $this->validationMessages());
        if ($request->input('tab') === 'sources') {
            return redirect()->route('admin.supplier-import-profiles.edit', $supplier);
        }
        $tab = ($request->input('tab') ?: 'overview');
        $supplier->load(['importProfile', 'activePriceListImport']);
        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->first()
            ?? new SupplierSynchronization(['automatic_enabled' => false, 'paused' => false, 'fetch_interval_minutes' => 15, 'freshness_hours' => 24, 'sources' => SupplierSynchronization::defaultSources()]);
        $partners = ExternalSupplierPartner::where('supplier_organization_id', $supplier->id)->orderBy('source')->get();
        $previews = PriceListImport::where('supplier_organization_id', $supplier->id)->where('status', PriceListImportStatus::Preview)->latest('id')->get();
        $submittedForm = (string) old('_sync_form');
        if ($previews->contains(fn (PriceListImport $preview): bool => $submittedForm === 'date-'.$preview->id)) {
            $tab = 'review';
        }
        $runs = SupplierSyncRun::where('supplier_organization_id', $supplier->id)->with('attempts.import')->latest('id')->paginate(20, ['*'], 'page', $tab === 'history' ? max(1, $request->integer('page', 1)) : 1)->withQueryString();

        $reviewReasons = $previews->mapWithKeys(fn (PriceListImport $preview): array => [$preview->id => $policy->reason($preview) ?? 'review_pending']);
        $sources = collect(SupplierSynchronization::defaultSources())->map(fn (array $settings, string $source): array => array_replace($settings, is_array($configuration->sources[$source] ?? null) ? $configuration->sources[$source] : []))->sortBy('priority');

        return view('admin.supplier-synchronizations.show', compact('supplier', 'configuration', 'partners', 'runs', 'previews', 'tab', 'sources', 'reviewReasons'));
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

        return back()->with('success', 'Загрузка поставлена в очередь. Повторные запросы объединяются.')->with('supplier_import_requested', $supplier->id);
    }

    public function connect(Request $request, string $source, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'take_over' => ['prohibited'],
            'phone' => [$source === 'icorp' ? 'sometimes' : 'prohibited', 'required', 'string', 'max:32', 'regex:/^(?:\+?992[ -]?)?\d[\d -]{7,14}\d$/'],
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
            $reason = ExternalSupplierDirectory::failureReason($exception);
            Cache::forever('external-prices:status:'.$source, ['status' => $reason, 'checked_at' => now()->toIso8601String()]);

            return back()->with('warning', ($credentialsSaved ? 'Данные сохранены, подключение не выполнено: ' : 'Подключение не выполнено: ').$this->connectionFailureLabel($reason));
        }

        return back()->with('success', 'Подключение установлено.');
    }

    public function confirmDate(Request $request, PriceListImport $import, AuditLogger $audit): RedirectResponse
    {
        $value = $request->input('published_at');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) {
            $request->merge(['published_at' => $value.':00']);
        }
        $data = $request->validate(['published_at' => ['required', 'date_format:Y-m-d\TH:i:s', 'before_or_equal:now'], 'evidence' => ['required', 'string', 'max:1000']], $this->validationMessages());
        DB::transaction(function () use ($import, $data, $request, $audit): void {
            Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $import = PriceListImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($import->status, [PriceListImportStatus::Preview, PriceListImportStatus::Completed], true), 422);
            $before = $import->only(['source_published_at', 'source_date_precision']);
            $date = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s', $data['published_at'], config('price-list-imports.timezone'));
            $import->update(['source_published_at' => $date, 'inventory_at' => $date, 'source_date_precision' => 'second',
                'source_metadata' => array_replace($import->source_metadata ?? [], ['date_confirmed_by' => $request->user()->id, 'date_evidence' => $data['evidence']])]);
            $audit->log('supplier_sync.date_confirmed', $import, $before, ['published_at' => $date->toIso8601String(), 'evidence' => $data['evidence']]);
            app(ActivationDispatcher::class)->dispatch($import, $request->user()->id);
        }, 3);

        return back()->with('success', 'Дата подтверждена. Перед активацией будут повторно проверены настройки и выбранный источник.');
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
