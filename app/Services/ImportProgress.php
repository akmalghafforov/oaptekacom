<?php

namespace App\Services;

use App\Models\PriceListImport;
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
            $reason !== null => 'Требуется проверка даты или настроек источника.',
            $status === 'processing' => 'Обработано строк: '.$processed.' / '.$import->total_rows,
            $status === 'committing' => 'Публикация проверенных товаров в каталоге.',
            $status === 'completed' => 'Каталог опубликован.',
            $status === 'pending' => 'Файл получен. Ожидается обработка.',
            default => $import->status->label(),
        };

        return ['id' => 'import-'.$import->id, 'import_id' => $import->id, 'title' => $import->supplier->name.' · '.$import->source_type->label().' · №'.$import->id,
            'label' => $import->status->label(), 'detail' => $detail, 'active' => in_array($status, ['pending', 'processing', 'committing'], true) || ($status === 'preview' && $reason === null),
            'processed' => $processed, 'total' => (int) $import->total_rows, 'url' => route('price-list-imports.show', $import)];
    }

    /** @return array<string, mixed> */
    public function run(SupplierSyncRun $run): array
    {
        $source = $run->configuration_snapshot['selected_source'] ?? $run->attempts->first()?->source;
        $sourceLabel = $source === 'icorp' ? 'iCorp' : ($source === 'pricelist_tj' ? 'PriceList.tj' : 'Внешний источник');
        $active = $run->active_supplier_id !== null;
        $label = match ($run->status) {
            'queued' => 'В очереди на загрузку', 'processing' => 'Обработка', 'review' => 'Требуется проверка',
            'completed' => 'Завершён', 'failed' => 'Ошибка загрузки', 'unchanged' => 'Без изменений', 'skipped' => 'Пропущен',
            'waiting_import' => 'Ожидает завершения другого импорта', default => 'Загрузка из сервиса',
        };

        if ($active && $run->attempts->contains('status', 'fetching')) {
            $label = 'Загрузка из сервиса';
        } elseif (! $active && $run->attempts->contains('status', 'failed')) {
            $label = 'Ошибка загрузки';
        }

        return ['id' => 'run-'.$run->id, 'import_id' => null, 'title' => $run->supplier->name.' · '.$sourceLabel,
            'label' => $label, 'detail' => $active ? 'После получения файла здесь появится обработка прайс-листа.' : 'Активный каталог сохранён. Подробности доступны в истории синхронизации.',
            'active' => $active && ! in_array($run->status, ['review', 'failed', 'skipped', 'unchanged', 'completed'], true), 'processed' => 0, 'total' => 0,
            'url' => route('admin.supplier-sync.show', [$run->supplier_organization_id, 'tab' => 'history'])];
    }
}
