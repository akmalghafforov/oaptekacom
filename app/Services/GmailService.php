<?php

namespace App\Services;

use App\Models\GmailMessage;
use App\Models\User;
use Closure;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Sleep;
use Throwable;

class GmailService
{
    public function __construct(private GmailClientFactory $clients) {}

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

    /** @return array{messages: list<array>, next: ?string, searching: bool} */
    public function inbox(User $user, int $size = 20, ?string $page = null, ?string $filter = null): array
    {
        if ($size < 1 || $size > 50) {
            throw new GmailException('Размер страницы должен быть от 1 до 50.');
        }
        abort_unless($user->isAdminMode(), 403);
        $connection = $user->gmailConnection()->first();
        if (! $connection) {
            throw new GmailException('Подключите Gmail заново.');
        }
        Gate::forUser($user)->authorize('view', $connection);
        $binding = [$user->id, strtolower($connection->email), $filter, $size];
        $boundary = null;
        if ($page !== null) {
            try {
                $cursor = json_decode(Crypt::decryptString($page), true, flags: JSON_THROW_ON_ERROR);
                if ($cursor['binding'] !== $binding || ! is_array($cursor['boundary'])) {
                    throw new \RuntimeException;
                }
                $boundary = $cursor['boundary'];
            } catch (Throwable) {
                throw new GmailException('Страница устарела. Откройте первую страницу.');
            }
        }
        $messages = [];
        $last = null;
        $next = null;
        $status = app(GmailImportStatus::class);
        do {
            $query = GmailMessage::where('user_id', $user->id)->where('mailbox_email', strtolower($connection->email));
            if ($boundary) {
                $query->where(fn ($query) => $query->where('received_at', '<', $boundary[0])->orWhere(fn ($query) => $query->where('received_at', $boundary[0])->where('id', '<', $boundary[1])));
            }
            $batch = $query->select(['id', 'gmail_message_id', 'received_at', 'sender', 'subject', 'snippet', 'unread', 'attachment_manifest'])->orderByDesc('received_at')->orderByDesc('id')->limit(100)->get();
            $decorated = $status->decorate($connection->email, $batch->map(fn ($saved): array => $saved->snapshot())->all())['messages'];
            foreach ($decorated as $index => $message) {
                $saved = $batch[$index];
                $boundary = [$saved->getRawOriginal('received_at'), $saved->id];
                if ($filter !== null && ! collect($message['attachments'])->contains(fn (array $attachment): bool => $attachment['outcome']['category'] === $filter)) {
                    continue;
                }
                if (count($messages) === $size) {
                    $next = Crypt::encryptString(json_encode(['binding' => $binding, 'boundary' => $last], JSON_THROW_ON_ERROR));
                    break 2;
                }
                $messages[] = $message;
                $last = $boundary;
            }
        } while ($batch->count() === 100);

        return ['messages' => $messages, 'next' => $next, 'searching' => false];
    }

    public function message(User $user, string $id): array
    {
        abort_unless($user->isAdminMode(), 403);
        $connection = $user->gmailConnection()->first();
        if (! $connection) {
            throw new GmailException('Подключите Gmail заново.');
        }
        Gate::forUser($user)->authorize('view', $connection);
        $saved = GmailMessage::where('user_id', $user->id)->where('mailbox_email', strtolower($connection->email))->where('gmail_message_id', $id)->first();
        if (! $saved) {
            throw new GmailException('Письмо больше недоступно.');
        }

        return $saved->snapshot(true);
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
