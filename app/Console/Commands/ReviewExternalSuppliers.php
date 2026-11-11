<?php

namespace App\Console\Commands;

use App\Services\ExternalSupplierEligibility;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class ReviewExternalSuppliers extends Command
{
    protected $signature = 'external-prices:review {partners : Path to a JSON array of discovered partners} {--exclude= : Required path to a JSON array of suppliers already sending price lists}';

    protected $description = 'Review external partner eligibility without downloading prices or changing suppliers';

    public function handle(ExternalSupplierEligibility $eligibility): int
    {
        if (! $this->option('exclude')) {
            $this->error('Provide --exclude with the current sending-supplier list. Use an empty JSON array only if there are no exclusions.');

            return self::FAILURE;
        }

        try {
            $results = $eligibility->review($this->readList($this->argument('partners')), $this->readList($this->option('exclude')));
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function readList(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Supplier input must be a readable local JSON file.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read supplier input.');
        }
        $decoded = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Supplier input must contain a JSON array.');
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
