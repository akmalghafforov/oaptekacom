<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Jobs\RefreshExternalSupplierDirectory;
use App\Models\ExternalAvailability;
use App\Models\ExternalServiceState;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\SupplierSynchronization;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

    /** @return list<array<string, mixed>> */
    public function availability(string $source): array
    {
        $state = ExternalServiceState::findOrFail($source);
        if ($state->directory_checked_at?->gt(now()->subHour()) && $state->directory !== null) {
            return $state->directory;
        }
        app(ExternalServiceControl::class)->assertAvailable($source);
        if ($state->next_check_at?->isFuture()) {
            throw new ExternalServiceUnavailable('service_retry_pending');
        }
        RefreshExternalSupplierDirectory::dispatch($source)->onQueue(config('price-list-imports.queue'));
        $state->refresh();
        if ($state->directory_checked_at?->gt(now()->subHour()) && $state->directory !== null) {
            return $state->directory;
        }

        app(ExternalServiceControl::class)->assertAvailable($source);
        throw new ExternalServiceUnavailable('availability_pending');
    }

    public function refreshAvailabilityPage(string $source): void
    {
        $control = app(ExternalServiceControl::class);
        $token = (string) Str::uuid();
        $state = DB::transaction(function () use ($source, $token): ?ExternalServiceState {
            $state = ExternalServiceState::whereKey($source)->lockForUpdate()->firstOrFail();
            if ($state->status === 'paused' || $state->cooldown_until?->isFuture() || $state->next_check_at?->isFuture()
                || ($state->directory_owner && $state->directory_lease_expires_at?->isFuture())) {
                return null;
            }
            if ($state->retry_count > 3) {
                $state->retry_count = 0;
            }
            $state->fill(['directory_progress' => $state->directory_progress ?? ['next_page' => 1, 'received' => 0, 'items' => [], 'total' => null, 'total_pages' => null], 'directory_owner' => $token, 'directory_lease_expires_at' => now()->addSeconds(140)])->save();

            return $state;
        }, 3);
        if (! $state) {
            return;
        }
        $continue = false;
        try {
            $progress = $state->directory_progress ?? ['next_page' => 1, 'received' => 0, 'items' => [], 'total' => null, 'total_pages' => null];
            $page = Cache::lock('external-prices:'.$source, 140)->block(1, function () use ($source, $progress): array {
                if ($source === 'icorp') {
                    $partners = app(IcorpClient::class)->partners();

                    return ['partners' => $partners, 'total' => count($partners), 'total_pages' => 1, 'received' => count($partners)];
                }

                return app(PriceListTjClient::class)->partnerPage($progress['next_page']);
            });
            $continue = DB::transaction(function () use ($source, $token, $progress, $page): bool {
                $state = ExternalServiceState::whereKey($source)->lockForUpdate()->firstOrFail();
                if ($state->directory_owner !== $token || ! $state->directory_lease_expires_at?->isFuture()) {
                    return false;
                }
                if (($progress['total'] !== null && ($progress['total'] !== $page['total'] || $progress['total_pages'] !== $page['total_pages']))
                    || ($page['received'] === 0 && $page['total'] > 0)) {
                    throw new \RuntimeException('directory_changed');
                }
                $items = $progress['items'];
                foreach ($page['partners'] as $partner) {
                    if (isset($items[$partner['external_id']])) {
                        throw new \RuntimeException('directory_repeated_partner');
                    }
                    $items[$partner['external_id']] = $partner;
                }
                $received = $progress['received'] + $page['received'];
                $complete = $progress['next_page'] >= $page['total_pages'];
                if ($received > $page['total'] || ($complete && $received !== $page['total'])) {
                    throw new \RuntimeException('incomplete_directory');
                }
                if ($complete) {
                    $this->storeAvailability($source, array_values($items));
                    $state->update(['directory_progress' => null, 'directory_owner' => null, 'directory_lease_expires_at' => null]);
                } else {
                    $state->update(['directory_progress' => ['next_page' => $progress['next_page'] + 1, 'items' => $items, 'received' => $received, 'total' => $page['total'], 'total_pages' => $page['total_pages']]]);
                }

                return ! $complete;
            }, 3);
        } catch (LockTimeoutException) {
            $state->update(['next_check_at' => now()->addMinute()]);
        } catch (Throwable $exception) {
            $state = ExternalServiceState::whereKey($source)->where('directory_owner', $token)->first();
            if ($state) {
                $reason = self::failureReason($exception);
                $transient = $reason === 'transport';
                $retry = $state->retry_count + ($transient ? 1 : 0);
                $delay = $exception instanceof ExternalServiceUnavailable && $exception->retryAfter ? $exception->retryAfter : ($transient ? $control->retryDelay($retry) : 21600);
                $cooldown = now()->addHours(6);
                if ($state->cooldown_until?->gt($cooldown)) {
                    $cooldown = $state->cooldown_until;
                }
                $state->update(['retry_count' => $retry, 'next_check_at' => now()->addSeconds($delay),
                    ...($transient && $retry > 3 ? ['cooldown_until' => $cooldown] : []),
                    ...($reason === 'extraction' ? ['directory_progress' => null] : [])]);
                if ($reason === 'extraction' || ($transient && $retry > 3)) {
                    $control->incident($source, $reason === 'extraction' ? 'extraction' : 'retry_exhausted');
                }
            }
        } finally {
            ExternalServiceState::whereKey($source)->where('directory_owner', $token)->update(['directory_owner' => null, 'directory_lease_expires_at' => null]);
        }
        if ($continue) {
            RefreshExternalSupplierDirectory::dispatch($source)->onQueue(config('price-list-imports.queue'));
        }
    }

    /** @param list<array<string, mixed>> $partners */
    private function storeAvailability(string $source, array $partners): void
    {
        Validator::make(['partners' => $partners], [
            'partners' => ['present', 'array', 'list'], 'partners.*.external_id' => ['required', 'string', 'max:255', 'distinct:strict'],
            'partners.*.name' => ['required', 'string', 'max:255'], 'partners.*.phones' => ['present', 'array'], 'partners.*.phones.*' => ['string', 'max:100'],
        ])->validate();
        foreach ($partners as $entry) {
            $partner = ExternalSupplierPartner::where('source', $source)->where('external_id', $entry['external_id'])->lockForUpdate()->first()
                ?? new ExternalSupplierPartner(['source' => $source, 'external_id' => $entry['external_id']]);
            $partner->fill(['name' => $entry['name'], 'phones' => $entry['phones'], 'metadata' => $partner->directoryMetadata($entry), 'discovered_at' => now()])->save();
        }
        ExternalServiceState::whereKey($source)->update(['directory' => $partners, 'directory_checked_at' => now(), 'next_check_at' => now()->addHour(), 'retry_count' => 0]);
        foreach (ExternalSupplierPartner::where('source', $source)->get() as $partner) {
            $evidence = collect($partners)->firstWhere('external_id', $partner->external_id);
            $available = $evidence !== null && ($source !== 'icorp' || ! empty($evidence['catalog_id']));
            $publication = ExternalPriceWorkbook::publicationDate($evidence['published_at'] ?? null);
            $evidence ??= ['external_id' => $partner->external_id, 'missing' => true];
            $version = hash('sha256', json_encode([$partner->external_id, $evidence['published_at'] ?? null, $evidence['catalog_id'] ?? null, $available], JSON_THROW_ON_ERROR));
            $confirmed = ExternalAvailability::where('external_supplier_partner_id', $partner->id)->where('version_key', $version)->first();
            if ($confirmed && isset($confirmed->evidence['confirmed_published_at'])) {
                $publication = ['date' => $confirmed->published_at, 'precision' => $confirmed->date_precision];
                $evidence = array_replace($evidence, array_intersect_key($confirmed->evidence, array_flip(['confirmed_published_at', 'date_confirmed_by', 'date_evidence'])));
            }
            ExternalAvailability::updateOrCreate(['external_supplier_partner_id' => $partner->id, 'version_key' => $version],
                ['available' => $available, 'published_at' => $publication['date'], 'date_precision' => $publication['precision'], 'evidence' => $evidence, 'last_checked_at' => now(), 'next_check_at' => now()->addHour()]);
            if ($available) {
                $partner->update(['metadata' => $partner->directoryMetadata($evidence), 'discovered_at' => now()]);
            }
        }
        Cache::forever('external-prices:status:'.$source, ['status' => 'connected', 'checked_at' => now()->toIso8601String()]);
        SupplierSynchronization::where('selection_mode', 'newest_available')->where('automatic_enabled', true)->whereIn('supplier_organization_id', ExternalSupplierPartner::where('source', $source)->whereNotNull('supplier_organization_id')->select('supplier_organization_id'))->update(['next_check_at' => now()]);
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
        if ($exception instanceof ExternalServiceUnavailable) {
            return $exception->reason;
        }
        if ($exception instanceof IcorpFailure) {
            return $exception->reason;
        }
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'another device'), str_contains($message, '409') => 'takeover_required',
            str_contains($message, '403'), str_contains($message, 'permit'), str_contains($message, 'not available') => 'permission',
            str_contains($message, '401'), str_contains($message, '419'), str_contains($message, 'login'), str_contains($message, 'authentication'), str_contains($message, 'configure ') => 'authentication',
            $exception instanceof ConnectionException, str_contains($message, 'http 5'), str_contains($message, 'http 429') => 'transport',
            default => 'extraction',
        };
    }
}
