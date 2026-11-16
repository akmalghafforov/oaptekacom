<?php

namespace App\Services;

use App\Enums\PriceListImportStatus;
use App\Models\ExternalDownload;
use App\Models\ExternalServiceState;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use Illuminate\Support\Collection;

class SupplierSynchronizationStatus
{
    public function __construct(private ImportProgress $progress) {}

    /** @return array<string, mixed> */
    public function forSupplier(Organization $supplier, ?SupplierSynchronization $configuration): array
    {
        return $this->forSuppliers(collect([$supplier]), collect([$supplier->id => $configuration]))[$supplier->id];
    }

    /** @param Collection<int, Organization> $suppliers @param Collection<int, SupplierSynchronization|null> $configurations @return Collection<int, array<string, mixed>> */
    public function forSuppliers(Collection $suppliers, Collection $configurations): Collection
    {
        $ids = $suppliers->pluck('id');
        (new \Illuminate\Database\Eloquent\Collection($suppliers->all()))->loadMissing(['activePriceListImport', 'importProfile', 'senderAddresses']);
        $serviceStates = ExternalServiceState::all()->keyBy('source');
        $partners = ExternalSupplierPartner::whereIn('supplier_organization_id', $ids)->get()->groupBy('supplier_organization_id');
        $runs = SupplierSyncRun::whereIn('id', SupplierSyncRun::selectRaw('MAX(id)')->whereIn('supplier_organization_id', $ids)->groupBy('supplier_organization_id'))
            ->with(['supplier', 'attempts.import'])->get()->keyBy('supplier_organization_id');
        $imports = PriceListImport::whereIn('supplier_organization_id', $ids)
            ->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing, PriceListImportStatus::Preview])
            ->with('supplier')->withCount('rows')->latest('id')->get()->groupBy('supplier_organization_id');
        $downloads = ExternalDownload::whereIn('supplier_organization_id', $ids)->whereNotIn('status', ['completed', 'failed', 'cancelled'])->latest('id')->get()->groupBy('supplier_organization_id')->map->first();

        return $suppliers->mapWithKeys(fn (Organization $supplier): array => [$supplier->id => $this->project($supplier, $configurations->get($supplier->id), $runs->get($supplier->id), $imports->get($supplier->id, collect()), $downloads->get($supplier->id), $partners->get($supplier->id, collect()), $serviceStates)]);
    }

    /** @param Collection<int, PriceListImport> $imports @return array<string, mixed> */
    private function project(Organization $supplier, ?SupplierSynchronization $configuration, ?SupplierSyncRun $run, Collection $imports, ?ExternalDownload $download, Collection $partners, Collection $serviceStates): array
    {
        $active = $supplier->activePriceListImport;
        $entries = $imports->map(function (PriceListImport $import) use ($supplier): array {
            $entry = $this->progress->import($import);
            $reason = $entry['reason'];
            if (str_starts_with($reason ?? '', 'review_')) {
                $entry['url'] = route('admin.supplier-sync.show', [$supplier, 'tab' => 'review']);
                $entry['action_label'] = in_array($reason, ['review_equal_date_conflict', 'review_date_precision'], true) ? 'Сравнить версии' : 'Уточнить дату';
            } elseif (in_array($reason, ['validation_failed', 'configuration_changed', 'mapping_changed', 'profile_changed'], true)) {
                $entry['url'] = route('admin.supplier-sync.show', [$supplier, 'tab' => 'sources']);
                $entry['action_label'] = 'Исправить профиль и настройки';
            }

            return $entry;
        })->all();
        if ($run && ! $imports->contains(fn (PriceListImport $import): bool => $run->attempts->contains('price_list_import_id', $import->id))) {
            array_unshift($entries, $this->progress->run($run));
        }
        $retry = $run?->active_supplier_id ? $run->next_retry_at : null;
        $retry ??= $run?->active_supplier_id ? $download?->next_retry_at : null;
        $next = $retry ?? ($configuration?->automatic_enabled && ! $configuration->paused ? $configuration->next_check_at : null);
        $review = collect($entries)->contains(fn (array $entry): bool => str_starts_with($entry['reason'] ?? '', 'review_') || in_array($entry['reason'] ?? null, ['validation_failed', 'configuration_changed', 'profile_changed', 'mapping_changed'], true)) || $run?->status === 'review';
        $updating = collect($entries)->contains('active', true);
        $overdue = $configuration?->automatic_enabled && ! $configuration->paused && $next?->lt(now()->subMinutes(5)) && ! $updating;
        $sourceHealth = collect(array_keys(SupplierSynchronization::defaultSources()))->mapWithKeys(function (string $source) use ($supplier, $configuration, $partners, $serviceStates): array {
            $partner = $partners->firstWhere('source', $source);
            $state = $serviceStates->get($source);

            return [$source => ['enabled' => in_array($source, $configuration?->enabledSources() ?? [], true),
                'identity_confirmed' => $source === 'email' ? $supplier->senderAddresses->isNotEmpty() : $partner !== null,
                'profile_ready' => $source === 'email' ? (bool) $supplier->importProfile?->is_active : (bool) ($partner?->profile_active && $partner?->profile_configuration),
                'connection_status' => $state?->status ?? 'unknown', 'reason' => $state?->reason,
                'last_shared_check' => $state?->last_checked_at?->toIso8601String(), 'cooldown_until' => $state?->cooldown_until?->toIso8601String(),
            ]];
        });
        $partial = $run && collect($configuration?->enabledSources() ?? [])->contains(fn (string $source): bool => ! $run->attempts->contains(fn ($attempt): bool => $attempt->source === $source && $attempt->status !== 'failed' && ! in_array($attempt->reason, ['authentication', 'permission', 'transport', 'extraction', 'takeover_required', 'challenge', 'rate_limited', 'service_cooldown', 'service_retry_pending', 'availability_pending', 'mailbox_unhealthy', 'mailbox_failed'], true)));
        $failed = $run?->status === 'failed' || (! $run?->active_supplier_id && $run?->attempts->contains('status', 'failed') && $run->status !== 'completed');
        $state = match (true) {
            $review => 'review', $updating => 'updating', $failed => 'failed', $overdue => 'overdue',
            (bool) $configuration?->paused => 'paused', (bool) $configuration?->automatic_enabled => 'configured', default => 'off',
        };
        $labels = ['review' => 'Требуется проверка', 'updating' => 'Обновляется', 'failed' => 'Ошибка проверки', 'overdue' => 'Проверка задерживается', 'paused' => 'На паузе', 'configured' => 'Автообновление включено', 'off' => 'Автообновление выключено'];
        $format = fn ($date): ?string => $date?->copy()->timezone('Asia/Dushanbe')->format('d.m.Y H:i');
        if ($download && $entries !== []) {
            $downloadState = match ($download->status) {
                'retry' => 'Ожидается повтор загрузки.', 'cooldown' => 'Загрузка приостановлена до окончания паузы сервиса.',
                'paused' => 'Восстановите общее подключение сервиса.', default => 'Загрузка страниц источника.',
            };
            $entries[0]['detail'] .= ' '.$downloadState.' Получено строк: '.$download->received_rows.' / '.($download->total_rows ?? '—').'.';
            if ($download->next_retry_at) {
                $entries[0]['detail'] .= ' Следующая попытка: '.$format($download->next_retry_at).'.';
            }
        }

        return [
            'state' => $state, 'label' => $labels[$state], 'attention' => $review || $failed || $overdue || $partial,
            'active_source' => $active?->source_type->label() ?? 'Каталог отсутствует',
            'publication' => $format($active?->source_published_at), 'precision' => $active?->source_date_precision,
            'evidence' => $active?->source_metadata['date_evidence'] ?? match ($active?->source_metadata['publication_origin'] ?? null) {
                'cell' => 'Ячейка таблицы', 'filename' => 'Имя файла', default => null
            },
            'activated_at' => $format($configuration?->last_activated_at ?? $active?->activated_at),
            'last_check' => $format($run?->finished_at), 'next_check' => $format($next),
            'newer_data' => match (true) {
                $review => 'Ожидает проверки', $updating => 'Проверяется', $failed || $partial => 'Частично проверено: доступность некоторых источников не подтверждена',
                $run?->status === 'completed' => 'Более новый прайс опубликован',
                $run?->status === 'unchanged' => 'Не найдено среди проверенных источников', default => 'Неизвестно',
            },
            'recovery' => $run?->active_supplier_id && $run->lease_expires_at?->isPast() ? 'Срок работы исполнителя истёк. Ожидается автоматическое восстановление.' : null,
            'partial' => (bool) $partial, 'reason' => $run?->reason,
            'source_health' => $sourceHealth->all(),
            'checked_at' => now()->toIso8601String(), 'entries' => $entries,
        ];
    }
}
