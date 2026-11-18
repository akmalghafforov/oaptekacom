<?php

namespace App\Services;

use App\Enums\PriceListImportSource;
use App\Models\ExternalDownload;
use App\Models\ExternalImportQueueItem;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSyncRun;
use App\Models\User;

class ImportProgress
{
    /** @return array<string, mixed> */
    public function import(PriceListImport $import): array
    {
        $status = $import->status->value;
        $processed = (int) $import->rows_count;
        $reason = $status === 'preview' ? app(SupplierSourcePolicy::class)->reason($import) : null;
        $detail = match (true) {
            $import->failure_stage === 'version_skipped' => 'Пропущен: этот или более новый день уже импортирован либо версия обрабатывается.',
            $status === 'failed' => 'Импорт завершился с ошибкой. Активный каталог сохранён.',
            $reason === 'source_not_selected' => 'Выбран другой источник. Активный каталог сохранён.',
            in_array($reason, ['configuration_changed', 'mapping_changed', 'profile_changed'], true) => 'Настройки изменились. Запустите новый импорт.',
            in_array($reason, ['review_missing_date', 'review_future_date', 'review_active_date_unknown'], true) => 'Уточните дату публикации и основание в разделе «Требуют проверки». Активный каталог сохранён.',
            $reason === 'review_date_precision' => 'Даты пересекаются: точности недостаточно для выбора нового прайса. Сравните версии.',
            $reason === 'review_equal_date_conflict' => 'Разное содержимое при равной дате. Сравните версии и подтвердите выбор.',
            $reason === 'validation_failed' => 'Файл не прошёл пороги проверки. Исправьте профиль; активный каталог сохранён.',
            $reason !== null => 'Требуется проверка даты или настроек источника.',
            $status === 'processing' => 'Обработано строк: '.$processed.' / '.$import->total_rows,
            $status === 'committing' => 'Публикация проверенных товаров в каталоге.',
            $status === 'completed' => 'Каталог опубликован.',
            $status === 'pending' => 'Файл получен. Ожидается обработка.',
            default => $import->status->label(),
        };

        $entry = ['id' => 'import-'.$import->id, 'import_id' => $import->id, 'title' => $import->supplier->name.' · '.$import->source_type->label().' · №'.$import->id,
            'reason' => $reason, 'label' => $import->failure_stage === 'version_skipped' ? 'Пропущен' : ($reason === 'unchanged_content' ? 'Без изменений' : ($reason && str_starts_with($reason, 'review_') ? 'Требуется проверка' : $import->status->label())), 'detail' => $detail, 'active' => in_array($status, ['pending', 'processing', 'committing'], true) || ($status === 'preview' && $reason === null),
            'filename' => $import->original_filename, 'source' => $import->source_type->value,
            'source_label' => $import->source_type->value === 'pricelist_tj' ? 'PriceList.tj' : $import->source_type->label(),
            'supplier' => $import->supplier->name, 'status' => $status,
            'rows' => $import->valid_rows.' / '.$import->error_rows.' ош.', 'date' => $import->created_at->copy()->timezone('Asia/Dushanbe')->format('d.m.Y H:i'),
            'processed' => $processed, 'total' => (int) $import->total_rows, 'url' => route('price-list-imports.show', $import)];

        $run = app(ExternalImportQueue::class)->runForImport($import);

        return $run ? $this->withQueue($entry, $run->id) : array_replace($entry, ['unfinished' => $entry['active'], 'owns_lane' => false, 'is_processing' => in_array($status, ['processing', 'committing'], true)]);
    }

