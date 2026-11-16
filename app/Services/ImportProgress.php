<?php

namespace App\Services;

use App\Enums\PriceListImportSource;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSyncRun;

class ImportProgress
{
    /** @return array<string, mixed> */
    public function import(PriceListImport $import): array
    {
        $status = $import->status->value;
        $processed = (int) $import->rows_count;
        $reason = $status === 'preview' ? app(SupplierSourcePolicy::class)->reason($import) : null;
        $detail = match (true) {
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

        return ['id' => 'import-'.$import->id, 'import_id' => $import->id, 'title' => $import->supplier->name.' · '.$import->source_type->label().' · №'.$import->id,
            'reason' => $reason, 'label' => $reason === 'unchanged_content' ? 'Без изменений' : ($reason && str_starts_with($reason, 'review_') ? 'Требуется проверка' : $import->status->label()), 'detail' => $detail, 'active' => in_array($status, ['pending', 'processing', 'committing'], true) || ($status === 'preview' && $reason === null),
            'filename' => $import->original_filename, 'source' => $import->source_type->value,
            'source_label' => $import->source_type->value === 'pricelist_tj' ? 'PriceList.tj' : $import->source_type->label(),
            'supplier' => $import->supplier->name, 'status' => $status,
            'rows' => $import->valid_rows.' / '.$import->error_rows.' ош.', 'date' => $import->created_at->copy()->timezone('Asia/Dushanbe')->format('d.m.Y H:i'),
            'processed' => $processed, 'total' => (int) $import->total_rows, 'url' => route('price-list-imports.show', $import)];
    }

    /** @return array<string, mixed> */
    public function run(SupplierSyncRun $run): array
    {
        $source = $run->configuration_snapshot['selected_source'] ?? $run->attempts->first(fn (SupplierSyncAttempt $attempt): bool => in_array($attempt->source, [PriceListImportSource::Icorp->value, PriceListImportSource::PriceListTj->value], true))?->source;
        $sourceLabel = $source === 'icorp' ? 'iCorp' : ($source === 'pricelist_tj' ? 'PriceList.tj' : 'Внешний источник');
        $active = $run->active_supplier_id !== null;
        $label = match ($run->status) {
            'queued' => 'В очереди на загрузку', 'processing' => 'Обработка', 'review' => 'Требуется проверка',
            'completed' => 'Каталог опубликован', 'downloading' => 'Загрузка', 'activating' => 'Публикация', 'waiting_mailbox' => 'Ожидает почту', 'waiting_availability' => 'Ожидает проверку источников', 'failed' => 'Ошибка загрузки', 'unchanged' => 'Без изменений', 'skipped' => 'Пропущен',
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

        return ['id' => 'run-'.$run->id, 'import_id' => null, 'title' => $run->supplier->name.' · '.$sourceLabel,
            'label' => $label, 'detail' => $detail,
            'filename' => null, 'source' => $source, 'source_label' => $sourceLabel, 'supplier' => $run->supplier->name,
            'status' => $label === 'Ошибка загрузки' ? 'failed' : $run->status, 'rows' => null, 'date' => $run->created_at->copy()->timezone('Asia/Dushanbe')->format('d.m.Y H:i'),
            'active' => $active && ! in_array($run->status, ['review', 'failed', 'skipped', 'unchanged', 'completed'], true), 'processed' => 0, 'total' => 0,
            'url' => route('admin.supplier-sync.show', [$run->supplier_organization_id, 'tab' => 'history'])];
    }
}
