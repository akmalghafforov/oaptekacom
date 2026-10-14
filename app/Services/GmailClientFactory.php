<?php

namespace App\Services;

use Google\Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Psr\Log\NullLogger;

class GmailClientFactory
{
    public const string SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    public function __construct(private ?ClientInterface $httpClient = null) {}

    public function configured(): bool
    {
        return (bool) (config('services.google.client_id') && config('services.google.client_secret') && config('services.google.redirect_uri'));
    }

    public function make(?string $accessToken = null): Client
    {
        if (! $this->configured()) {
            throw new GmailException('Gmail не настроен. Обратитесь к администратору сервера.');
        }
        if (! app()->runningUnitTests() && (! config('session.block') || config('session.block_lock_seconds') <= (int) ini_get('max_execution_time') || in_array(config('session.driver'), ['cookie', 'array'], true) || config('cache.stores.'.config('session.block_store').'.driver') === 'array' || ! Cache::store(config('session.block_store'))->getStore() instanceof LockProvider)) {
            throw new GmailException('Для Gmail необходимы серверные сессии и общий атомарный кеш блокировок.');
        }
        $client = new Client(['retry' => ['retries' => 0]]);
        $client->setLogger(new NullLogger);
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect_uri'));
        $client->setScopes([self::SCOPE]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setHttpClient($this->httpClient ?? new HttpClient(['connect_timeout' => 3, 'timeout' => 8, 'http_errors' => false]));
        if ($accessToken !== null) {
            $client->setAccessToken(['access_token' => $accessToken, 'expires_in' => 3600, 'created' => time()]);
        }

        return $client;
    }
}
