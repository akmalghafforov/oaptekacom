<?php

namespace App\Services;

use App\Models\GmailConnection;
use App\Models\User;
use Closure;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Sleep;
use Throwable;

class GmailService
{
    public function __construct(private GmailClientFactory $clients, private GmailMessageReader $reader) {}

    private function locked(User $user, Closure $operation): mixed
    {
        abort_unless($user->isAdminMode(), 403);
        try {
            return Cache::store(config('session.block_store'))->lock('gmail-owner:'.$user->id, 300)->block(10, $operation);
        } catch (GmailException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GmailException;
        }
    }

    public function connect(User $user, string $code): void
    {
        $this->locked($user, function () use ($user, $code): void {
            $client = $this->clients->make();
            $token = $client->fetchAccessTokenWithAuthCode($code);
            $this->validateToken($token);
            $scopes = preg_split('/\s+/', trim($token['scope'] ?? ''));
            if (! in_array(GmailClientFactory::SCOPE, $scopes, true)) {
                throw new GmailException('Разрешение на чтение Gmail не предоставлено.');
            }
            $gmail = new Gmail($this->clients->make($token['access_token']));
            $email = $this->read(fn () => $gmail->users->getProfile('me'))->getEmailAddress();
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new GmailException;
            }
            $existing = $user->gmailConnection()->first();
            $refresh = $token['refresh_token'] ?? ($existing && strcasecmp($existing->email, $email) === 0 ? $existing->refresh_token : null);
            if (! is_string($refresh) || $refresh === '') {
                throw new GmailException('Google не выдал постоянный доступ. Повторите подключение с подтверждением разрешений.');
            }
            $user->gmailConnection()->updateOrCreate([], ['email' => $email, 'access_token' => $token['access_token'], 'refresh_token' => $refresh, 'expires_at' => now()->addSeconds((int) $token['expires_in']), 'scopes' => $scopes, 'status' => 'connected', 'connected_at' => now(), 'reconnect_required_at' => null]);
        });
    }

    public function disconnect(User $user): bool
    {
        return $this->locked($user, function () use ($user): bool {
            $connection = $user->gmailConnection()->first();
            try {
                $token = $connection?->refresh_token ?? $connection?->access_token;
            } catch (Throwable) {
                $connection?->delete();

                return false;
            }
            $connection?->delete();
            if (! $token) {
                return true;
            }
            try {
                return $this->clients->make()->revokeToken($token);
            } catch (Throwable) {
                return false;
            }
        });
    }

    /** @return array{messages: list<array>, next: ?string} */
    public function inbox(User $user, int $size = 20, ?string $page = null): array
    {
        if ($size < 1 || $size > 50) {
            throw new GmailException('Размер страницы должен быть от 1 до 50.');
        }

        return $this->mailbox($user, function (Gmail $gmail) use ($size, $page): array {
            try {
                $list = $this->read(fn () => $gmail->users_messages->listUsersMessages('me', array_filter(['labelIds' => ['INBOX'], 'maxResults' => $size, 'pageToken' => $page], fn ($value) => $value !== null)));
            } catch (GoogleException $exception) {
                if ($page !== null && $exception->getCode() === 400) {
                    throw new GmailException('Страница устарела. Откройте первую страницу.');
                }
                throw $exception;
            }
            $pending = array_map(fn (Message $message): string => $message->getId(), array_slice($list->getMessages() ?? [], 0, $size));
            $pageIds = $pending;
            $messages = [];
            for ($attempt = 0; $pending && $attempt < 3; $attempt++) {
                $client = $gmail->getClient();
                $client->setUseBatch(true);
                try {
                    $batch = $gmail->createBatch();
                    foreach ($pending as $id) {
                        $batch->add($gmail->users_messages->get('me', $id, ['format' => 'metadata', 'metadataHeaders' => ['From', 'Subject', 'Date']]), $id);
                    }
                    $results = $batch->execute();
                } catch (Throwable $exception) {
                    if ($attempt >= 2 || ! $this->retryable($exception)) {
                        throw $exception;
                    }
                    Sleep::usleep(100000 * ($attempt + 1));

                    continue;
                } finally {
                    $client->setUseBatch(false);
                }
                $retry = [];
                foreach ($pending as $id) {
                    $result = $results['response-'.$id] ?? null;
                    if ($result instanceof Message) {
                        $messages[$id] = $this->reader->metadata($result);
                    } elseif ($result instanceof GoogleException && $result->getCode() === 404) {
                        continue;
                    } elseif ($result instanceof Throwable && $this->retryable($result) && $attempt < 2) {
                        $retry[] = $id;
                    } elseif ($result instanceof Throwable) {
                        throw $result;
                    } else {
                        throw new GmailException;
                    }
                }
                $pending = $retry;
                if ($pending) {
                    Sleep::usleep(100000 * ($attempt + 1));
                }
            }

            return ['messages' => array_values(array_filter(array_map(fn (string $id): ?array => $messages[$id] ?? null, $pageIds))), 'next' => $list->getNextPageToken()];
        });
    }

    public function message(User $user, string $id): array
    {
        return $this->mailbox($user, function (Gmail $gmail) use ($id): array {
            try {
                $message = $this->read(fn () => $gmail->users_messages->get('me', $id, ['format' => 'full']));
            } catch (GoogleException $exception) {
                if ($exception->getCode() === 404) {
                    throw new GmailException('Письмо больше недоступно.');
                }
                throw $exception;
            }

            return $this->reader->read($message, fn (string $attachment): string => $this->read(fn () => $gmail->users_messages_attachments->get('me', $id, $attachment))->getData() ?? '');
        });
    }

    private function mailbox(User $user, Closure $operation): mixed
    {
        return $this->locked($user, function () use ($user, $operation): mixed {
            $connection = $user->gmailConnection()->first();
            if (! $connection || $connection->status !== 'connected') {
                throw new GmailException('Подключите Gmail заново.');
            }
            Gate::forUser($user)->authorize('view', $connection);
            try {
                if (! $connection->expires_at || $connection->expires_at->lessThan(now()->addMinute())) {
                    app(GmailMailboxAccess::class)->refresh($connection);
                }

                return $operation(new Gmail($this->clients->make($connection->access_token)));
            } catch (GoogleException $exception) {
                if ($exception->getCode() === 401) {
                    app(GmailMailboxAccess::class)->refresh($connection);
                    try {
                        return $operation(new Gmail($this->clients->make($connection->access_token)));
                    } catch (GoogleException $retry) {
                        if ($retry->getCode() === 401) {
                            $this->revoked($connection);
                        }
                    }
                }
                throw new GmailException;
            }
        });
    }

    private function revoked(GmailConnection $connection): never
    {
        $connection->update(['access_token' => null, 'refresh_token' => null, 'status' => 'reconnect_required', 'reconnect_required_at' => now()]);
        throw new GmailException('Доступ Gmail отозван. Подключите аккаунт заново.');
    }

    /** @param array{access_token?: string, refresh_token?: string, expires_in?: int, scope?: string, error?: string} $token */
    private function validateToken(array $token): void
    {
        if (isset($token['error']) || ! is_string($token['access_token'] ?? null) || $token['access_token'] === '' || (int) ($token['expires_in'] ?? 0) <= 0) {
            throw new GmailException('Не удалось получить доступ Gmail. Повторите подключение.');
        }
    }

    private function read(Closure $operation): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                if ($attempt >= 2 || ! $this->retryable($exception)) {
                    throw $exception;
                }
                Sleep::usleep(100000 * ($attempt + 1));
            }
        }
    }

    private function retryable(Throwable $exception): bool
    {
        if ($exception instanceof TransferException) {
            return true;
        }
        if (! $exception instanceof GoogleException) {
            return false;
        }
        $reasons = array_column($exception->getErrors() ?? [], 'reason');

        return in_array($exception->getCode(), [429, 500, 502, 503, 504], true) || ($exception->getCode() === 403 && array_intersect($reasons, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError']));
    }
}
