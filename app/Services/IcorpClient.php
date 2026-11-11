<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class IcorpClient
{
    /** @var array<string, array{name: string, catalog: ?string}> */
    private array $catalogs = [];

    /** @return list<array{source: string, external_id: string, name: string, phones: list<string>}> */
    public function partners(): array
    {
        $xpath = $this->document($this->get('/listpartners'));
        $partners = [];
        $this->catalogs = [];
        foreach ($xpath->query('//article[@data-partner-id]') as $card) {
            $id = $card->getAttribute('data-partner-id');
            $name = $this->text($xpath, './/h3', $card);
            if ($id === '' || $name === '') {
                throw new RuntimeException('iCorp returned an invalid partner card.');
            }
            $phones = [];
            foreach ($xpath->query('.//a[starts-with(@href,"tel:")]', $card) as $link) {
                $phones[] = substr($link->getAttribute('href'), 4);
            }
            $catalog = null;
            foreach ($xpath->query('.//a[contains(@href,"/priceview/")]', $card) as $link) {
                $url = parse_url($link->getAttribute('href'));
                if (isset($url['host']) && $url['host'] !== 'icorp.tech') {
                    throw new RuntimeException('iCorp returned an unexpected price-list host.');
                }
                $catalog = rawurldecode(basename($url['path']));
            }
            $this->catalogs[$id] = ['name' => $name, 'catalog' => $catalog];
            $partners[] = ['source' => 'icorp', 'external_id' => $id, 'name' => $name, 'phones' => array_values(array_unique($phones))];
        }
        if ($partners === []) {
            throw new RuntimeException('No iCorp partner cards found. The session or page format may have changed.');
        }

        return $partners;
    }

    /** @return array{company: array<string, mixed>, items: list<array<string, mixed>>, account_discount: null, unavailable?: bool} */
    public function prices(string $externalId): array
    {
        if ($this->catalogs === []) {
            $this->partners();
        }
        $partner = $this->catalogs[$externalId] ?? null;
        if ($partner === null) {
            throw new RuntimeException('The supplier is not in the authenticated iCorp directory.');
        }
        $company = ['id' => $externalId, 'name' => $partner['name'], 'catalog_id' => $partner['catalog']];
        if ($partner['catalog'] === null) {
            return ['company' => $company, 'items' => [], 'account_discount' => null, 'unavailable' => true];
        }
        $items = [];
        $total = null;
        for ($page = 1; $page <= 1000; $page++) {
            $xpath = $this->document($this->get('/productsFirmByOverhead', [
                'id' => $partner['catalog'], 'q' => '', 'manufacturer' => '', 'discount_filter' => 'all', 'per_page' => 50, 'page' => $page,
            ]));
            $summary = $this->text($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," catalog-summary ")]');
            if (! preg_match('/Показано\s+(\d+)-(\d+)\s+из\s+(\d+)/u', $summary, $range)) {
                throw new RuntimeException('iCorp returned an invalid catalog summary.');
            }
            $total ??= (int) $range[3];
            if ((int) $range[3] !== $total || (int) $range[1] !== count($items) + 1) {
                throw new RuntimeException('The iCorp price list changed during extraction.');
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
                    throw new RuntimeException('iCorp returned an invalid or repeated price row.');
                }
                $items[$id] = [
                    'external_id' => $id, 'name' => $name, 'price' => $price,
                    'manufacturer' => $this->classText($xpath, 'catalog-subtitle', $row) ?: null,
                    'original_price' => $this->classText($xpath, 'catalog-price-old', $row) ?: null,
                    'discount_percent' => $discount, 'expiry' => $expiry === '-' ? null : $expiry,
                    'quantity' => null,
                ];
            }
            if (count($items) !== (int) $range[2]) {
                throw new RuntimeException('iCorp catalog rows do not match the page summary.');
            }
            if (count($items) === $total) {
                return ['company' => $company, 'items' => array_values($items), 'account_discount' => null];
            }
        }

        throw new RuntimeException('iCorp pagination exceeded the extraction limit.');
    }

    /** @param array<string, int|string> $query */
    private function get(string $path, array $query = []): string
    {
        $disk = Storage::disk('local');
        $sessionPath = 'external-prices/sessions/icorp-'.hash('sha256', (string) config('external-prices.icorp.phone')).'.encrypted';
        if (! $disk->exists($sessionPath)) {
            $this->connect();
        }
        $session = json_decode(Crypt::decryptString($disk->get($sessionPath)), true, 512, JSON_THROW_ON_ERROR);
        $jar = new CookieJar(false, $session['cookies']);
        $response = Http::connectTimeout(10)->timeout(40)->withoutRedirecting()
            ->withUserAgent($session['user_agent'])->withOptions(['cookies' => $jar])
            ->get('https://icorp.tech'.$path, $query);
        if (in_array($response->status(), [301, 302, 401], true)) {
            $this->connect();
            $session = json_decode(Crypt::decryptString($disk->get($sessionPath)), true, 512, JSON_THROW_ON_ERROR);
            $jar = new CookieJar(false, $session['cookies']);
            $response = $this->http($jar, $session['user_agent'])->get('https://icorp.tech'.$path, $query);
        }
        if (! $response->successful()) {
            throw new RuntimeException('iCorp workspace access failed (HTTP '.$response->status().'). Reconnect the account if its session expired.');
        }
        $session['cookies'] = $jar->toArray();
        if (! $disk->put($sessionPath, Crypt::encryptString(json_encode($session, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Could not save the encrypted iCorp session.');
        }

        return $response->body();
    }

    public function connect(bool $takeOver = false): void
    {
        $phone = preg_replace('/\D/', '', (string) config('external-prices.icorp.phone'));
        $password = config('external-prices.icorp.password');
        $firmId = (string) config('external-prices.icorp.firm_id');
        if ($phone === '' || ! is_string($password) || $password === '' || ! ctype_digit($firmId)) {
            throw new RuntimeException('Configure ICORP_PHONE, ICORP_PASSWORD and ICORP_FIRM_ID before connecting.');
        }
        $sessionPath = 'external-prices/sessions/icorp-'.hash('sha256', (string) config('external-prices.icorp.phone')).'.encrypted';
        $disk = Storage::disk('local');
        $session = $disk->exists($sessionPath) ? json_decode(Crypt::decryptString($disk->get($sessionPath)), true, 512, JSON_THROW_ON_ERROR) : ['cookies' => [], 'user_agent' => 'Oapteka price-list importer'];
        $jar = new CookieJar(false, $session['cookies']);
        $login = $this->http($jar, $session['user_agent'])->get('https://icorp.tech/login');
        if ($login->successful()) {
            $csrf = $this->csrf($login->body());
            $phone = strlen($phone) === 12 && str_starts_with($phone, '992') ? substr($phone, 3) : $phone;
            $step = $this->http($jar, $session['user_agent'])->asForm()->withHeaders(['X-CSRF-TOKEN' => $csrf, 'X-Requested-With' => 'XMLHttpRequest'])
                ->post('https://icorp.tech/authorize', ['phone' => $phone, '_token' => $csrf]);
            $payload = $step->json();
            $payload = is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : $payload;
            if (! $step->successful() || ($payload['result'] ?? null) != 5 || ! str_contains($payload['input'] ?? '', 'value="password"')) {
                throw new RuntimeException('iCorp requires additional authentication. Reconnect the account interactively.');
            }
            $authorized = $this->http($jar, $session['user_agent'])->asForm()->withHeaders(['X-CSRF-TOKEN' => $csrf, 'X-Requested-With' => 'XMLHttpRequest'])
                ->post('https://icorp.tech/authorize', ['phone' => $phone, '_token' => $csrf, 'auth_mode' => 'password', 'password' => $password]);
            $payload = $authorized->json();
            $payload = is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : $payload;
            if (! $authorized->successful() || ($payload['result'] ?? null) != 2) {
                throw new RuntimeException('iCorp login failed.');
            }
        } elseif (! $login->redirect()) {
            throw new RuntimeException('Could not open the iCorp login page.');
        }
        $choose = $this->http($jar, $session['user_agent'])->get('https://icorp.tech/choose');
        if (! $choose->successful()) {
            throw new RuntimeException('Could not select the iCorp workspace.');
        }
        $xpath = $this->document($choose->body());
        if ($xpath->query('//button[@data-firm-id="'.$firmId.'"]')->length !== 1) {
            throw new RuntimeException('The configured iCorp workspace is not available to this account.');
        }
        $entry = $this->http($jar, $session['user_agent'])->asForm()->acceptJson()
            ->withHeaders(['X-CSRF-TOKEN' => $this->csrf($choose->body()), 'X-Requested-With' => 'XMLHttpRequest'])
            ->post('https://icorp.tech/firm-entry', ['firm_id' => $firmId, 'force_firm_session' => $takeOver ? 1 : 0]);
        if ($entry->status() === 409) {
            throw new RuntimeException('iCorp is in use on another device. Reconnect explicitly with --take-over to sign out that device.');
        }
        if (! $entry->successful()) {
            throw new RuntimeException('iCorp workspace entry failed (HTTP '.$entry->status().').');
        }
        $session['cookies'] = $jar->toArray();
        if (! $disk->put($sessionPath, Crypt::encryptString(json_encode($session, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Could not save the encrypted iCorp session.');
        }
    }

    private function csrf(string $html): string
    {
        $node = $this->document($html)->query('//meta[@name="csrf-token"]')->item(0);
        if (! $node instanceof DOMElement || $node->getAttribute('content') === '') {
            throw new RuntimeException('iCorp did not return a CSRF token.');
        }

        return $node->getAttribute('content');
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
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
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
