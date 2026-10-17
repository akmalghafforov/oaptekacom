<?php

namespace App\Services;

use App\Models\GmailImportReceipt;
use App\Models\PriceListImport;
use Illuminate\Support\Collection;

class GmailImportStatus
{
    public function receipts(string $mailbox, array $messageIds): Collection
    {
        return GmailImportReceipt::where('mailbox_email', mb_strtolower($mailbox))->whereIn('gmail_message_id', $messageIds)->orderBy('id')->get();
    }

    /** @return array{messages: array, active: bool} */
    public function decorate(string $mailbox, array $messages): array
    {
        $receipts = $this->receipts($mailbox, array_column($messages, 'id'));
        $imports = PriceListImport::whereIn('id', $receipts->pluck('price_list_import_id')->filter()->unique())->get()->keyBy('id');
        $active = false;
        foreach ($messages as &$message) {
            $scoped = $receipts->where('gmail_message_id', $message['id']);
            $parent = $scoped->firstWhere('receipt_scope', 'message');
            $message['intake_reason'] = $this->reason($parent?->reason);
            if ($scoped->contains(fn ($receipt): bool => $receipt->status === 'pending' && ! $receipt->price_list_import_id)) {
                $active = true;
            }
            foreach ($message['attachments'] as &$attachment) {
                $status = 'skipped';
                $reason = null;
                $import = null;
                $duplicate = false;
                foreach ($scoped as $receipt) {
                    $selected = isset($receipt->selected_part['part_id']) && (string) $receipt->selected_part['part_id'] === $attachment['part_id'];
                    $entry = collect($receipt->attachment_manifest ?? [])->first(fn ($entry): bool => (string) ($entry['part_id'] ?? '') === $attachment['part_id']);
                    if ($selected) {
                        $status = $receipt->status;
                        $reason = $receipt->reason;
                        $import = $imports->get($receipt->price_list_import_id);
                        $duplicate = $receipt->status === 'duplicate';
                        if ($import) {
                            $status = $import->status->value;
                        }
                        break;
                    }
                    if ($entry && ! $import) {
                        if ($receipt->receipt_scope === 'message') {
                            $reason = $entry['reason'];
                            $status = $reason ? 'skipped' : ($receipt->status === 'pending' ? 'pending' : 'skipped');
                        } elseif (in_array($receipt->supplier_organization_id, $entry['supplier_ids'] ?? [], true) && ! $entry['reason'] && ! $receipt->price_list_import_id) {
                            $status = $receipt->status;
                            $reason = $receipt->reason;
                        }
                    }
                }
                $label = match ($status) {
                    'pending', 'awaiting_duplicate_confirmation' => 'В очереди',
                    'processing', 'preview', 'committing', 'accepted' => 'Обрабатывается',
                    'completed', 'superseded' => 'Импортировано',
                    'failed' => 'Ошибка',
                    default => 'Не импортировано',
                };
                $attachment['outcome'] = ['status' => $status, 'label' => $label, 'reason' => $import && $status === 'failed' ? 'Откройте прайс-лист, проверьте причину ошибки и повторите обработку.' : $this->reason($reason), 'duplicate' => $duplicate, 'import_id' => $import?->id, 'url' => $import ? route('price-list-imports.show', $import) : null];
                $active = $active || in_array($label, ['В очереди', 'Обрабатывается'], true);
            }
            unset($attachment);
        }
        unset($message);

        return compact('messages', 'active');
    }

    public function reason(?string $reason): ?string
    {
        return match ($reason) {
            null => null,
            'unknown_supplier' => 'Поставщик не определён. Настройте профиль.',
            'ambiguous_supplier' => 'Подходят несколько поставщиков. Уточните адреса и фильтры файлов.',
            'multiple_candidates' => 'Несколько подходящих вложений для одного поставщика. Уточните фильтр имени файла.',
            'format_mismatch', 'no_candidates' => 'Тип файла не соответствует профилю.',
            'missing_profile', 'inactive_profile', 'inactive_supplier', 'profile_changed' => 'Проверьте активность поставщика и профиль импорта.',
            'invalid_attachment' => 'Файл повреждён, имеет неподдерживаемый формат или превышает ограничения.',
            'attachment_unavailable', 'message_unavailable', 'not_in_inbox' => 'Письмо или вложение недоступно во входящих.',
            'reconnect_required', 'mailbox_changed', 'permission_denied' => 'Проверьте подключение и доступ к настроенному ящику.',
            'intake_failed' => 'Не удалось обработать вложение. Повторите импорт.',
            'supplier_resolution_changed', 'replay_requested' => 'Маршрутизация изменилась. Повторите импорт.',
            'duplicate_content', 'duplicate_message' => 'Используется существующий импорт.',
            default => 'Вложение пропущено. Проверьте профиль и повторите импорт.',
        };
    }
}
