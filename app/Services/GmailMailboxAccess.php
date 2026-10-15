<?php

namespace App\Services;

use App\Models\GmailConnection;
use Closure;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use Illuminate\Support\Facades\Cache;

class GmailMailboxAccess
{
    public function __construct(private GmailClientFactory $clients) {}

    public function connection(): GmailConnection
    {
        $connection = GmailConnection::find(config('gmail-imports.connection_id'));
        if (! $connection || $connection->status !== 'connected' || ! $connection->user?->isAdmin() || $connection->user->is_blocked || ! config('gmail-imports.mailbox') || strcasecmp($connection->email, config('gmail-imports.mailbox')) !== 0) {
            throw new GmailException('mailbox_unavailable');
        }

        return $connection;
    }

    public function run(Closure $operation): mixed
    {
        $connection = $this->connection();

        return Cache::store(config('session.block_store'))->lock('gmail-owner:'.$connection->user_id, 300)->block(10, function () use ($operation): mixed {
            $connection = $this->connection();
            if (! $connection->expires_at || $connection->expires_at->lessThan(now()->addMinute())) {
                $this->refresh($connection);
            }
            try {
                return $operation(new Gmail($this->clients->make($connection->access_token)), $connection);
            } catch (GoogleException $exception) {
                if ($exception->getCode() !== 401) {
                    throw $exception;
                }
                $this->refresh($connection);
                try {
                    return $operation(new Gmail($this->clients->make($connection->access_token)), $connection);
                } catch (GoogleException $exception) {
                    if ($exception->getCode() === 401) {
                        $this->revoke($connection);
                    }
                    throw $exception;
                }
            }
        });
    }

    public function refresh(GmailConnection $connection): void
    {
        if (! $connection->refresh_token) {
            $this->revoke($connection);
        }
        $token = $this->clients->make()->fetchAccessTokenWithRefreshToken($connection->refresh_token);
        if (($token['error'] ?? null) === 'invalid_grant') {
            $this->revoke($connection);
        }
        if (isset($token['error']) || empty($token['access_token']) || (int) ($token['expires_in'] ?? 0) <= 0) {
            throw new GmailException('token_refresh_failed');
        }
        $connection->update(['access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token, 'expires_at' => now()->addSeconds((int) $token['expires_in'])]);
    }

    public function revoke(GmailConnection $connection): never
    {
        $connection->update(['access_token' => null, 'refresh_token' => null, 'status' => 'reconnect_required', 'reconnect_required_at' => now()]);
        throw new GmailException('Доступ Gmail отозван. Подключите аккаунт заново.');
    }
}
