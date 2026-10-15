<?php

namespace App\Console\Commands;

use App\Models\PriceListImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupGmailImportFiles extends Command
{
    protected $signature = 'gmail-imports:cleanup';

    protected $description = 'Remove unreferenced private import files older than 24 hours';

    public function handle(): int
    {
        $disk = Storage::disk(config('price-list-imports.disk'));
        $count = 0;
        foreach ($disk->allFiles('price-list-imports') as $path) {
            if ($disk->lastModified($path) < now()->subDay()->timestamp && ! PriceListImport::where('file_path', $path)->exists()) {
                $disk->delete($path);
                $count++;
            }
        }
        $this->info("Removed {$count} abandoned files.");

        return self::SUCCESS;
    }
}
