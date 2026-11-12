<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class IcorpClient
{
    private bool $recovering = false;

    /** @var array<string, array{name: string, catalog: ?string, published_at: ?string}> */
    private array $catalogs = [];

    /** @return list<array{source: string, external_id: string, name: string, phones: list<string>, city?: string, catalog_id: ?string, published_at: ?string}> */
    public function partners(): array
    {
        $xpath = $this->document($this->get('/listpartners'));
        $partners = [];
        $this->catalogs = [];
        foreach ($xpath->query('//article[@data-partner-id]') as $card) {
            $id = $card->getAttribute('data-partner-id');
            $name = $this->text($xpath, './/h3', $card);
            if ($id === '' || $name === '') {
                throw new IcorpFailure('directory', 'iCorp returned an invalid partner card.');
            }
            $phones = [];
            foreach ($xpath->query('.//a[starts-with(@href,"tel:")]', $card) as $link) {
                $phones[] = substr($link->getAttribute('href'), 4);
            }
            $catalog = null;
            foreach ($xpath->query('.//a[contains(@href,"/priceview/")]', $card) as $link) {
                $url = parse_url($link->getAttribute('href'));
                if (isset($url['host']) && $url['host'] !== 'icorp.tech') {
                    throw new IcorpFailure('directory', 'iCorp returned an unexpected price-list host.');
                }
                $catalog = rawurldecode(basename($url['path']));
            }
            $published = $card->getAttribute('data-price-date') ?: $this->text($xpath, './/time/@datetime', $card);
            if ($published === '') {
                preg_match('/(?:Дата прайса|Дата прайс-листа|Дата обновления|Обновл[её]н[оа]?|Прайс от)\s*:?\s*(\d{2}\.\d{2}\.\d{4}(?:\s+\d{2}:\d{2}(?::\d{2})?)?)/iu', $card->textContent, $dateMatch);
                $published = $dateMatch[1] ?? null;
            }
            $this->catalogs[$id] = ['name' => $name, 'catalog' => $catalog, 'published_at' => $published];
            $city = $card->getAttribute('data-city') ?: ($this->text($xpath, './/*[@itemprop="addressLocality"]', $card) ?: null);
            $partners[] = ['source' => 'icorp', 'external_id' => $id, 'name' => $name, 'phones' => array_values(array_unique($phones)), 'catalog_id' => $catalog, 'published_at' => $published, ...($city !== null ? ['city' => $city] : [])];
        }
        if ($partners === []) {
            throw new IcorpFailure('directory', 'No iCorp partner cards found. The session or page format may have changed.');
        }

        return $partners;
    }

    /** @return array{company: array<string, mixed>, items: list<array<string, mixed>>, account_discount: null, unavailable?: bool} */
    public function prices(string $externalId): array
    {
        if ($this->catalogs === []) {
            foreach (app(ExternalSupplierDirectory::class)->availability('icorp') as $entry) {
                $this->catalogs[$entry['external_id']] = ['name' => $entry['name'], 'catalog' => $entry['catalog_id'] ?? null, 'published_at' => $entry['published_at'] ?? null];
            }
        }
        $partner = $this->catalogs[$externalId] ?? null;
        if ($partner === null) {
            throw new IcorpFailure('directory', 'The supplier is not in the authenticated iCorp directory.');
        }
        $company = ['id' => $externalId, 'name' => $partner['name'], 'catalog_id' => $partner['catalog'], 'published_at' => $partner['published_at']];
        if ($partner['catalog'] === null) {
            return ['company' => $company, 'items' => [], 'account_discount' => null, 'unavailable' => true];
        }

        return app(ExternalDownloadLease::class)->run(function (\Closure $heartbeat) use ($externalId, $partner, $company): array {
            $items = [];
            $total = null;
            for ($number = 1; $number <= 1000; $number++) {
                $heartbeat();
                $page = $this->pricePage($externalId, $number, [...$partner, 'received_rows' => count($items)]);
                if ($total !== null && $total !== $page['total']) {
                    throw new IcorpFailure('catalog', 'The iCorp price list changed during extraction.');
                }
                $total = $page['total'];
                foreach ($page['items'] as $item) {
                    if (isset($items[$item['external_id']])) {
                        throw new IcorpFailure('catalog', 'iCorp returned an invalid or repeated price row.');
                    }
                    $items[$item['external_id']] = $item;
                }
                if ($number >= $page['total_pages']) {
                    if (count($items) !== $total) {
                        throw new IcorpFailure('catalog', 'iCorp catalog rows do not match the page summary.');
                    }

                    return ['company' => $company, 'items' => array_values($items), 'account_discount' => null];
                }
            }
            throw new IcorpFailure('catalog', 'iCorp pagination exceeded the extraction limit.');
        });
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    public function pricePage(string $externalId, int $page, array $evidence = []): array
    {
        $partner = ['catalog' => $evidence['catalog'] ?? $evidence['catalog_id'] ?? null, 'name' => $evidence['name'] ?? '', 'published_at' => $evidence['published_at'] ?? null];
        if ($partner['catalog'] === null) {
            return ['company' => [], 'items' => [], 'total' => 0, 'total_pages' => 0, 'account_discount' => null];
        }
        $items = [];
        $xpath = $this->document($this->get('/productsFirmByOverhead', [
            'id' => $partner['catalog'], 'q' => '', 'manufacturer' => '', 'discount_filter' => 'all', 'per_page' => 50, 'page' => $page,
        ]));
        $summary = $this->text($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," catalog-summary ")]');
        if (! preg_match('/Показано\s+(\d+)-(\d+)\s+из\s+(\d+)/u', $summary, $range)) {
            throw new IcorpFailure('catalog', 'iCorp returned an invalid catalog summary.');
        }
        $total = (int) $range[3];
        if ((int) $range[1] !== (int) ($evidence['received_rows'] ?? (($page - 1) * 50)) + 1) {
            throw new IcorpFailure('catalog', 'The iCorp price list changed during extraction.');
        }
        foreach ($xpath->query('//tr[contains(concat(" ",normalize-space(@class)," ")," catalog-cart-source ")]') as $row) {
            $id = $this->value($xpath, 'catalog-prid', $row);
            $firm = base64_decode($this->value($xpath, 'catalog-firm', $row), true);
            $catalog = $this->value($xpath, 'catalog-over', $row);
            $name = $this->classText($xpath, 'catalog-title', $row);
            $price = $this->classText($xpath, 'catalog-price-main', $row);
            $discount = rtrim($this->classText($xpath, 'catalog-discount-badge', $row), '% ');
            $expiry = $this->classText($xpath, 'catalog-col-expire', $row);
            if ($id === '' || $firm !== $externalId || $catalog !== $partner['catalog'] || $name === ''
                || ! is_numeric($price) || (float) $price < 0 || ! is_numeric($discount) || isset($items[$id])) {
                throw new IcorpFailure('catalog', 'iCorp returned an invalid or repeated price row.');
            }
            $items[$id] = [
                'external_id' => $id, 'name' => $name, 'price' => $price,
                'manufacturer' => $this->classText($xpath, 'catalog-subtitle', $row) ?: null,
                'original_price' => $this->classText($xpath, 'catalog-price-old', $row) ?: null,
                'discount_percent' => $discount, 'expiry' => $expiry === '-' ? null : $expiry,
                'quantity' => null,
            ];
        }
        if (count($items) !== (int) $range[2] - (int) $range[1] + 1 || count($items) > 50) {
            throw new IcorpFailure('catalog', 'iCorp catalog rows do not match the page summary.');
        }

        return ['company' => ['id' => $externalId, 'name' => $partner['name'], 'catalog_id' => $partner['catalog'], 'published_at' => $partner['published_at']], 'items' => array_values($items), 'total' => $total, 'total_pages' => (int) $range[2] === $total ? $page : (int) ceil($total / max(1, count($items))), 'account_discount' => null];
    }

    /** @param array<string, int|string> $query */
    private function get(string $path, array $query = []): string
    {
        $disk = Storage::disk('local');
        $sessionPath = app(IcorpCredentials::class)->sessionPath();
        if (! $disk->exists($sessionPath)) {
            app(ExternalServiceControl::class)->pause('icorp', 'authentication');
            throw new IcorpFailure('session', 'session_expired', reason: 'authentication');
        }
        $session = json_decode(Crypt::decryptString($disk->get($sessionPath)), true, 512, JSON_THROW_ON_ERROR);
        $jar = new CookieJar(false, $session['cookies']);
        $response = $this->send(fn () => $this->http($jar, $session['user_agent'])->get('https://icorp.tech'.$path, $query));
        $stage = $path === '/listpartners' ? 'directory' : 'catalog';
        if ($this->requiresLogin($response)) {
            throw new IcorpFailure($stage, 'session_expired', $response->status(), 'authentication');
        }
        $this->checkResponse($response, $stage);
        $this->saveSession($sessionPath, $jar, $session['user_agent']);

        return $response->body();
    }

    public function connect(bool $takeOver = false): void
    {
        $this->recovering = true;
        try {
            $credentials = app(IcorpCredentials::class)->current();
            $firmId = (string) config('external-prices.icorp.firm_id');
            if ($credentials['phone'] === '' || $credentials['password'] === '' || ! ctype_digit($firmId)) {
                throw new IcorpFailure('credentials', 'configuration_missing', reason: 'authentication');
            }
            $sessionPath = app(IcorpCredentials::class)->sessionPath();
            $disk = Storage::disk('local');
            $session = $disk->exists($sessionPath) ? json_decode(Crypt::decryptString($disk->get($sessionPath)), true, 512, JSON_THROW_ON_ERROR) : ['cookies' => [], 'user_agent' => 'Oapteka price-list importer'];
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $jar = new CookieJar(false, $attempt === 0 ? $session['cookies'] : []);
                try {
                    $this->authenticate($jar, $session['user_agent'], $credentials, $firmId, $takeOver);
                    $this->saveSession($sessionPath, $jar, $session['user_agent']);
                    app(ExternalServiceControl::class)->recovered('icorp');

                    return;
                } catch (IcorpFailure $exception) {
                    if ($exception->httpStatus !== 419 || $attempt === 1) {
                        throw $exception;
                    }
                }
            }
        } catch (IcorpFailure $exception) {
            if (in_array($exception->reason, ['authentication', 'permission', 'takeover_required'], true)) {
                app(ExternalServiceControl::class)->pause('icorp', $exception->reason);
            }
            throw $exception;
        } finally {
            $this->recovering = false;
        }
    }

    /** @param array{phone: string, password: string, revision: string} $credentials */
    private function authenticate(CookieJar $jar, string $userAgent, array $credentials, string $firmId, bool $takeOver): void
    {
        $login = $this->send(fn () => $this->http($jar, $userAgent)->get('https://icorp.tech/login'));
        if ($login->successful()) {
            $csrf = $this->csrf($login->body());
            $step = $this->post($jar, $userAgent, '/authorize', '/login', $csrf, ['phone' => $credentials['phone'], '_token' => $csrf]);
            $payload = $this->payload($step);
            if (($payload['result'] ?? null) != 5 || ! str_contains($payload['input'] ?? '', 'value="password"')) {
                throw new IcorpFailure('login', 'password_login_unavailable', reason: 'authentication');
            }
            $csrf = is_string($payload['csrf_token'] ?? null) ? $payload['csrf_token'] : $csrf;
            $authorized = $this->post($jar, $userAgent, '/authorize', '/login', $csrf, ['phone' => $credentials['phone'], '_token' => $csrf, 'auth_mode' => 'password', 'password' => $credentials['password']]);
            if (($this->payload($authorized)['result'] ?? null) != 2) {
                throw new IcorpFailure('login', 'invalid_credentials', reason: 'authentication');
            }
        } elseif (! $login->redirect()) {
            $this->checkResponse($login, 'login');
        }
        $choose = $this->send(fn () => $this->http($jar, $userAgent)->get('https://icorp.tech/choose'));
        $this->checkResponse($choose, 'workspace');
        $xpath = $this->document($choose->body());
        if ($xpath->query('//button[@data-firm-id="'.$firmId.'"]')->length !== 1) {
            throw new IcorpFailure('workspace', 'workspace_unavailable', reason: 'permission');
        }
        $entry = $this->post($jar, $userAgent, '/firm-entry', '/choose', $this->csrf($choose->body()), ['firm_id' => $firmId, 'force_firm_session' => $takeOver ? 1 : 0]);
        $payload = $this->payload($entry);
        if (($payload['status'] ?? $payload['success'] ?? false) !== true) {
            throw new IcorpFailure('workspace', 'entry_rejected', $entry->status(), 'permission');
        }
        $directory = $this->send(fn () => $this->http($jar, $userAgent)->get('https://icorp.tech/listpartners'));
        $this->checkResponse($directory, 'directory');
        if ($this->requiresLogin($directory)) {
            throw new IcorpFailure('directory', 'session_expired', $directory->status(), 'authentication');
        }
        if ($this->document($directory->body())->query('//article[@data-partner-id]')->length === 0) {
            throw new IcorpFailure('directory', 'directory_missing');
        }
    }

    /** @param array<string, int|string> $data */
    private function post(CookieJar $jar, string $userAgent, string $path, string $referer, string $csrf, array $data): Response
    {
        $response = $this->send(fn () => $this->http($jar, $userAgent)->asForm()->acceptJson()->withHeaders([
            'X-CSRF-TOKEN' => $csrf, 'X-Requested-With' => 'XMLHttpRequest',
            'Origin' => 'https://icorp.tech', 'Referer' => 'https://icorp.tech'.$referer,
        ])->post('https://icorp.tech'.$path, $data));
        $this->checkResponse($response, $path === '/authorize' ? 'login' : 'workspace');

        return $response;
    }

    private function checkResponse(Response $response, string $stage): void
    {
        if (! $response->successful()) {
            $status = $response->status();
            $reason = match (true) {
                in_array($status, [301, 302, 303, 307, 308, 401, 419], true) => 'authentication',
                $status === 409 => 'takeover_required',
                $status === 403 => 'permission',
                $status === 429 || $status >= 500 => 'transport',
                default => 'extraction',
            };
            throw new IcorpFailure($stage, $status === 409 ? 'another device' : 'http_error', $status, $reason);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $payload = $response->json();
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (! is_array($payload)) {
            throw new IcorpFailure('login', 'invalid_auth_response', $response->status(), 'authentication');
        }

        return $payload;
    }

    private function requiresLogin(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308, 401, 419], true)
            || $this->document($response->body())->query('//form[contains(@action,"/authorize")] | //input[@name="auth_mode"]')->length > 0;
    }

    private function saveSession(string $path, CookieJar $jar, string $userAgent): void
    {
        if (! Storage::disk('local')->put($path, Crypt::encryptString(json_encode(['cookies' => $jar->toArray(), 'user_agent' => $userAgent], JSON_THROW_ON_ERROR)))) {
            throw new IcorpFailure('session', 'session_save_failed');
        }
    }

    private function csrf(string $html): string
    {
        $node = $this->document($html)->query('//meta[@name="csrf-token"]')->item(0);
        if (! $node instanceof DOMElement || $node->getAttribute('content') === '') {
            throw new IcorpFailure('login', 'csrf_token_missing', reason: 'authentication');
        }

        return $node->getAttribute('content');
    }

    private function send(\Closure $request): Response
    {
        return app(ExternalServiceControl::class)->request('icorp', $request, $this->recovering);
    }

    private function http(CookieJar $jar, string $userAgent): PendingRequest
    {
        return Http::connectTimeout(10)->timeout(40)->withoutRedirecting()->withUserAgent($userAgent)->withOptions(['cookies' => $jar]);
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.($html !== '' ? $html : '<html></html>'), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function classText(DOMXPath $xpath, string $class, DOMNode $context): string
    {
        return $this->text($xpath, './/*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]', $context);
    }

    private function value(DOMXPath $xpath, string $class, DOMNode $context): string
    {
        $node = $xpath->query('.//input[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]', $context)->item(0);

        return $node instanceof DOMElement ? $node->getAttribute('value') : '';
    }

    private function text(DOMXPath $xpath, string $query, ?DOMNode $context = null): string
    {
        return trim($xpath->query($query, $context)->item(0)?->textContent ?? '');
    }
}
