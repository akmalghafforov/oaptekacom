<?php

namespace App\Services;

use App\Models\ExternalListingRun;
use App\Models\ExternalServiceState;
use App\Models\SupplierSynchronization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class RenderedSupplierListing
{
    public function scan(string $source): ?ExternalListingRun
    {
        abort_unless(in_array($source, ['icorp', 'pricelist_tj'], true), 422);

        return Cache::lock('external-prices:'.$source, 140)->block(1, function () use ($source): ?ExternalListingRun {
            $run = ExternalListingRun::firstOrCreate(['source' => $source, 'scan_hour' => now()->startOfHour()]);
            if (in_array($run->status, ['completed', 'failed'], true) || $run->next_retry_at?->isFuture()) {
                return $run;
            }
            try {
                app(ExternalServiceControl::class)->assertAvailable($source);
                $run->update(['status' => 'scanning']);
                $options = ['source' => $source, 'pages' => $run->pages ?? [], 'executable_path' => config('external-prices.browser_executable')] + $this->session($source);
                $process = new Process([config('external-prices.browser_node', 'node'), app_path('Services/rendered_supplier_listing.mjs')], base_path(), null, json_encode($options, JSON_THROW_ON_ERROR), 110);
                $buffer = '';
                $process->run(function (string $type, string $output) use ($run, &$buffer): void {
                    if ($type !== Process::OUT) {
                        return;
                    }
                    $buffer .= $output;
                    while (($end = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $end);
                        $buffer = substr($buffer, $end + 1);
                        $page = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                        $pages = $run->fresh()->pages ?? [];
                        $pages[$page['page']] = $page;
                        $run->update(['pages' => $pages]);
                    }
                });
                if (! $process->isSuccessful()) {
                    $error = json_decode($process->getErrorOutput(), true) ?? [];
                    throw new ExternalServiceUnavailable($error['reason'] ?? 'extraction', $error['retry_after'] ?? null);
                }
                $this->publish($run->fresh());
            } catch (\Throwable $exception) {
                $reason = $exception instanceof ProcessTimedOutException ? 'transport' : ExternalSupplierDirectory::failureReason($exception);
                $retry = $run->retry_count + 1;
                $transient = in_array($reason, ['transport', 'rate_limited', 'service_cooldown', 'pacing_wait'], true);
                $delay = $exception instanceof ExternalServiceUnavailable && $exception->retryAfter ? $exception->retryAfter : app(ExternalServiceControl::class)->retryDelay($retry);
                $run->update(['status' => $transient && $retry <= 3 ? 'pending' : 'failed', 'reason' => $reason, 'retry_count' => $retry, 'next_retry_at' => $transient && $retry <= 3 ? now()->addSeconds($delay) : null]);
                if (in_array($reason, ['authentication', 'permission', 'extraction', 'takeover_required'], true)) {
                    app(ExternalServiceControl::class)->pause($source, $reason);
                }
                // Historical snapshots remain visible but cannot authorize new work.
                ExternalServiceState::whereKey($source)->update(['reason' => $reason, 'last_checked_at' => now()]);
                report($exception);
            }

            return $run->fresh();
        });
    }

    public function publish(ExternalListingRun $run): void
    {
        $pages = $run->pages ?? [];
        $total = (int) ($pages[1]['total_pages'] ?? 0);
        if ($total < 1 || count($pages) !== $total) {
            throw new \RuntimeException('incomplete_listing');
        }
        $partners = [];
        for ($number = 1; $number <= $total; $number++) {
            $page = $pages[$number] ?? null;
            if (! $page || $page['total_pages'] !== $total || ! $page['partners'] || $page['received'] !== count($page['partners'])) {
                throw new \RuntimeException('incomplete_listing');
            }
            foreach ($page['partners'] as $partner) {
                if (isset($partners[$partner['external_id']])) {
                    throw new \RuntimeException('duplicate_listing_id');
                }
                $partners[$partner['external_id']] = $partner + ['listing_run_id' => $run->id, 'observed_at' => now()->toIso8601String()];
            }
        }
        $expectedTotal = $pages[1]['total'] ?? null;
        if ($expectedTotal !== null && (count($partners) !== $expectedTotal || collect($pages)->contains(fn (array $page): bool => ($page['total'] ?? null) !== $expectedTotal))) {
            throw new \RuntimeException('incomplete_listing_count');
        }
        DB::transaction(function () use ($run, $partners): void {
            app(ExternalSupplierDirectory::class)->storeAvailability($run->source, array_values($partners));
            $run->update(['status' => 'completed', 'reason' => null, 'completed_at' => now()]);
            DB::afterCommit(function () use ($run): void {
                if (! $run->dispatch_requested || app(SupplierVersions::class)->paused() || $run->scan_hour->lt(now()->startOfHour())) {
                    return;
                }
                if (ExternalListingRun::where('scan_hour', $run->scan_hour)->whereIn('source', ['icorp', 'pricelist_tj'])->whereIn('status', ['completed', 'failed'])->count() !== 2) {
                    return;
                }
                SupplierSynchronization::where('automatic_enabled', true)->where('paused', false)->with('supplier')->eachById(function ($configuration): void {
                    if ($configuration->supplier->external_fetch_enabled) {
                        app(SupplierSynchronizationService::class)->request($configuration->supplier, 'listing_completed');
                    }
                });
            });
        });
    }

    /** @return array<string, mixed> */
    private function session(string $source): array
    {
        $disk = Storage::disk('local');
        $path = $source === 'icorp' ? app(IcorpCredentials::class)->sessionPath()
            : 'external-prices/sessions/pricelist-tj-'.hash('sha256', (string) config('external-prices.pricelist_tj.phone')).'.encrypted';
        if (! $disk->exists($path)) {
            throw new ExternalServiceUnavailable('authentication');
        }
        $value = Crypt::decryptString($disk->get($path));
        if ($source === 'pricelist_tj') {
            return ['token' => $value];
        }
        $session = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        $cookies = array_map(fn (array $cookie): array => ['name' => $cookie['Name'], 'value' => $cookie['Value'], 'domain' => $cookie['Domain'], 'path' => $cookie['Path'] ?: '/',
            'httpOnly' => (bool) ($cookie['HttpOnly'] ?? false), 'secure' => (bool) ($cookie['Secure'] ?? false), 'expires' => $cookie['Expires'] ?? -1, 'sameSite' => 'Lax'], $session['cookies']);

        return ['cookies' => $cookies, 'user_agent' => $session['user_agent']];
    }
}
