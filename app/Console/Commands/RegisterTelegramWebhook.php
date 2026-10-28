<?php

namespace App\Console\Commands;

use App\Services\TelegramSupplierReviews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterTelegramWebhook extends Command
{
    protected $signature = 'telegram:register-webhook';

    protected $description = 'Register the configured HTTPS Telegram supplier review webhook';

    public function handle(TelegramSupplierReviews $reviews): int
    {
        $url = route('telegram.webhook');
        if (! $reviews->configured() || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->error('Valid Telegram configuration and an HTTPS APP_URL are required.');

            return self::FAILURE;
        }
        try {
            $response = Http::connectTimeout(5)->timeout(20)->post('https://api.telegram.org/bot'.config('telegram.bot_token').'/setWebhook', [
                'url' => $url, 'secret_token' => config('telegram.webhook_secret'), 'allowed_updates' => ['callback_query'],
            ]);
            if ($response->successful() && $response->json('ok') === true) {
                $this->info('Webhook registered. Run migrations, queue workers and the scheduler before enabling reviews.');

                return self::SUCCESS;
            }
        } catch (\Throwable) {
            // Do not expose HTTP exception URLs containing bot credentials.
        }
        $this->error('Telegram webhook registration failed.');

        return self::FAILURE;
    }
}
