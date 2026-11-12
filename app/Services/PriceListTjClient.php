<?php

namespace App\Services;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PriceListTjClient
{
    private bool $recovering = false;

    private const BASE_URL = 'https://pricelist-tj.com';

    /** @return list<array{source: string, external_id: string, name: string, phones: list<string>, city: ?string, published_at: ?string}> */
    public function partners(): array
    {
        $partners = [];
        foreach ($this->pages('/api/companies', ['type' => 'wholesale', 'pageSize' => 30], 'companies') as $page) {
            foreach ($page['companies'] as $company) {
                if (! is_string($company['id'] ?? null) || ! is_string($company['name'] ?? null)) {
                    throw new RuntimeException('PriceList returned an invalid company.');
                }
                if (($company['blocked'] ?? false) || ! ($company['verified'] ?? false)) {
                    continue;
                }
                $partners[] = [
                    'source' => 'pricelist_tj', 'external_id' => $company['id'], 'name' => $company['name'],
                    'phones' => array_values(array_filter([$company['phone'] ?? null, $company['extraPhone'] ?? null])),
                    'published_at' => $company['lastPriceUpdate'] ?? null,
                    'city' => $company['city'] ?? null,
                ];
            }
        }

        return $partners;
    }

    /** @return array{partners: list<array<string, mixed>>, total: int, total_pages: int, received: int} */
    public function partnerPage(int $number): array
    {
        $page = $this->get('/api/companies', ['type' => 'wholesale', 'pageSize' => 30, 'page' => $number]);
        $pagination = $page['pagination'] ?? [];
        if (! is_array($page['companies'] ?? null) || ! array_is_list($page['companies']) || count($page['companies']) > 30
            || ($pagination['page'] ?? null) !== $number || ! is_int($pagination['total'] ?? null) || $pagination['total'] < 0
            || ! is_int($pagination['totalPages'] ?? null) || $pagination['totalPages'] < 0 || $pagination['totalPages'] > 1000) {
            throw new RuntimeException('PriceList returned an invalid paginated directory.');
        }
        $partners = [];
        foreach ($page['companies'] as $company) {
            if (! is_string($company['id'] ?? null) || ! is_string($company['name'] ?? null)) {
                throw new RuntimeException('PriceList returned an invalid company.');
            }
            if (($company['blocked'] ?? false) || ! ($company['verified'] ?? false)) {
                continue;
            }
            $partners[] = ['source' => 'pricelist_tj', 'external_id' => $company['id'], 'name' => $company['name'],
                'phones' => array_values(array_filter([$company['phone'] ?? null, $company['extraPhone'] ?? null])),
                'published_at' => $company['lastPriceUpdate'] ?? null, 'city' => $company['city'] ?? null];
        }

        return ['partners' => $partners, 'total' => $pagination['total'], 'total_pages' => $pagination['totalPages'], 'received' => count($page['companies'])];
    }

    /** @return array{company: array<string, mixed>, items: list<array<string, mixed>>, account_discount: mixed} */
    public function prices(string $externalId): array
    {
        return app(ExternalDownloadLease::class)->run(function (\Closure $heartbeat) use ($externalId): array {
            $items = [];
            $company = null;
            $total = null;
            $totalPages = null;
            for ($number = 1; $number <= 1000; $number++) {
                $heartbeat();
                $page = $this->pricePage($externalId, $number);
                if (($company !== null && $company !== $page['company']) || ($total !== null && ($total !== $page['total'] || $totalPages !== $page['total_pages']))) {
                    throw new RuntimeException('The price list changed during extraction.');
                }
                $company = $page['company'];
                $total = $page['total'];
                $totalPages = $page['total_pages'];
                foreach ($page['items'] as $item) {
                    if (isset($items[$item['external_id']])) {
                        throw new RuntimeException('PriceList repeated rows across pages.');
                    }
                    $items[$item['external_id']] = $item;
                }
                if ($number >= $totalPages) {
                    if (count($items) !== $total) {
                        throw new RuntimeException('PriceList row count does not match its reported total.');
                    }

                    return ['company' => $company, 'items' => array_values($items), 'account_discount' => $page['account_discount']];
                }
            }
            throw new RuntimeException('PriceList pagination exceeded the extraction limit.');
        });
    }

