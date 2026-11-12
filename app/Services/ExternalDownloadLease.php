<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExternalDownloadLease
{
    public function claim(string $owner): int
    {
        return DB::transaction(function () use ($owner): int {
            $lease = DB::table('external_download_leases')->where('id', 1)->lockForUpdate()->first();
            if ($lease->owner !== null && $lease->owner !== $owner && $lease->expires_at > now()->toDateTimeString()) {
                throw new ExternalServiceUnavailable('download_busy');
            }
            $fence = $lease->fence + 1;
            DB::table('external_download_leases')->where('id', 1)->update(['owner' => $owner, 'fence' => $fence, 'expires_at' => now()->addMinutes(3)]);

            return $fence;
        }, 3);
    }

    public function assertOwned(string $owner, int $fence): void
    {
        $lease = DB::table('external_download_leases')->where('id', 1)->lockForUpdate()->first();
        if ($lease->owner !== $owner || (int) $lease->fence !== $fence || $lease->expires_at <= now()->toDateTimeString()) {
            throw new ExternalServiceUnavailable('lease_lost');
        }
    }

    public function release(string $owner, int $fence): void
    {
        DB::table('external_download_leases')->where('id', 1)->where('owner', $owner)->where('fence', $fence)
            ->update(['owner' => null, 'expires_at' => null]);
    }

    public function run(Closure $callback): mixed
    {
        $owner = 'direct:'.Str::uuid();
        $fence = $this->claim($owner);
        try {
            return $callback(function () use ($owner, &$fence): void {
                DB::transaction(function () use ($owner, &$fence): void {
                    $this->assertOwned($owner, $fence);
                    DB::table('external_download_leases')->where('id', 1)->update(['expires_at' => now()->addMinutes(3)]);
                });
            });
        } finally {
            $this->release($owner, $fence);
        }
    }
}
