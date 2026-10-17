<?php

namespace App\Services;

use App\Jobs\ProcessGmailImportReceipt;
use App\Models\GmailImportReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GmailImportReplay
{
    public function prepare(GmailImportReceipt $receipt): bool
    {
        $lock = Cache::store(config('session.block_store'))->lock('gmail-receipt:'.$receipt->id, 120);
        if (! $lock->get()) {
            return false;
        }
        try {
            return DB::transaction(function () use ($receipt): bool {
                $receipt = GmailImportReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                if ($receipt->price_list_import_id || $receipt->next_attempt_at?->isFuture() || ! in_array($receipt->status, ['skipped', 'failed', 'routed'], true)) {
                    return false;
                }
                $receipt->update(['status' => 'pending', 'reason' => null, 'attempts' => 0, 'next_attempt_at' => null]);

                return true;
            });
        } finally {
            $lock->release();
        }
    }

    public function message(string $mailbox, string $message): void
    {
        $receipt = GmailImportReceipt::firstOrCreate(['mailbox_email' => mb_strtolower($mailbox), 'gmail_message_id' => $message, 'receipt_scope' => 'message']);
        if ($receipt->wasRecentlyCreated || $this->prepare($receipt)) {
            try {
                ProcessGmailImportReceipt::dispatch($receipt->id)->afterCommit();
            } catch (\Throwable $exception) {
                report($exception);
                throw new GmailException('Не удалось отправить задание в очередь. Запись сохранена для автоматического восстановления; обновите статус позже.');
            }
        }
    }
}
