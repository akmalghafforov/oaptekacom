<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Jobs\DeliverSupplierReview;
use App\Models\Organization;
use App\Models\SupplierReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramSupplierReviews
{
    public function configured(): bool
    {
        $mapping = config('telegram.admin_mapping');
        if (! config('telegram.enabled') || ! preg_match('/^\d+:[A-Za-z0-9_-]+$/D', (string) config('telegram.bot_token'))
            || ! preg_match('/^[A-Za-z0-9_-]{1,256}$/D', (string) config('telegram.webhook_secret'))
            || ! preg_match('/^-\d+$/D', (string) config('telegram.group_chat_id')) || ! is_array($mapping) || $mapping === []) {
            return false;
        }
        foreach ($mapping as $telegramId => $adminId) {
            if (! preg_match('/^[1-9]\d*$/D', (string) $telegramId) || ! preg_match('/^[1-9]\d*$/D', (string) $adminId)) {
                return false;
            }
        }

        return true;
    }

    public function create(Organization $organization): SupplierReview
    {
        $review = SupplierReview::firstOrCreate(['organization_id' => $organization->id], ['token' => bin2hex(random_bytes(16))]);
        $this->dispatch($review);

        return $review;
    }

    public function dispatch(SupplierReview $review): void
    {
        if ($this->configured()) {
            DB::afterCommit(function () use ($review): void {
                try {
                    DeliverSupplierReview::dispatch($review->id);
                } catch (\Throwable) {
                    Log::warning('Supplier review dispatch failed', ['review_id' => $review->id]);
                }
            });
        }
    }

    /** Must be called while holding the organization row lock, inside its transaction. */
    public function complete(Organization $organization, string $decision, User $actor, ?string $telegramId = null): void
    {
        $review = SupplierReview::where('organization_id', $organization->id)->lockForUpdate()->first();
        if (! $review) {
            return;
        }
        if (! $review->decision) {
            $review->fill(['decision' => $decision, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'telegram_user_id' => $telegramId, 'decided_at' => now()]);
        }
        $review->version++;
        if ($review->message_id) {
            $review->delivery_state = 'sent';
            $review->attempts = 0;
            $review->next_attempt_at = null;
        }
        $review->save();
        $this->dispatch($review);
    }

    /** @param array<string, mixed> $callback */
    public function decide(array $callback): string
    {
        $data = $callback['data'] ?? null;
        $telegramId = $callback['from']['id'] ?? null;
        if (! is_string($data) || ! preg_match('/^supplier:(approve|reject):([a-f0-9]{32})$/D', $data, $matches)
            || (! is_int($telegramId) && ! is_string($telegramId))) {
            return 'Некорректная заявка.';
        }
        $adminId = config('telegram.admin_mapping')[(string) $telegramId] ?? null;
        $review = SupplierReview::where('token', $matches[2])->first();
        if (! $adminId || ! $review) {
            return 'Нет доступа или заявка недействительна.';
        }

        return DB::transaction(function () use ($review, $adminId, $telegramId, $callback, $matches): string {
            $organization = Organization::whereKey($review->organization_id)->lockForUpdate()->firstOrFail();
            $review = SupplierReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            $admin = User::whereKey($adminId)->lockForUpdate()->first();
            if (! $admin || ! $admin->isAdmin() || $admin->is_blocked) {
                return 'Нет доступа.';
            }
            $chat = $callback['message']['chat']['id'] ?? null;
            $message = $callback['message']['message_id'] ?? null;
            if ($organization->type !== OrganizationType::Wholesaler || ! $review->message_id
                || ! is_scalar($chat) || ! is_scalar($message)
                || (string) $chat !== $review->chat_id || (string) $chat !== (string) config('telegram.group_chat_id')
                || (string) $message !== $review->message_id) {
                return 'Некорректная заявка.';
            }
            if ($review->decision || $organization->status !== 'pending') {
                return 'Заявка уже рассмотрена. Статус: '.$organization->status;
            }
            if ($matches[1] === 'approve') {
                app(OrganizationApprovalService::class)->approve($organization, $admin, (string) $telegramId);
            } else {
                $organization->update(['status' => 'blocked']);
                $organization->users()->where('role', UserRole::Wholesaler)->update(['is_blocked' => true]);
                app(AuditLogger::class)->log('organization.rejected', $organization, ['status' => 'pending'], ['status' => 'blocked', 'telegram_user_id' => (string) $telegramId], $admin);
                $this->complete($organization, 'rejected', $admin, (string) $telegramId);
            }

            return 'Решение сохранено.';
        });
    }

    /** @return array<string, mixed> */
    public function message(SupplierReview $review): array
    {
        $organization = $review->organization;
        $verified = $organization->assignedUsers()->whereNotNull('phone_verified_at')->exists();
        $lines = [
            'Заявка поставщика #'.$organization->id,
            'Компания: '.$organization->name,
            'Телефон: '.$organization->phone,
            'Регистрация: '.$organization->created_at->toIso8601String(),
            'Телефон подтверждён: '.($verified ? 'да' : 'нет'),
            'Профиль заполнен: '.($organization->setup_required ? 'нет' : 'да'),
            'Город: '.$organization->city,
            'Контакт: '.$organization->contact_name,
            'Email: '.$organization->contact_email,
            'WhatsApp: '.$organization->whatsapp_phone,
            'Доп. телефоны: '.implode(', ', $organization->additional_phones ?? []),
            'Текущий статус: '.$organization->status,
        ];
        if ($review->decision) {
            $lines[] = 'Решение: '.$review->decision;
            $lines[] = 'Администратор: '.$review->actor_name;
            $lines[] = $review->telegram_user_id ? 'Telegram ID: '.$review->telegram_user_id : 'Источник решения: веб';
            $lines[] = 'Время решения: '.$review->decided_at->toIso8601String();
        }
        $buttons = [];
        if (! $review->decision && $organization->status === 'pending') {
            $buttons = [[['text' => 'Approve', 'callback_data' => 'supplier:approve:'.$review->token], ['text' => 'Reject', 'callback_data' => 'supplier:reject:'.$review->token]]];
        }

        return ['text' => implode("\n", array_map(fn (string $line): string => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $line), 0, 240), $lines)), 'reply_markup' => ['inline_keyboard' => $buttons]];
    }
}
