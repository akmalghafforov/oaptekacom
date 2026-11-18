<?php

namespace App\Console\Commands;

use App\Models\ExternalImportQueueItem;
use App\Models\ExternalServiceState;
use App\Services\SupplierVersions;
use Illuminate\Console\Command;

class ImportExternalSuppliers extends Command
{
    protected $signature = 'suppliers:import-external {--retry-failed-listings : Retry failed current-hour snapshots for ready sources}';

    protected $description = 'Scan external supplier listings, queue eligible imports, and recover unfinished work';

    public function handle(SupplierVersions $versions): int
    {
        if (! $versions->enabled() || $versions->paused()) {
            $this->warn('Supplier import dispatch is not ready: complete the daily-version rollout and resume dispatch.');
            $this->reportProgress();

            return self::FAILURE;
        }

        $scan = $this->call('suppliers:scan-listings', ['--dispatch' => true, '--retry-failed-listings' => (bool) $this->option('retry-failed-listings')]);
        $recovery = $this->call('suppliers:sync');
        $this->reportProgress();

        if ($scan !== self::SUCCESS) {
            $this->warn('Partial result: some listings are unavailable; eligible work from ready sources continues.');
        }

        return $scan === self::SUCCESS && $recovery === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function reportProgress(): void
    {
        foreach (['pricelist_tj', 'icorp'] as $source) {
            $state = ExternalServiceState::find($source);
            $this->line($source.': '.($state?->status ?? 'missing').($state?->reason ? ' ('.$state->reason.')' : '')
                .($state?->cooldown_until?->isFuture() ? ' until '.$state->cooldown_until->toIso8601String() : ''));
        }

        $counts = ExternalImportQueueItem::where('lane_id', 'system')->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $queued = collect(['queued', 'downloading', 'importing', 'publishing', 'retry'])->sum(fn (string $state): int => (int) ($counts[$state] ?? 0));
        $this->line('Persisted system lane: queued='.$queued.' completed='.($counts['completed'] ?? 0).' unchanged='.($counts['skipped'] ?? 0)
            .' failed='.($counts['failed'] ?? 0).' blocked='.($counts['blocked'] ?? 0));
        $this->line('Queued includes unfinished work. Publication is confirmed only after catalog activation.');
    }
}