    /** @return array{company: array<string, mixed>, items: list<array<string, mixed>>, total: int, total_pages: int, account_discount: mixed} */
    public function pricePage(string $externalId, int $number, array $evidence = []): array
    {
        $page = $this->get('/api/companies/'.rawurlencode($externalId), ['pageSize' => 50, 'sort' => 'price_asc', 'page' => $number]);
        $pagination = $page['pagination'] ?? [];
        if (! is_array($page['priceItems'] ?? null) || ! array_is_list($page['priceItems']) || count($page['priceItems']) > 50
            || ($pagination['page'] ?? null) !== $number || ! is_int($pagination['total'] ?? null)
            || ! is_int($pagination['totalPages'] ?? null) || $pagination['total'] < 0
            || $pagination['totalPages'] < 0 || $pagination['totalPages'] > 1000) {
            throw new RuntimeException('PriceList returned an invalid paginated response.');
        }
        $items = [];
        if (($page['restricted'] ?? true) !== false) {
            app(ExternalServiceControl::class)->pause('pricelist_tj', 'permission');
            throw new RuntimeException('PriceList does not permit access to this price list.');
        }
        if (($page['company']['id'] ?? null) !== $externalId) {
            throw new RuntimeException('PriceList returned prices for a different company.');
        }
        $metadata = array_intersect_key($page['company'], array_flip(['id', 'name', 'city', 'phone', 'extraPhone', 'address', 'lastPriceUpdate']));
        $company = $metadata;
        $discount = $page['myDiscount'] ?? null;
        foreach ($page['priceItems'] as $item) {
            if (! is_string($item['id'] ?? null) || ($item['companyId'] ?? null) !== $externalId
                || ! is_string($item['medicine'] ?? null) || ! is_numeric($item['price'] ?? null) || $item['price'] < 0) {
                throw new RuntimeException('PriceList returned an invalid price row.');
            }
            if (isset($items[$item['id']])) {
                throw new RuntimeException('PriceList repeated rows across pages. No partial price list was saved.');
            }
            $items[$item['id']] = [
                'external_id' => $item['id'], 'name' => $item['medicine'],
                'manufacturer' => $item['manufacturer'] ?? null, 'price' => $item['price'],
                'original_price' => $item['originalPrice'] ?? null, 'discount_percent' => $item['discountPercent'] ?? null,
                'quantity' => $item['qty'] ?? null, 'expiry' => $item['expiry'] ?? null,
            ];
        }

        return ['company' => $company, 'items' => array_values($items), 'account_discount' => $discount, 'total' => $pagination['total'], 'total_pages' => $pagination['totalPages']];
    }

    /**
     * @param  array<string, int|string>  $query
     * @return Generator<int, array<string, mixed>>
     */
    private function pages(string $path, array $query, string $itemsKey): Generator
    {
        $expectedTotal = null;
        $received = 0;
        for ($number = 1; $number <= 1000; $number++) {
            $page = $this->get($path, [...$query, 'page' => $number]);
            $pagination = $page['pagination'] ?? [];
            if (! is_array($page[$itemsKey] ?? null) || ! array_is_list($page[$itemsKey])
                || ($pagination['page'] ?? null) !== $number || ! is_int($pagination['total'] ?? null)
                || ! is_int($pagination['totalPages'] ?? null) || $pagination['total'] < 0
                || $pagination['totalPages'] < 0 || $pagination['totalPages'] > 1000) {
                throw new RuntimeException('PriceList returned an invalid paginated response.');
            }
            $expectedTotal ??= $pagination['total'];
            if ($pagination['total'] !== $expectedTotal || ($page[$itemsKey] === [] && $received < $expectedTotal)) {
                throw new RuntimeException('PriceList pagination changed or ended before all rows were received.');
            }
            $received += count($page[$itemsKey]);
            yield $page;
            if ($number >= $pagination['totalPages']) {
                if ($received !== $expectedTotal) {
                    throw new RuntimeException('PriceList row count does not match its reported total.');
                }

                return;
            }
        }

        throw new RuntimeException('PriceList pagination exceeded the extraction limit.');
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $token = $this->token();
        $response = app(ExternalServiceControl::class)->request('pricelist_tj', fn () => $this->http()->withToken($token)->get(self::BASE_URL.$path, $query));
        if (! $response->successful() || ! is_array($response->json()) || isset($response->json()['error'])) {
            throw new RuntimeException('PriceList request failed (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    public function connect(): void
    {
        $this->recovering = true;
        try {
            Storage::disk('local')->delete($this->sessionPath());
            $this->token();
            app(ExternalServiceControl::class)->recovered('pricelist_tj');
        } finally {
            $this->recovering = false;
        }
    }

    private function token(): string
    {
        $disk = Storage::disk('local');
        $path = $this->sessionPath();
        if ($disk->exists($path)) {
            return Crypt::decryptString($disk->get($path));
        }
        if (! $this->recovering) {
            app(ExternalServiceControl::class)->pause('pricelist_tj', 'authentication');
            throw new ExternalServiceUnavailable('authentication');
        }
        $phone = preg_replace('/\D/', '', (string) config('external-prices.pricelist_tj.phone'));
        $password = config('external-prices.pricelist_tj.password');
        if ($phone === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Configure PRICELIST_TJ_PHONE and PRICELIST_TJ_PASSWORD before connecting.');
        }
        $response = app(ExternalServiceControl::class)->request('pricelist_tj', fn () => $this->http()->post(self::BASE_URL.'/api/auth/login', [
            'phone' => '+'.(strlen($phone) === 9 ? '992'.$phone : $phone), 'password' => $password,
        ]), recovery: true);
        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            app(ExternalServiceControl::class)->pause('pricelist_tj', 'authentication');
            throw new RuntimeException('PriceList login failed (HTTP '.$response->status().').');
        }
        if (! $disk->put($path, Crypt::encryptString($token))) {
            throw new RuntimeException('Could not save the encrypted PriceList session.');
        }

        return $token;
    }

    private function sessionPath(): string
    {
        return 'external-prices/sessions/pricelist-tj-'.hash('sha256', (string) config('external-prices.pricelist_tj.phone')).'.encrypted';
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(10)->timeout(40)->withoutRedirecting();
    }
}
