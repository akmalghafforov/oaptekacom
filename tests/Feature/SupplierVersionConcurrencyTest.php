<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;
use App\Models\SupplierVersionClaim;
use App\Services\PriceList\ImportActivator;
use App\Services\SupplierVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SupplierVersionConcurrencyTest extends TestCase
{
    public function test_competing_workers_claim_one_supplier_day_and_recover_the_winner(): void
    {
        if (getenv('SUPPLIER_VERSION_CONCURRENCY_TESTS') !== '1' || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Run against a migrated isolated PostgreSQL test database with SUPPLIER_VERSION_CONCURRENCY_TESTS=1.');
        }
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        $supplier = Organization::factory()->wholesaler()->create();
        $imports = collect(['email', 'icorp'])->map(fn (string $source) => PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => $source, 'source_published_at' => now()->startOfDay(), 'source_date_precision' => 'day']));
        $directory = sys_get_temp_dir().'/supplier-versions-'.Str::uuid();
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach ($imports as $index => $import) {
                $code = <<<'CODE'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['price-list-imports.daily_versions' => true]);
touch($argv[2].'/ready-'.$argv[3]);
$deadline = microtime(true) + 10;
while (!is_file($argv[2].'/go')) {
    if (microtime(true) > $deadline) { exit(2); }
    usleep(10000);
}
$import = App\Models\PriceListImport::findOrFail((int) $argv[1]);
echo app(App\Services\SupplierVersions::class)->attach($import) ?? 'claimed';
CODE;
                $process = new Process([PHP_BINARY, '-r', $code, (string) $import->id, $directory, (string) $index], base_path(), null, null, 20);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) !== 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            touch($directory.'/go');
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $outcomes[] = $worker->getOutput();
            }
            sort($outcomes);
            $this->assertSame(['claimed', 'version_owned'], $outcomes);
            $this->assertSame(1, SupplierVersionClaim::where('supplier_organization_id', $supplier->id)->count());
            $winner = PriceListImport::findOrFail(SupplierVersionClaim::where('supplier_organization_id', $supplier->id)->value('price_list_import_id'));
            $this->assertNull(app(SupplierVersions::class)->attach($winner));
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            SupplierVersionClaim::where('supplier_organization_id', $supplier->id)->delete();
            $imports->each->delete();
            $supplier->delete();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_competing_cutovers_cannot_regress_the_successful_day(): void
    {
        if (getenv('SUPPLIER_VERSION_CONCURRENCY_TESTS') !== '1' || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Run against a migrated isolated PostgreSQL test database with SUPPLIER_VERSION_CONCURRENCY_TESTS=1.');
        }
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        $supplier = Organization::factory()->wholesaler()->create();
        $imports = collect([now()->subDays(2), now()->subDay()])->map(function ($date) use ($supplier): PriceListImport {
            $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => 'manual', 'source_published_at' => $date->startOfDay(), 'source_date_precision' => 'day', 'status' => 'preview', 'valid_rows' => 1]);
            $row = PriceListImportRow::factory()->for($import, 'import')->create(['parsed_values' => ['name' => 'Тест', 'normalized_name' => 'тест', 'normalized_sku' => null, 'sku' => null, 'price' => 10, 'quantity' => 5, 'total' => null, 'expiration' => null], 'disposition' => 'valid', 'planned_action' => 'create']);
            app(SupplierVersions::class)->attach($import);
            app(ImportActivator::class)->materialize($import, $row->id, $row->id);

            return $import;
        });
        $directory = sys_get_temp_dir().'/supplier-cutover-'.Str::uuid();
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach ($imports as $index => $import) {
                $code = <<<'CODE'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['price-list-imports.daily_versions' => true]);
touch($argv[2].'/ready-'.$argv[3]);
$deadline = microtime(true) + 10;
while (!is_file($argv[2].'/go')) {
    if (microtime(true) > $deadline) { exit(2); }
    usleep(10000);
}
try {
    app(App\Services\PriceList\ImportActivator::class)->commit(App\Models\PriceListImport::findOrFail((int) $argv[1]));
    echo 'activated';
} catch (Illuminate\Validation\ValidationException $exception) {
    if (!str_contains(json_encode($exception->errors()), 'already_imported_day')) { throw $exception; }
    echo 'skipped';
}
CODE;
                $worker = new Process([PHP_BINARY, '-r', $code, (string) $import->id, $directory, (string) $index], base_path(), null, null, 20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) !== 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            touch($directory.'/go');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $this->assertSame($imports->last()->id, $supplier->fresh()->active_price_list_import_id);
            $this->assertSame(now()->subDay()->toDateString(), $supplier->fresh()->successful_price_list_day);
            $this->assertSame('completed', SupplierVersionClaim::where('price_list_import_id', $imports->last()->id)->value('state'));
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            $supplier->update(['active_price_list_import_id' => null]);
            AuditEvent::where('subject_type', PriceListImport::class)->whereIn('subject_id', $imports->pluck('id')->all())->delete();
            SupplierVersionClaim::where('supplier_organization_id', $supplier->id)->delete();
            PriceListImportRow::whereIn('price_list_import_id', $imports->pluck('id')->all())->delete();
            Offer::where('organization_id', $supplier->id)->delete();
            SupplierProductAlias::where('supplier_organization_id', $supplier->id)->delete();
            SupplierProduct::where('supplier_organization_id', $supplier->id)->delete();
            Medicine::where('supplier_organization_id', $supplier->id)->delete();
            PriceListImport::where('supplier_organization_id', $supplier->id)->delete();
            $supplier->delete();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
