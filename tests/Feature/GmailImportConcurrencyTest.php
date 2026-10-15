<?php

namespace Tests\Feature;

use App\DataTransferObjects\StoredImportFile;
use App\Jobs\PreparePriceListImport;
use App\Models\GmailImportContentClaim;
use App\Models\GmailImportReceipt;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Services\SupplierPriceListIngestor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class GmailImportConcurrencyTest extends TestCase
{
    public function test_concurrent_receipts_claim_the_same_supplier_contents_once(): void
    {
        if (getenv('GMAIL_CONCURRENCY_TESTS') !== '1' || ! function_exists('pcntl_fork') || ! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Opt in with GMAIL_CONCURRENCY_TESTS=1 against a migrated, isolated MySQL/PostgreSQL test database.');
        }
        $database = DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', $database, 'Concurrency coverage requires an isolated test database.');
        $this->assertSame(0, DB::transactionLevel(), 'Forked workers must see committed fixtures.');
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $profile = SupplierImportProfile::factory()->create(['file_type' => 'csv']);
        $sender = SupplierSenderAddress::factory()->for($profile->supplier, 'supplier')->create();
        $mailbox = Str::uuid().'@example.com';
        $receiptIds = [];
        for ($i = 0; $i < 2; $i++) {
            $receiptIds[] = GmailImportReceipt::factory()->create(['mailbox_email' => $mailbox, 'gmail_message_id' => 'message-'.$i, 'sender_email' => $sender->normalized_email, 'supplier_organization_id' => $profile->supplier_organization_id, 'selected_part' => ['file_type' => 'csv']])->id;
            Storage::disk('local')->put('concurrent-'.$i.'.csv', "name,price\nAspirin,10\n");
        }
        $directory = sys_get_temp_dir().'/gmail-concurrency-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach ($receiptIds as $index => $id) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork test worker');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        $receipt = GmailImportReceipt::findOrFail($id);
                        touch($directory.'/ready-'.$index);
                        $deadline = microtime(true) + 10;
                        while (! is_file($directory.'/go')) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Barrier expired');
                            }
                            usleep(10000);
                        }
                        $bytes = Storage::disk('local')->get('concurrent-'.$index.'.csv');
                        $file = new StoredImportFile('local', 'concurrent-'.$index.'.csv', 'prices.csv', 'text/csv', strlen($bytes), hash('sha256', $bytes));
                        $result = app(SupplierPriceListIngestor::class)->ingestGmailReceipt($receipt, $file);
                        file_put_contents($directory.'/result-'.$index, $result['outcome']);
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($directory.'/result-'.$index, $exception::class);
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) !== 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            touch($directory.'/go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            $outcomes = [file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1')];
            sort($outcomes);
            $this->assertSame(['accepted', 'duplicate'], $outcomes);
            $this->assertSame(1, PriceListImport::where('supplier_organization_id', $profile->supplier_organization_id)->count());
            $this->assertSame(1, GmailImportContentClaim::where('supplier_organization_id', $profile->supplier_organization_id)->count());
            $this->assertSame(1, GmailImportReceipt::whereIn('id', $receiptIds)->distinct()->count('price_list_import_id'));
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            DB::purge();
            GmailImportReceipt::whereIn('id', $receiptIds)->delete();
            GmailImportContentClaim::where('supplier_organization_id', $profile->supplier_organization_id)->delete();
            PriceListImport::where('supplier_organization_id', $profile->supplier_organization_id)->delete();
            $sender->delete();
            $profile->delete();
            $profile->supplier->delete();
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
