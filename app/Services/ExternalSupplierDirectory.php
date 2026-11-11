<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ExternalSupplierDirectory
{
    public function refresh(string $source): void
    {
        abort_unless(in_array($source, ['icorp', 'pricelist_tj'], true), 422);
        Cache::lock('external-prices:'.$source, 7200)->block(1, function () use ($source): void {
            try {
                $partners = app($source === 'icorp' ? IcorpClient::class : PriceListTjClient::class)->partners();
                Validator::make(['partners' => $partners], [
                    'partners' => ['required', 'array', 'list', 'min:1'],
                    'partners.*.external_id' => ['required', 'string', 'max:255', 'distinct:strict'],
                    'partners.*.city' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'partners.*.name' => ['required', 'string', 'max:255'],
                    'partners.*.phones' => ['present', 'array'],
                    'partners.*.phones.*' => ['string', 'max:100'],
                ])->validate();
                DB::transaction(function () use ($partners, $source): void {
                    foreach ($partners as $partner) {
                        $record = ExternalSupplierPartner::where('source', $source)->where('external_id', $partner['external_id'])->lockForUpdate()->first()
                            ?? new ExternalSupplierPartner(['source' => $source, 'external_id' => $partner['external_id']]);
                        $record->fill([
                            'name' => $partner['name'], 'phones' => $partner['phones'],
                            'metadata' => $record->directoryMetadata($partner),
                            'discovered_at' => now(),
                        ])->save();
                    }
                });
                Cache::forever('external-prices:status:'.$source, ['status' => 'connected', 'checked_at' => now()->toIso8601String()]);
            } catch (Throwable $exception) {
                Cache::forever('external-prices:status:'.$source, ['status' => self::failureReason($exception), 'checked_at' => now()->toIso8601String()]);
                throw $exception;
            }
        });
    }

    /** @return list<array{id: int, name: string, similarity: float, score: int|float, evidence: list<string>}> */
    public function suggestions(ExternalSupplierPartner $partner, ?Collection $suppliers = null): array
    {
        $matcher = app(SupplierSuggestionMatcher::class);
        $suppliers ??= Organization::where('type', OrganizationType::Wholesaler)->orderBy('id')->get();
        $matcher->useCities(Organization::whereNotNull('city')->distinct()->pluck('city'));
        $prepared = $matcher->prepare($partner);
        $candidates = [];
        foreach ($suppliers as $supplier) {
            if ($evidence = $matcher->compare($prepared, $matcher->prepare($supplier))) {
                $candidates[] = ['id' => $supplier->id, 'name' => $supplier->name, ...$evidence];
            }
        }

        return $matcher->rank($candidates);
    }

    public static function failureReason(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'another device'), str_contains($message, '409') => 'takeover_required',
            str_contains($message, '403'), str_contains($message, 'permit'), str_contains($message, 'not available') => 'permission',
            str_contains($message, '401'), str_contains($message, 'login'), str_contains($message, 'authentication'), str_contains($message, 'configure ') => 'authentication',
            $exception instanceof ConnectionException, str_contains($message, 'http 5'), str_contains($message, 'http 429') => 'transport',
            default => 'extraction',
        };
    }
}
