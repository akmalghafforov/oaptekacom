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
    private const BASE_URL = 'https://pricelist-tj.com';

    /** @return list<array{source: string, external_id: string, name: string, phones: list<string>}> */
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
                ];
            }
        }

        return $partners;
    }

    /** @return array{company: array<string, mixed>, items: list<array<string, mixed>>, account_discount: mixed} */
    public function prices(string $externalId): array
    {
        $items = [];
        $company = null;
        $discount = null;
        foreach ($this->pages('/api/companies/'.rawurlencode($externalId), ['pageSize' => 50, 'sort' => 'price_asc'], 'priceItems') as $page) {
            if (($page['restricted'] ?? true) !== false) {
                throw new RuntimeException('PriceList does not permit access to this price list.');
            }
            if (($page['company']['id'] ?? null) !== $externalId) {
                throw new RuntimeException('PriceList returned prices for a different company.');
            }
            $metadata = array_intersect_key($page['company'], array_flip(['id', 'name', 'city', 'phone', 'extraPhone', 'address', 'lastPriceUpdate']));
            if ($company !== null && $company !== $metadata) {
                throw new RuntimeException('The price list changed during extraction. Run extraction again.');
            }
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
        }

        return ['company' => $company ?? [], 'items' => array_values($items), 'account_discount' => $discount];
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
        $response = $this->http()->withToken($this->token())->get(self::BASE_URL.$path, $query);
        if ($response->status() === 401) {
            Storage::disk('local')->delete($this->sessionPath());
            $response = $this->http()->withToken($this->token())->get(self::BASE_URL.$path, $query);
        }
        if (! $response->successful() || ! is_array($response->json()) || isset($response->json()['error'])) {
            throw new RuntimeException('PriceList request failed (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    private function token(): string
    {
        $disk = Storage::disk('local');
        $path = $this->sessionPath();
        if ($disk->exists($path)) {
            return Crypt::decryptString($disk->get($path));
        }
        $phone = preg_replace('/\D/', '', (string) config('external-prices.pricelist_tj.phone'));
        $password = config('external-prices.pricelist_tj.password');
        if ($phone === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Configure PRICELIST_TJ_PHONE and PRICELIST_TJ_PASSWORD before connecting.');
        }
        $response = $this->http()->post(self::BASE_URL.'/api/auth/login', [
            'phone' => '+'.(strlen($phone) === 9 ? '992'.$phone : $phone), 'password' => $password,
        ]);
        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
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