    /** @return array<string, mixed> */
    public function run(SupplierSyncRun $run, ?ExternalDownload $download = null): array
    {
        $source = $download?->source ?? $run->selected_source ?? $run->configuration_snapshot['selected_source'] ?? $run->attempts->first(fn (SupplierSyncAttempt $attempt): bool => in_array($attempt->source, [PriceListImportSource::Icorp->value, PriceListImportSource::PriceListTj->value], true))?->source;
        $sourceLabel = $source === 'icorp' ? 'iCorp' : ($source === 'pricelist_tj' ? 'PriceList.tj' : 'Внешний источник');
        $active = $run->active_supplier_id !== null;
        $label = match ($run->status) {
            'queued' => 'В очереди на загрузку', 'processing' => 'Обработка', 'review' => 'Требуется проверка',
            'completed' => 'Каталог опубликован', 'downloading' => 'Загрузка', 'activating' => 'Публикация', 'waiting_mailbox' => 'Ожидает почту', 'waiting_availability' => 'Ожидает проверку источников', 'failed' => 'Ошибка загрузки', 'unchanged' => 'Без изменений', 'skipped' => 'Пропущен',
            'retry', 'cooldown' => 'Ожидание повтора', 'paused' => 'Требуется проверка',
            'waiting_import' => 'Ожидает завершения другого импорта', default => 'Загрузка из сервиса',
        };

        if ($active && $run->attempts->contains('status', 'fetching')) {
            $label = 'Загрузка из сервиса';
        } elseif (! $active && $run->attempts->contains('status', 'failed')) {
            $label = 'Ошибка загрузки';
        }

        $reason = $run->attempts->firstWhere('status', 'failed')?->reason ?? $run->reason;
        $detail = match ($reason) {
            'authentication' => 'Не удалось авторизоваться в сервисе. Активный каталог сохранён.',
            'permission' => 'Сервис отказал в доступе. Активный каталог сохранён.',
            'transport' => 'Не удалось соединиться с сервисом. Активный каталог сохранён.',
            'extraction' => 'Не удалось прочитать данные сервиса. Активный каталог сохранён.',
            'empty_catalog' => 'Сервис вернул пустой прайс-лист. Активный каталог сохранён.',
            'service_busy', 'download_busy' => 'Общая загрузка занята другим поставщиком. Ожидается освобождение очереди.',
            'service_cooldown', 'rate_limited' => 'Сервис ограничил запросы. Повтор выполняется после установленной паузы.',
            'pacing_wait' => 'Ожидается разрешённое время следующего запроса.',
            'mailbox_processing' => 'Ожидается обработка полученных почтовых файлов.',
            'mailbox_unhealthy', 'mailbox_failed' => 'Проверка почты не подтверждена. Результат по остальным источникам может быть частичным.',
            'availability_pending', 'service_retry_pending' => 'Ожидается фоновая проверка доступности источников.',
            'configuration_changed', 'profile_changed', 'mapping_changed' => 'Настройки изменились. Предыдущая работа остановлена; проверьте с новыми настройками.',
            default => $active ? 'После получения файла здесь появится обработка прайс-листа.' : 'Активный каталог сохранён. Подробности доступны в истории синхронизации.',
        };

        if ($run->next_retry_at) {
            $detail .= ' Следующая попытка: '.$run->next_retry_at->copy()->timezone('Asia/Dushanbe')->format('d.m.Y H:i').'.';
        }

        $entry = ['id' => 'run-'.$run->id, 'import_id' => null, 'title' => $run->supplier->name.' · '.$sourceLabel,
            'label' => $label, 'detail' => $detail,
            'filename' => null, 'source' => $source, 'source_label' => $sourceLabel, 'supplier' => $run->supplier->name,
            'status' => $label === 'Ошибка загрузки' ? 'failed' : $run->status, 'rows' => ($download?->received_rows ?? 0).' / '.($download?->total_rows ?? '—'), 'date' => $run->created_at->copy()->timezone('Asia/Dushanbe')->format('d.m.Y H:i'),
            'active' => $active && ! in_array($run->status, ['review', 'failed', 'skipped', 'unchanged', 'completed'], true), 'processed' => (int) ($download?->received_rows ?? 0), 'total' => (int) ($download?->total_rows ?? 0),
            'url' => route('admin.supplier-sync.show', [$run->supplier_organization_id, 'tab' => 'history'])];

        return $this->withQueue($entry, $run->id);
    }

