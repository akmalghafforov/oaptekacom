<?php

namespace App\Console\Commands;

use App\Services\ExternalSupplierEligibility;
use App\Services\IcorpClient;
use App\Services\PriceListTjClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FetchExternalPrices extends Command
{
    protected $signature = 'external-prices:fetch {source : pricelist_tj or icorp} {--discover : Save partner metadata without fetching prices} {--exclude= : Local JSON list of suppliers already sending prices}';

    protected $description = 'Discover external suppliers or extract eligible price lists into private staging storage';

    public function handle(PriceListTjClient $priceListTj, IcorpClient $icorp, ExternalSupplierEligibility $eligibility): int
    {
        $source = $this->argument('source');
        if (! in_array($source, ['pricelist_tj', 'icorp'], true)) {
            $this->error('Choose pricelist_tj or icorp.');

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
        $lock = Cache::lock('external-prices:'.$source, 7200);
        if (! $lock->get()) {
            $this->error('An extraction is already using this service account.');

            return self::FAILURE;
        }
        try {
            $runPath = 'external-prices/runs/'.now()->format('Ymd-His').'-'.Str::uuid();
            $partners = $client->partners();
            $this->save($runPath.'/partners.json', $partners);
            if ($this->option('discover')) {
                $this->info(count($partners).' partners discovered. Private file: '.$runPath.'/partners.json');

                return self::SUCCESS;
            }
            $report = [];
            $this->save($runPath.'/report.json', $report);
            $failed = false;
            foreach ($partners as $partner) {
                $result = $eligibility->review([$partner], $excluded)[0];
                if ($result['outcome'] === 'eligible') {
                    try {
                        $prices = $client->prices($partner['external_id']);
                        if ($prices['unavailable'] ?? false) {
                            $result = [...$result, 'outcome' => 'skip', 'reason' => 'price_list_unavailable'];
                        } else {
                            $file = $runPath.'/'.hash('sha256', $partner['external_id']).'.json';
                            $this->save($file, ['source' => $source, 'fetched_at' => now()->toIso8601String(), ...$prices]);
                            $result = [...$result, 'outcome' => 'extracted', 'row_count' => count($prices['items']), 'file' => $file];
                        }
                    } catch (RuntimeException $exception) {
                        $failed = true;
                        $result = [...$result, 'outcome' => 'failed', 'reason' => 'extraction_failed', 'message' => $exception->getMessage()];
                    } catch (Throwable) {
                        $failed = true;
                        $result = [...$result, 'outcome' => 'failed', 'reason' => 'extraction_failed'];
                    }
                }
                $report[] = $result;
                $this->save($runPath.'/report.json', $report);
            }
            $this->info('Private extraction report: '.$runPath.'/report.json');

            return $failed ? self::FAILURE : self::SUCCESS;
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
