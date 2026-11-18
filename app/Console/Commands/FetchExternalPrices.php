<?php

namespace App\Console\Commands;

use App\Models\ExternalSupplierPartner;
use App\Models\SupplierSynchronization;
use App\Services\ExternalSupplierEligibility;
use App\Services\IcorpClient;
use App\Services\PriceListTjClient;
use App\Services\RenderedSupplierListing;
use App\Services\SupplierSynchronizationService;
use App\Services\SupplierVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FetchExternalPrices extends Command
{
    protected $signature = 'external-prices:fetch {source : pricelist_tj or icorp} {--discover : Save partner metadata without fetching prices} {--exclude= : Local JSON list of suppliers already sending prices}';

    protected $description = 'Discover external suppliers or queue configured supplier imports in the system lane';

    public function handle(PriceListTjClient $priceListTj, IcorpClient $icorp, ExternalSupplierEligibility $eligibility): int
    {
        $source = $this->argument('source');
        if (! in_array($source, ['pricelist_tj', 'icorp'], true)) {
            $this->error('Choose pricelist_tj or icorp.');

            return self::FAILURE;
        }
        if (app(SupplierVersions::class)->enabled()) {
            if ($this->option('discover')) {
                app(RenderedSupplierListing::class)->scan($source);

                return self::SUCCESS;
            }
            $this->error('Direct extraction is retired. Use suppliers:scan-listings --dispatch for claimed background imports.');

            return self::FAILURE;
        }
        $client = $source === 'icorp' ? $icorp : $priceListTj;
        $excluded = [];
        if (! $this->option('discover')) {
            $path = $this->option('exclude');
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                $this->error('Provide --exclude with a readable local JSON list before extracting prices.');

                return self::FAILURE;
            }
            try {
                $contents = file_get_contents($path);
                if ($contents === false || ! is_array(json_decode($contents, false, 512, JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('The exclusion file must contain a JSON array.');
                }
                $excluded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                $eligibility->review([], $excluded);
            } catch (Throwable) {
                $this->error('The exclusion list is invalid. No external requests were made.');

                return self::FAILURE;
            }
        }
        if (! $this->option('discover')) {
            $group = (string) Str::uuid();
            $queued = 0;
            ExternalSupplierPartner::where('source', $source)->whereNotNull('supplier_organization_id')->where('profile_active', true)
                ->with('supplier')->eachById(function ($partner) use ($source, $excluded, $group, $eligibility, &$queued): void {
                    $configuration = SupplierSynchronization::where('supplier_organization_id', $partner->supplier_organization_id)->first();
                    if (! $configuration || ! in_array($source, $configuration->enabledSources(), true) || ! $configuration->automatic_enabled || $configuration->paused || $partner->supplier->status !== 'active') {
                        return;
                    }
                    $phones = array_filter(array_map($eligibility->normalizePhone(...), $partner->phones ?? []));
                    $isExcluded = collect($excluded)->contains(fn (array $entry): bool => ($entry['supplier_organization_id'] ?? null) === $partner->supplier_organization_id
                        || (isset($entry['name']) && $eligibility->normalizeName($entry['name']) === $eligibility->normalizeName($partner->name))
                        || array_intersect($phones, array_filter(array_map($eligibility->normalizePhone(...), $entry['phones'] ?? []))) !== []);
                    if ($isExcluded) {
                        return;
                    }
                    app(SupplierSynchronizationService::class)->request($partner->supplier, 'cli', groupId: $group);
                    $queued++;
                });
            $this->info($queued.' supplier imports queued in the system lane.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('external-prices:'.$source, 7200);
        if (! $lock->get()) {
            $this->error('An extraction is already using this service account.');

            return self::FAILURE;
        }
        try {
            $runPath = 'external-prices/runs/'.now()->format('Ymd-His').'-'.Str::uuid();
            $partners = $client->partners();
            $this->save($runPath.'/partners.json', $partners);
            $this->info(count($partners).' partners discovered. Private file: '.$runPath.'/partners.json');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('External extraction failed. Check service connectivity, credentials and account access.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /** @param array<mixed> $data */
    private function save(string $path, array $data): void
    {
        if (! Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Could not save extraction output.');
        }
    }
}