    /** @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function withQueue(array $entry, int $runId, ?ExternalImportQueueItem $item = null): array
    {
        $items = ExternalImportQueueItem::where('supplier_sync_run_id', $runId)->orderByDesc('generation')->orderBy('id')->get();
        $item ??= $items->firstWhere('requested_by', auth()->id()) ?? $items->first();
        if (! $item) {
            return array_replace($entry, ['unfinished' => $entry['active'], 'owns_lane' => false, 'is_processing' => $entry['status'] === 'downloading']);
        }
        $head = ExternalImportQueueItem::where('lane_id', $item->lane_id)->whereNull('finished_at')->orderBy('id')->value('id');
        $owns = ! $item->finished_at && $item->started_at !== null && (int) $head === $item->id;
        $position = $item->finished_at ? null : ExternalImportQueueItem::where('lane_id', $item->lane_id)->whereNull('finished_at')->where('id', '<=', $item->id)->count();
        $stage = $item->state;
        $download = $item->download;
        if (! $owns && ! $item->finished_at) {
            $stage = 'queued';
        } elseif ($download && ! $item->price_list_import_id && ! $item->finished_at) {
            $stage = match (true) {
                in_array($download->status, ['retry', 'cooldown'], true) => 'retry',
                $download->status === 'paused' => 'blocked',
                ($download->worker_token !== null && $download->worker_expires_at?->isFuture()) || ($item->state === 'downloading' && $item->worker_token !== null && $item->worker_expires_at?->isFuture()) => 'downloading',
                default => 'queued',
            };
        }
        $label = match ($stage) {
            'queued' => 'В очереди', 'retry' => 'Ожидание повтора', 'blocked' => 'Требуется проверка',
            'downloading' => 'Загрузка', 'importing' => 'Импорт', 'publishing' => 'Публикация',
            'completed' => 'Завершено', 'failed' => 'Ошибка', 'skipped' => 'Без изменений', default => $entry['label'],
        };
        $detail = $entry['detail'];
        if ($stage === 'queued') {
            $detail = 'Позиция в очереди: '.$position.'. '.($item->wait_reason === 'shared_execution' ? 'Ожидается уже выполняющийся импорт этой версии.' : ($owns ? 'Ожидается свободный ресурс.' : 'Ожидается завершение предыдущего поставщика.'));
        }
        $import = $item->import;
        $processed = match ($stage) {
            'publishing', 'completed' => $import?->offers()->count() ?? 0,
            'importing' => $import?->rows()->count() ?? 0,
            default => $download?->received_rows ?? 0,
        };
        $total = in_array($stage, ['publishing', 'completed'], true) ? $import?->valid_rows : ($stage === 'importing' ? $import?->total_rows : $download?->total_rows);
        if ($stage === 'publishing') {
            $detail = 'Подготовлено предложений: '.$processed.' / '.($total ?: '—').'. Переключение каталога ещё не завершено.';
        }
        if ($stage === 'retry') {
            $detail = match ($item->wait_reason) {
                'rate_limited', 'service_cooldown' => 'Сервис ограничил запросы. Ожидается окончание общей паузы.',
                'transport' => 'Временная ошибка соединения с сервисом.',
                'download_busy', 'service_busy' => 'Общая загрузка занята другим поставщиком.',
                'pacing_wait' => 'Ожидается разрешённое время следующего запроса.',
                default => 'Ожидается повторная попытка: '.($item->wait_reason ?? 'временная ошибка').'.',
            };
            $detail .= ' Неудачных запросов: '.($download?->retry_count ?? 0).'. Следующий повтор: '.($item->next_retry_at?->copy()->timezone('Asia/Dushanbe')->format('d.m.Y H:i') ?? 'после освобождения ресурса').'.';
        } elseif ($stage === 'blocked') {
            $detail .= ' Требуется действие администратора перед повторным запуском.';
        } elseif ($stage === 'downloading') {
            $detail = 'Получено строк: '.$processed.' / '.($total ?: '—').'. Страниц: '.max(0, ($download?->next_page ?? 1) - 1).' / '.($download?->total_pages ?? '—').'.';
        }

        return array_replace($entry, ['id' => 'queue-'.$item->id, 'queue_item_id' => $item->id, 'group_id' => $item->group_id,
            'lane' => $item->lane_id === 'system' ? 'Системная очередь' : 'Моя очередь', 'stage' => $stage, 'status' => $stage, 'label' => $label, 'detail' => $detail,
            'unfinished' => $item->finished_at === null, 'owns_lane' => $owns,
            'is_processing' => $owns && $item->worker_token !== null && $item->worker_expires_at?->isFuture() && in_array($stage, ['downloading', 'importing', 'publishing'], true),
            'active' => $item->finished_at === null, 'queue_position' => $position,
            'processed' => $processed, 'total' => $total, 'rows' => $processed.' / '.($total ?: '—'),
            'downloaded_pages' => $download ? max(0, $download->next_page - 1) : 0, 'total_pages' => $download?->total_pages,
            'wait_reason' => $item->wait_reason, 'retry_count' => $download?->retry_count ?? 0,
            'next_retry_at' => $item->next_retry_at?->toIso8601String(), 'heartbeat_at' => $item->heartbeat_at?->toIso8601String(),
            'failure_stage' => $import?->failure_stage ?? ($stage === 'failed' ? 'download' : null),
            'failure_message' => $import?->failure_message ?? $download?->reason,
            'generation' => $item->generation, 'revision' => $item->revision]);
    }

    /** @return array<string, mixed> */
    public function queue(User $user): array
    {
        $items = ExternalImportQueueItem::where(function ($query) use ($user): void {
            $query->where('requested_by', $user->id);
            if ($user->isAdminMode()) {
                $query->orWhereNull('requested_by');
            }
        })
            ->when(! $user->isAdminMode(), fn ($query) => $query->whereHas('run', fn ($query) => $query->where('supplier_organization_id', $user->activeOrganizationId())))
            ->with(['run.supplier', 'run.attempts', 'download', 'import.supplier'])->orderBy('id')->get();
        $counts = ['waiting' => 0, 'processing' => 0, 'succeeded' => 0, 'skipped' => 0, 'failed' => 0, 'blocked' => 0];
        $active = [];
        foreach ($items as $item) {
            $key = match ($item->state) {
                'completed' => 'succeeded', 'failed' => 'failed', 'skipped' => 'skipped', 'blocked' => 'blocked',
                'downloading', 'importing', 'publishing' => 'processing', default => 'waiting',
            };
            $counts[$key]++;
            if (! $item->finished_at && $item->started_at) {
                $entry = $item->import ? $this->import($item->import->loadCount('rows')) : $this->run($item->run, $item->download);
                if (! $user->isAdminMode() && ! $item->import) {
                    $entry['url'] = null;
                }
                $active[] = $this->withQueue($entry, $item->supplier_sync_run_id, $item);
            }
        }
        $groups = $items->groupBy('group_id')->map(function ($members, string $id): array {
            return ['id' => $id, 'total' => $members->count(), 'finished' => $members->whereNotNull('finished_at')->count(),
                'succeeded' => $members->where('state', 'completed')->count(), 'skipped' => $members->where('state', 'skipped')->count(),
                'failed' => $members->where('state', 'failed')->count(), 'blocked' => $members->where('state', 'blocked')->count()];
        })->values()->all();

        return ['counts' => $counts, 'active' => $active, 'groups' => $groups, 'revision' => (int) $items->sum('revision')];
    }
}
