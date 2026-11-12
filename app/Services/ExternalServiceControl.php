<?php

namespace App\Services;

use App\Jobs\NotifyExternalServiceIncident;
use App\Models\ExternalServiceIncident;
use App\Models\ExternalServiceState;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

class ExternalServiceControl
{
    public function assertAvailable(string $source, bool $recovery = false): void
    {
        $state = ExternalServiceState::findOrFail($source);
        if ($state->cooldown_until?->isFuture()) {
            throw new ExternalServiceUnavailable('service_cooldown', (int) ceil(now()->diffInSeconds($state->cooldown_until)));
        }
        if (! $recovery && $state->status === 'paused') {
            throw new ExternalServiceUnavailable($state->reason ?? 'authentication');
        }
    }

    public function request(string $source, Closure $request, bool $recovery = false): Response
    {
        return Cache::lock('external-http:global', 90)->block(1, function () use ($source, $request, $recovery): Response {
            $this->assertAvailable($source, $recovery);
            $state = ExternalServiceState::findOrFail($source);
            $global = DB::table('external_download_leases')->where('id', 1)->value('next_request_at');
            $due = $state->next_request_at;
            if ($global && (! $due || $due->lt($global))) {
                $due = CarbonImmutable::parse($global);
            }
            if ($due?->isFuture()) {
                $wait = (int) ceil(now()->diffInMicroseconds($due));
                if ($wait > 30000000) {
                    throw new ExternalServiceUnavailable('pacing_wait', (int) ceil($wait / 1000000));
                }
                Sleep::usleep($wait);
            }
            $this->assertAvailable($source, $recovery);
            $interval = max(5, (int) config('external-prices.'.$source.'.request_interval_seconds', 5));
            $state->update(['next_request_at' => now()->addSeconds($interval)->ceilSecond(), 'last_checked_at' => now()]);
            DB::table('external_download_leases')->where('id', 1)->update(['next_request_at' => now()->addSeconds(5)->ceilSecond()]);
            $response = $request();
            if ($response->status() === 429) {
                $retry = $response->header('Retry-After');
                try {
                    $seconds = is_numeric($retry) ? (int) $retry : (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($retry)));
                } catch (\Throwable) {
                    $seconds = 3600;
                }
                $state->update(['status' => 'cooldown', 'reason' => 'rate_limited', 'cooldown_until' => now()->addSeconds(max(3600, $seconds))]);
                $this->incident($source, 'rate_limited');
                throw new ExternalServiceUnavailable('rate_limited', max(3600, $seconds));
            }
            $challenge = preg_match('/captcha|cf-chl-|challenge-platform|access denied/i', $response->body()) === 1;
            $blocked = $challenge || in_array($response->status(), [401, 403, 409, 419], true) || (! $recovery && (in_array($response->status(), [301, 302, 303, 307, 308], true) || preg_match('/<form[^>]*action=["\'][^"\']*\/authorize|name=["\']auth_mode/i', $response->body())));
            if ($blocked) {
                $reason = $challenge ? 'challenge' : match ($response->status()) {
                    409 => 'takeover_required', 403 => 'permission', default => 'authentication',
                };
                $this->pause($source, $reason);
                if ($challenge) {
                    throw new ExternalServiceUnavailable($reason);
                }
            }
            if ($response->successful() && ! $blocked) {
                $state->refresh()->update(['last_success_at' => now(), ...($state->status === 'cooldown' && ! $state->cooldown_until?->isFuture() ? ['status' => 'ready', 'reason' => null, 'cooldown_until' => null] : [])]);
            }

            return $response;
        });
    }

    public function pause(string $source, string $reason): void
    {
        ExternalServiceState::whereKey($source)->update(['status' => 'paused', 'reason' => $reason]);
        $this->incident($source, $reason);
    }

    public function recovered(string $source): void
    {
        DB::transaction(function () use ($source): void {
            ExternalServiceState::whereKey($source)->update(['status' => 'ready', 'reason' => null, 'cooldown_until' => null,
                'retry_count' => 0, 'next_check_at' => null, 'directory_checked_at' => null, 'directory' => null, 'directory_progress' => null, 'directory_owner' => null, 'directory_lease_expires_at' => null, 'last_success_at' => now()]);
            ExternalServiceIncident::where('active_source', $source)->update(['active_source' => null, 'resolved_at' => now()]);
        });
    }

    public function incident(string $source, string $reason): void
    {
        $incident = ExternalServiceIncident::firstOrCreate(['active_source' => $source], ['source' => $source, 'reason' => $reason]);
        if ($incident->wasRecentlyCreated) {
            NotifyExternalServiceIncident::dispatch($incident->id)->onQueue(config('price-list-imports.queue'))->afterCommit();
        }
    }

    public function retryDelay(int $retry): int
    {
        $base = [300, 600, 1200][$retry - 1] ?? 21600;

        return $base + ($retry <= 3 ? random_int(0, (int) ($base * .2)) : 0);
    }
}
