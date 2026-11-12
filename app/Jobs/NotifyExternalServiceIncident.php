<?php

namespace App\Jobs;

use App\Models\ExternalServiceIncident;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotifyExternalServiceIncident implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [300, 600, 1200];

    public function __construct(public readonly int $incidentId) {}

    public function handle(): void
    {
        $lock = Cache::lock('external-incident-mail:'.$this->incidentId, 90);
        if (! $lock->get()) {
            return;
        }
        try {
            $incident = ExternalServiceIncident::findOrFail($this->incidentId);
            if ($incident->notified_at) {
                return;
            }
            $incident->update(['notification_attempted_at' => now()]);
            $delivered = $incident->delivered_to ?? [];
            $reason = match ($incident->reason) {
                'authentication' => 'требуется авторизация', 'permission' => 'нет доступа к прайс-листам',
                'challenge' => 'требуется проверка доступа или CAPTCHA', 'takeover_required' => 'аккаунт занят на другом устройстве',
                'rate_limited' => 'сервис ограничил частоту запросов', 'retry_exhausted' => 'повторы после ошибок соединения исчерпаны',
                default => 'ошибка чтения данных',
            };
            foreach (User::where('role', 'admin')->where('is_blocked', false)->whereNotNull('email')->get() as $user) {
                if (! $user->isAdmin() || $user->email === '' || in_array(strtolower($user->email), $delivered, true)) {
                    continue;
                }
                Mail::raw('Сервис '.$incident->source.' требует проверки. Причина: '.$reason.'. Откройте мониторинг синхронизации поставщиков. Восстановление подключения выполняется администратором.', function ($message) use ($user): void {
                    $message->to($user->email)->subject('Требуется проверка загрузки прайс-листов');
                });
                $delivered[] = strtolower($user->email);
                $incident->update(['delivered_to' => $delivered]);
            }
            $incident->update($delivered === [] ? ['notification_error' => 'no_recipients'] : ['notified_at' => now(), 'notification_error' => null]);
        } catch (Throwable $exception) {
            ExternalServiceIncident::whereKey($this->incidentId)->update(['notification_error' => 'delivery_failed']);
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
