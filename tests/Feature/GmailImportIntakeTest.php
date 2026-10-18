<?php

namespace Tests\Feature;

use App\DataTransferObjects\StoredImportFile;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Enums\SupplierFileType;
use App\Jobs\FinalizePriceListImportPreview;
use App\Jobs\PreparePriceListImport;
use App\Jobs\ProcessGmailImportReceipt;
use App\Jobs\ProcessPriceListImportChunk;
use App\Jobs\SyncGmailImports;
use App\Models\GmailConnection;
use App\Models\GmailImportContentClaim;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Models\User;
use App\Services\GmailApiException;
use App\Services\GmailClientFactory;
use App\Services\GmailException;
use App\Services\GmailImportDiscovery;
use App\Services\GmailImportIntake;
use App\Services\GmailMailboxAccess;
use App\Services\ImportFileValidator;
use App\Services\PriceList\CategoryCandidateExtractor;
use App\Services\PriceList\ImportProcessor;
use App\Services\PriceList\ValueNormalizer;
use App\Services\PriceList\WorkbookReader;
use App\Services\SupplierPriceListIngestor;
use Google\Service\Exception as GoogleException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class GmailImportIntakeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test', 'services.google.client_secret' => 'test', 'services.google.redirect_uri' => 'http://localhost/callback', 'session.block_store' => 'array', 'gmail-imports.enabled' => true]);
    }

    private function mailbox(array $responses): GmailConnection
    {
        $connection = GmailConnection::factory()->create(['email' => 'imports@example.com']);
        config(['gmail-imports.connection_id' => $connection->id, 'gmail-imports.mailbox' => $connection->email]);
        $this->google($responses);

        return $connection;
    }

    private function google(array $responses): void
    {
        $responses = array_merge(...array_map(fn ($data): array => is_array($data) && isset($data['payload']) ? [$data, $data] : [$data], $responses ?: [[]]));
        $responses = array_map(fn ($data) => $data instanceof Response ? $data : new Response(200, ['Content-Type' => 'application/json'], json_encode($data)), $responses);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler): callable {
            return function ($request, array $options) use ($handler) {
                if (str_ends_with($request->getUri()->getPath(), '/messages')) {
                    parse_str($request->getUri()->getQuery(), $query);
                    $this->assertSame('INBOX', $query['labelIds']);
                    $this->assertSame('has:attachment', $query['q']);
                }

                return $handler($request, $options);
            };
        });
        $this->app->instance(GmailClientFactory::class, new GmailClientFactory(new Client(['handler' => $stack, 'http_errors' => false])));
    }

    private function supplier(): Organization
    {
        $profile = SupplierImportProfile::factory()->create(['file_type' => SupplierFileType::Csv]);
        SupplierSenderAddress::factory()->for($profile->supplier, 'supplier')->create(['email' => 'supplier@example.com', 'normalized_email' => 'supplier@example.com']);

        return $profile->supplier;
    }

    private function receipt(string $message = 'message-1'): GmailImportReceipt
    {
        return GmailImportReceipt::factory()->create(['mailbox_email' => 'imports@example.com', 'gmail_message_id' => $message]);
    }

    private function outcome(GmailImportReceipt $receipt): GmailImportReceipt
    {
        return $receipt->children()->first() ?? $receipt->fresh();
    }

    private function message(array $parts = [], string $from = 'Supplier <SUPPLIER@example.com>'): array
    {
        return ['id' => 'message-1', 'internalDate' => '1760000000000', 'labelIds' => ['INBOX'], 'payload' => ['headers' => [['name' => 'From', 'value' => $from]], 'parts' => $parts]];
    }

    private function part(string $filename = 'prices.csv', string $bytes = "name,price\nAspirin,10\n"): array
    {
        return ['partId' => '1', 'filename' => $filename, 'mimeType' => 'text/csv', 'body' => ['size' => strlen($bytes), 'data' => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=')]];
    }

    #[TestWith(['Supplier <A+list@Example.com>', 'a+list@example.com'])]
    #[TestWith(['"Supplier, Inc" <A@Example.com>', 'a@example.com'])]
    #[TestWith([' A@Example.com ', 'a@example.com'])]
    #[TestWith(['A@example.com, B@example.com', null])]
    #[TestWith(['Supplier <A@example.com>, Other <B@example.com>', null])]
    #[TestWith(['broken', null])]
    #[TestWith(['a@example.com <b@example.com>', null])]
    #[TestWith(['Group: <a@example.com>', null])]
    #[TestWith(["A@example.com\r\nFrom: b@example.com", null])]
    public function test_sender_uses_exactly_one_actual_mailbox(string $header, ?string $expected): void
    {
        $this->assertSame($expected, app(GmailImportIntake::class)->sender($header));
    }

    #[TestWith(['!!!'])]
    #[TestWith(['YQ='])]
    #[TestWith(['A'])]
    #[TestWith(['YR'])]
    #[TestWith(['YQ==='])]
    public function test_invalid_base64_is_rejected(string $data): void
    {
        $this->assertNull(app(GmailImportIntake::class)->decode($data));
    }

    #[TestWith(['unknown_supplier'])]
    #[TestWith(['missing_profile'])]
    #[TestWith(['inactive_profile'])]
    #[TestWith(['inactive_supplier'])]
    public function test_ineligible_sender_is_skipped_without_attachment_download(string $reason): void
    {
        if ($reason !== 'unknown_supplier') {
            $supplier = $this->supplier();
            if ($reason === 'missing_profile') {
                $supplier->importProfile()->delete();
            }
            if ($reason === 'inactive_profile') {
                $supplier->importProfile()->update(['is_active' => false]);
            }
            if ($reason === 'inactive_supplier') {
                $supplier->update(['status' => 'blocked']);
            }
        }
        $part = $this->part();
        $part['body'] = ['attachmentId' => 'remote', 'size' => 100];
        $this->mailbox([$this->message([$part])]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        if ($reason === 'unknown_supplier') {
            $this->assertSame('unknown_supplier', $receipt->fresh()->attachment_manifest[0]['reason']);
            $this->assertSame('routed', $receipt->fresh()->status);
        } else {
            $this->assertSame($reason, $this->outcome($receipt)->reason);
            $this->assertSame('skipped', $this->outcome($receipt)->status);
        }
    }

    public function test_nested_embedded_attachment_is_accepted_and_duplicate_email_reuses_import(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $this->supplier();
        $message = $this->message([['mimeType' => 'multipart/mixed', 'parts' => [$this->part(), $this->part('notes.txt')]]]);
        $this->mailbox([$message, $message]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $duplicate = $this->receipt('resent');
        app(GmailImportIntake::class)->process($duplicate);
        $this->assertSame('accepted', $this->outcome($receipt)->status);
        $this->assertSame('duplicate_content', $this->outcome($duplicate)->reason);
        $this->assertSame($this->outcome($receipt)->price_list_import_id, $this->outcome($duplicate)->price_list_import_id);
        $this->assertSame(1, PriceListImport::count());
        $this->assertSame(1, GmailImportContentClaim::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('price-list-imports'));
        $import = PriceListImport::sole();
        $this->assertSame('supplier@example.com', $import->sender_email);
        $this->assertSame('csv', $import->profile_snapshot['file_type']);
        Queue::assertPushed(PreparePriceListImport::class, 1);
    }

    #[TestWith(['multiple_candidates'])]
    #[TestWith(['no_candidates'])]
    #[TestWith(['invalid_attachment'])]
    public function test_attachment_rejections_are_terminal(string $reason): void
    {
        Storage::fake('local');
        $this->supplier();
        $parts = match ($reason) {
            'multiple_candidates' => [$this->part(), $this->part('second.csv')],
            'no_candidates' => [$this->part('other.xlsx')],
            default => [$this->part('prices.csv', "\x00\x01\x02\x03")],
        };
        $this->mailbox([$this->message($parts)]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $this->assertSame($reason, $this->outcome($receipt)->reason);
        $this->assertSame(0, PriceListImport::count());
        $this->assertSame([], Storage::disk('local')->allFiles('price-list-imports'));
    }

    public function test_remote_attachment_download_is_supported(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $this->supplier();
        $part = $this->part();
        $data = $part['body']['data'];
        unset($part['body']['data']);
        $part['body']['attachmentId'] = 'attachment-1';
        $this->mailbox([$this->message([$part]), ['data' => $data]]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $this->assertSame('accepted', $this->outcome($receipt)->status);
        Queue::assertPushed(PreparePriceListImport::class);
    }

    public function test_filtered_scan_pages_then_revisits_receipts_and_discovers_new_arrivals(): void
    {
        $connection = $this->mailbox([
            ['messages' => [['id' => 'one']], 'nextPageToken' => 'page-2'],
            ['messages' => [['id' => 'two']]],
            ['messages' => [['id' => 'one'], ['id' => 'two'], ['id' => 'three']]],
        ]);
        Queue::fake([ProcessGmailImportReceipt::class, SyncGmailImports::class]);
        $job = new SyncGmailImports;
        $job->handle(app(GmailImportDiscovery::class), app(GmailMailboxAccess::class));
        $this->assertSame('page-2', $connection->fresh()->scan_page);
        $this->assertNull($connection->fresh()->last_synced_at);
        Queue::assertPushed(SyncGmailImports::class, fn ($job): bool => $job->delay === 2);
        $job->handle(app(GmailImportDiscovery::class), app(GmailMailboxAccess::class));
        $this->assertNull($connection->fresh()->scan_page);
        $this->assertNotNull($connection->fresh()->last_synced_at);
        Queue::assertPushed(SyncGmailImports::class, 1);
        $job->handle(app(GmailImportDiscovery::class), app(GmailMailboxAccess::class));
        $this->assertSame(3, GmailImportReceipt::count());
        Queue::assertPushed(ProcessGmailImportReceipt::class, 3);
    }

    public function test_empty_attachment_search_creates_no_receipts_or_jobs(): void
    {
        Queue::fake();
        $connection = $this->mailbox([[]]);

        (new SyncGmailImports)->handle(app(GmailImportDiscovery::class), app(GmailMailboxAccess::class));

        $this->assertSame(0, GmailImportReceipt::count());
        $this->assertNotNull($connection->fresh()->last_synced_at);
        Queue::assertNothingPushed();
    }

    public function test_legacy_receipts_resume_only_when_found_by_filtered_search_and_unlocked(): void
    {
        Queue::fake([ProcessGmailImportReceipt::class]);
        $connection = $this->mailbox([['messages' => [['id' => 'eligible']]], ['messages' => [['id' => 'eligible']]], ['messages' => [['id' => 'eligible']]]]);
        $connection->update(['scan_page' => 'legacy', 'history_cursor' => '100']);
        $eligible = $this->receipt('eligible');
        $excluded = $this->receipt('excluded');
        $completed = $this->receipt('completed');
        $completed->update(['status' => 'completed']);
        $supplierWork = GmailImportReceipt::factory()->create(['receipt_scope' => 'supplier:1', 'parent_receipt_id' => $completed->id]);
        $migration = require database_path('migrations/2026_10_18_064841_reset_gmail_discovery_for_attachment_search.php');
        $migration->up();
        $this->assertNull($connection->fresh()->scan_page);
        $this->assertNull($connection->fresh()->history_cursor);
        $this->assertSame('attachment_search_required', $excluded->fresh()->reason);
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('pending', $supplierWork->fresh()->status);
        (new ProcessGmailImportReceipt($excluded->id))->handle(app(GmailImportIntake::class));
        $lock = Cache::store(config('session.block_store'))->lock('gmail-receipt:'.$eligible->id, 120);
        $lock->get();
        try {
            app(GmailImportDiscovery::class)->sync();
            $this->assertSame('skipped', $eligible->fresh()->status);
        } finally {
            $lock->release();
        }

        app(GmailImportDiscovery::class)->sync();
        app(GmailImportDiscovery::class)->sync();

        $this->assertSame('pending', $eligible->fresh()->status);
        $this->assertNull($eligible->fresh()->reason);
        $this->assertSame('skipped', $excluded->fresh()->status);
        Queue::assertPushed(ProcessGmailImportReceipt::class, fn ($job): bool => $job->receiptId === $eligible->id);
        Queue::assertPushed(ProcessGmailImportReceipt::class, 1);
    }

    public function test_reconnecting_to_another_mailbox_resets_cursor_and_preserves_receipts(): void
    {
        $connection = $this->mailbox([]);
        $connection->update(['history_cursor' => '100', 'scan_page' => 'page']);
        $this->receipt();
        $connection->update(['email' => 'another@example.com']);
        $this->assertNull($connection->fresh()->history_cursor);
        $this->assertNull($connection->fresh()->scan_page);
        $connection->delete();
        $this->assertSame(1, GmailImportReceipt::count());
    }

    public function test_blocked_owner_cannot_access_background_mailbox(): void
    {
        $connection = $this->mailbox([]);
        $connection->user->forceFill(['is_blocked' => true])->save();
        $this->expectException(GmailException::class);
        app(GmailMailboxAccess::class)->connection();
    }

    public function test_unexpected_unauthorized_refreshes_once(): void
    {
        $connection = $this->mailbox([new Response(401, [], json_encode(['error' => ['code' => 401]])), ['access_token' => 'refreshed', 'expires_in' => 3600], ['historyId' => '100']]);
        $profile = app(GmailMailboxAccess::class)->run(fn ($gmail) => $gmail->users->getProfile('me'));
        $this->assertSame('100', $profile->getHistoryId());
        $this->assertSame('refreshed', $connection->fresh()->access_token);
    }

    public function test_invalid_grant_suspends_polling(): void
    {
        $connection = $this->mailbox([new Response(400, [], json_encode(['error' => 'invalid_grant']))]);
        $connection->update(['expires_at' => now()->subMinute()]);
        try {
            app(GmailMailboxAccess::class)->run(fn () => null);
            $this->fail('Expected reconnect requirement');
        } catch (GmailException) {
        }
        $this->assertSame('reconnect_required', $connection->fresh()->status);
    }

    public function test_skip_requires_explicit_replay(): void
    {
        $this->mailbox([$this->message()]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $this->supplier();
        app(GmailImportIntake::class)->process($this->outcome($receipt));
        $this->assertSame('routed', $receipt->fresh()->status);
        $this->artisan('gmail-imports:replay', ['--receipt' => $receipt->id])->assertSuccessful();
        $this->assertSame('pending', $this->outcome($receipt)->status);
    }

    public function test_deleted_message_is_terminal_and_transient_error_remains_retryable(): void
    {
        $this->mailbox([new Response(404, [], json_encode(['error' => ['code' => 404]]))]);
        $receipt = $this->receipt();
        (new ProcessGmailImportReceipt($receipt->id))->handle(app(GmailImportIntake::class));
        $this->assertSame('message_unavailable', $this->outcome($receipt)->reason);
        $retry = $this->receipt('retry');
        $this->google([new Response(503, [], json_encode(['error' => ['code' => 503]]))]);
        try {
            (new ProcessGmailImportReceipt($retry->id))->handle(app(GmailImportIntake::class));
            $this->fail('Expected retry');
        } catch (GoogleException) {
        }
        $this->assertSame('pending', $retry->fresh()->status);
    }

    #[TestWith(['pending'])]
    #[TestWith(['failed'])]
    #[TestWith(['completed'])]
    public function test_existing_manual_import_in_any_status_protects_identical_content(string $status): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $supplier = $this->supplier();
        $bytes = "name,price\nAspirin,10\n";
        $existing = PriceListImport::factory()->for($supplier, 'supplier')->create(['sha256' => hash('sha256', $bytes), 'status' => $status, 'source_type' => PriceListImportSource::Manual]);
        $this->mailbox([$this->message([$this->part()])]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $this->assertSame($existing->id, $this->outcome($receipt)->price_list_import_id);
        $this->assertSame('duplicate_content', $this->outcome($receipt)->reason);
        $this->assertSame(1, PriceListImport::count());
        $this->assertSame([], Storage::disk('local')->allFiles('price-list-imports'));
        Queue::assertNothingPushed();
    }

    public function test_profile_changes_before_handoff_are_rechecked(): void
    {
        Storage::fake('local');
        $supplier = $this->supplier();
        $receipt = $this->receipt();
        $receipt->update(['supplier_organization_id' => $supplier->id, 'sender_email' => 'supplier@example.com', 'selected_part' => ['file_type' => 'csv']]);
        Storage::disk('local')->put('new.csv', 'content');
        $supplier->importProfile()->update(['is_active' => false]);
        $file = new StoredImportFile('local', 'new.csv', 'prices.csv', 'text/csv', 7, hash('sha256', 'content'));
        $result = app(SupplierPriceListIngestor::class)->ingestGmailReceipt($receipt, $file);
        $this->assertSame('skipped', $result['outcome']);
        $this->assertSame('inactive_profile', $this->outcome($receipt)->reason);
        Storage::disk('local')->assertMissing('new.csv');
        $this->assertSame(0, PriceListImport::count());
    }

    public function test_intake_publishes_using_profile_snapshot_and_receipt_tracks_completion(): void
    {
        Storage::fake('local');
        $supplier = $this->supplier();
        $this->mailbox([$this->message([$this->part()])]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $import = PriceListImport::sole();
        $this->assertSame(PriceListImportStatus::Completed, $import->status);
        $this->assertSame($import->id, $supplier->fresh()->active_price_list_import_id);
        Queue::fake([SyncGmailImports::class]);
        $this->artisan('gmail-imports:sync')->assertSuccessful();
        $this->assertSame('completed', $this->outcome($receipt)->status);
        Queue::assertPushed(SyncGmailImports::class);
    }

    public function test_duplicate_finalization_does_not_reset_completed_import(): void
    {
        $import = PriceListImport::factory()->create(['status' => PriceListImportStatus::Completed]);
        (new FinalizePriceListImportPreview($import))->handle(app(CategoryCandidateExtractor::class));
        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
    }

    public function test_interrupted_preparation_rebuilds_saved_chunk_chain(): void
    {
        Bus::fake();
        $import = PriceListImport::factory()->create(['status' => PriceListImportStatus::Processing, 'effective_layout' => ['data_row' => 2], 'summary' => ['highest_row' => 3]]);
        (new PreparePriceListImport($import))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));
        Bus::assertChained([ProcessPriceListImportChunk::class, FinalizePriceListImportPreview::class]);
        $this->assertSame(PriceListImportStatus::Processing, $import->fresh()->status);
    }

    public function test_reconciliation_redispatches_committed_receipt_and_import(): void
    {
        $this->freezeTime();
        Queue::fake();
        $this->mailbox([]);
        $receipt = $this->receipt();
        $receipt->forceFill(['updated_at' => now()->subMinutes(3)])->save(['timestamps' => false]);
        $import = PriceListImport::factory()->create(['updated_at' => now()->subMinutes(20)]);
        $linked = $this->receipt('linked');
        $linked->update(['status' => 'accepted', 'price_list_import_id' => $import->id]);
        $this->artisan('gmail-imports:sync')->assertSuccessful();
        Queue::assertPushed(ProcessGmailImportReceipt::class, fn ($job): bool => $job->receiptId === $receipt->id);
        Queue::assertPushed(PreparePriceListImport::class, fn ($job): bool => $job->import->id === $import->id);
        Queue::assertPushed(SyncGmailImports::class);
    }

    public function test_cleanup_preserves_referenced_and_recent_files(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        Storage::disk('local')->put('price-list-imports/old.csv', 'old');
        Storage::disk('local')->put('price-list-imports/kept.csv', 'kept');
        Storage::disk('local')->put('price-list-imports/recent.csv', 'recent');
        touch(Storage::disk('local')->path('price-list-imports/old.csv'), now()->subDays(2)->timestamp);
        touch(Storage::disk('local')->path('price-list-imports/kept.csv'), now()->subDays(2)->timestamp);
        PriceListImport::factory()->create(['file_path' => 'price-list-imports/kept.csv']);
        $this->artisan('gmail-imports:cleanup')->assertSuccessful();
        Storage::disk('local')->assertMissing('price-list-imports/old.csv');
        Storage::disk('local')->assertExists(['price-list-imports/kept.csv', 'price-list-imports/recent.csv']);
    }

    #[TestWith(['csv'])]
    #[TestWith(['tsv'])]
    #[TestWith(['xls'])]
    #[TestWith(['xlsx'])]
    public function test_supported_workbooks_pass_shared_validation(string $format): void
    {
        Storage::fake('local');
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['name', 'price'], ['Aspirin', 10]]);
        $path = Storage::disk('local')->path('sample.'.$format);
        $writer = IOFactory::createWriter($sheet, match ($format) {
            'xls' => 'Xls','xlsx' => 'Xlsx',default => 'Csv'
        });
        if ($format === 'tsv') {
            $writer->setDelimiter("\t");
        }
        $writer->save($path);
        $sheet->disconnectWorksheets();
        app(ImportFileValidator::class)->validate($path, 'sample.'.$format, SupplierFileType::from($format));
        $this->assertGreaterThan(0, filesize($path));
    }

    #[TestWith(['size'])]
    #[TestWith(['rows'])]
    #[TestWith(['columns'])]
    #[TestWith(['corrupt'])]
    public function test_attachment_resource_limits_are_enforced(string $limit): void
    {
        Storage::fake('local');
        $bytes = match ($limit) {
            'rows' => "a,b\nx,y\nz,w",'columns' => implode(',', range(1, 65)), 'corrupt' => 'This is not a workbook',default => str_repeat('x', 1025)
        };
        Storage::disk('local')->put('sample.csv', $bytes);
        if ($limit === 'size') {
            config(['price-list-imports.max_file_kilobytes' => 1]);
        }
        if ($limit === 'rows') {
            config(['price-list-imports.max_rows' => 2]);
        }
        $this->expectException(ValidationException::class);
        app(ImportFileValidator::class)->validate(Storage::disk('local')->path('sample.csv'), $limit === 'corrupt' ? 'sample.xlsx' : 'sample.csv', $limit === 'corrupt' ? SupplierFileType::Xlsx : SupplierFileType::Csv);
    }

    public function test_identical_bytes_for_different_suppliers_create_distinct_imports(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $this->supplier();
        $second = SupplierImportProfile::factory()->create(['file_type' => SupplierFileType::Csv]);
        SupplierSenderAddress::factory()->for($second->supplier, 'supplier')->create(['email' => 'other@example.com', 'normalized_email' => 'other@example.com']);
        $this->mailbox([$this->message([$this->part()]), $this->message([$this->part()], 'other@example.com')]);
        app(GmailImportIntake::class)->process($this->receipt());
        app(GmailImportIntake::class)->process($this->receipt('second'));
        $this->assertSame(2, PriceListImport::count());
        $this->assertSame(2, GmailImportContentClaim::count());
        Queue::assertPushed(PreparePriceListImport::class, 2);
    }

    public function test_transient_refresh_failure_preserves_credentials(): void
    {
        $connection = $this->mailbox([new Response(503, [], json_encode(['error' => 'temporarily_unavailable']))]);
        $connection->update(['expires_at' => now()->subMinute()]);
        try {
            app(GmailMailboxAccess::class)->run(fn () => null);
            $this->fail('Expected transient failure');
        } catch (GmailException|GoogleException) {
        }
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('test-refresh', $connection->fresh()->refresh_token);
    }

    public function test_retry_after_is_preserved_without_internal_background_retries(): void
    {
        $this->mailbox([new Response(429, ['Retry-After' => '600'], json_encode(['error' => ['errors' => [['reason' => 'rateLimitExceeded']]]]))]);
        try {
            app(GmailMailboxAccess::class)->run(fn ($gmail) => $gmail->users->getProfile('me'));
            $this->fail('Expected throttling');
        } catch (GmailApiException $exception) {
            $this->assertTrue($exception->retryable());
            $this->assertSame(600, $exception->retryAfter);
            $this->assertSame([['reason' => 'rateLimitExceeded']], $exception->getErrors());
        }
    }

    public function test_failure_during_next_page_keeps_receipts_and_checkpoint_for_retry(): void
    {
        Queue::fake([ProcessGmailImportReceipt::class]);
        $connection = $this->mailbox([['messages' => [['id' => 'one']], 'nextPageToken' => 'next'], new Response(503, [], json_encode(['error' => ['code' => 503]]))]);
        app(GmailImportDiscovery::class)->sync();
        try {
            app(GmailImportDiscovery::class)->sync();
            $this->fail('Expected retry');
        } catch (GoogleException) {
        }
        $this->assertSame('next', $connection->fresh()->scan_page);
        $this->assertSame(1, GmailImportReceipt::count());
        $this->google([[]]);
        app(GmailImportDiscovery::class)->sync();
        $this->assertNull($connection->fresh()->scan_page);
        $this->assertNotNull($connection->fresh()->last_synced_at);
        Queue::assertPushed(ProcessGmailImportReceipt::class, 1);
    }

    public function test_preview_has_no_import_or_receipt_side_effects(): void
    {
        $this->supplier();
        $this->mailbox([['messages' => [['id' => 'one']]], $this->message([$this->part()])]);
        $this->artisan('gmail-imports:preview')->expectsOutputToContain('eligible')->assertSuccessful();
        $this->assertSame(0, GmailImportReceipt::count());
        $this->assertSame(0, PriceListImport::count());
    }

    public function test_disabled_intake_neither_accesses_gmail_nor_queues_jobs(): void
    {
        Queue::fake();
        config(['gmail-imports.enabled' => false]);
        $this->artisan('gmail-imports:sync')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_inbox_membership_is_rechecked_before_processing(): void
    {
        $message = $this->message([$this->part()]);
        $message['labelIds'] = ['SENT'];
        $this->mailbox([$message]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $this->assertSame('not_in_inbox', $this->outcome($receipt)->reason);
    }

    public function test_declared_size_and_decoded_size_must_match(): void
    {
        $this->supplier();
        $part = $this->part();
        $part['body']['size'] = 999;
        $this->mailbox([$this->message([$part])]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $this->assertSame('invalid_attachment', $this->outcome($receipt)->reason);
    }

    public function test_saved_profile_remains_authoritative_after_profile_is_replaced(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $supplier = $this->supplier();
        $this->mailbox([$this->message([$this->part()])]);
        app(GmailImportIntake::class)->process($this->receipt());
        $import = PriceListImport::sole();
        $supplier->importProfile()->delete();
        SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => SupplierFileType::Xlsx, 'is_active' => false]);
        (new PreparePriceListImport($import))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));
        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        $this->assertSame('csv', $import->fresh()->profile_snapshot['file_type']);
        $this->assertSame('Aspirin', $import->fresh()->offers()->sole()->source_name);
        Queue::assertPushed(PreparePriceListImport::class, 1);
    }

    public function test_failed_gmail_import_keeps_its_identity_and_retries_existing_import(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $this->supplier();
        $message = $this->message([$this->part()]);
        $this->mailbox([$message, $message]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $import = PriceListImport::sole();
        $import->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'preparation']);
        $resent = $this->receipt('resent-failure');
        app(GmailImportIntake::class)->process($resent);
        $this->assertSame('duplicate_content', $this->outcome($resent)->reason);
        $this->assertSame($import->id, $this->outcome($resent)->price_list_import_id);
        config(['auth.admin_two_factor_enabled' => false]);
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor)->post(route('price-list-imports.retry', $import))->assertRedirect();
        (new PreparePriceListImport($import->fresh()))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));
        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        $this->assertSame(1, PriceListImport::count());
        $this->assertSame(1, GmailImportContentClaim::count());
        Queue::assertPushed(PreparePriceListImport::class, 2);
    }

    public function test_expired_full_scan_page_token_restarts_scan_and_preserves_receipts(): void
    {
        $connection = $this->mailbox([new Response(400, [], json_encode(['error' => ['code' => 400]])), []]);
        $connection->update(['scan_page' => 'expired']);
        $this->receipt();
        app(GmailImportDiscovery::class)->sync();
        $this->assertNull($connection->fresh()->scan_page);
        $this->assertNull($connection->fresh()->scan_baseline);
        $this->assertSame(1, GmailImportReceipt::count());
    }

    public function test_reconciliation_respects_provider_retry_delay(): void
    {
        $this->freezeTime();
        $this->mailbox([new Response(429, ['Retry-After' => '600'], json_encode(['error' => ['code' => 429]]))]);
        $receipt = $this->receipt();
        (new ProcessGmailImportReceipt($receipt->id))->handle(app(GmailImportIntake::class));
        $this->assertSame(now()->addMinutes(10)->timestamp, $this->outcome($receipt)->next_attempt_at->timestamp);
        $this->travel(3)->minutes();
        Queue::fake();
        $this->artisan('gmail-imports:sync')->assertSuccessful();
        Queue::assertNotPushed(ProcessGmailImportReceipt::class);
        Queue::assertPushed(SyncGmailImports::class);
        (new ProcessGmailImportReceipt($receipt->id))->handle(app(GmailImportIntake::class));
        $this->assertSame(1, $this->outcome($receipt)->attempts);
        $this->travel(8)->minutes();
        $this->google([$this->message()]);
        (new ProcessGmailImportReceipt($receipt->id))->handle(app(GmailImportIntake::class));
        $this->assertSame('routed', $receipt->fresh()->status);
        $this->assertNull($this->outcome($receipt)->next_attempt_at);
    }

    public function test_scheduled_polling_respects_discovery_retry_delay(): void
    {
        $this->freezeTime();
        $connection = $this->mailbox([new Response(503, ['Retry-After' => '900'], json_encode(['error' => ['code' => 503]]))]);
        $connection->update(['history_cursor' => '100']);
        (new SyncGmailImports)->handle(app(GmailImportDiscovery::class), app(GmailMailboxAccess::class));
        $this->assertSame(now()->addMinutes(15)->timestamp, $connection->fresh()->next_sync_at->timestamp);
        Queue::fake();
        $this->travel(5)->minutes();
        $this->artisan('gmail-imports:sync')->assertSuccessful();
        (new SyncGmailImports)->handle(app(GmailImportDiscovery::class), app(GmailMailboxAccess::class));
        Queue::assertNothingPushed();
        $this->travel(11)->minutes();
        $this->google([[]]);
        (new SyncGmailImports)->handle(app(GmailImportDiscovery::class), app(GmailMailboxAccess::class));
        $this->assertNull($connection->fresh()->next_sync_at);
        $this->assertNotNull($connection->fresh()->last_synced_at);
    }

    public function test_four_actual_intake_failures_are_terminal_even_after_recovery_dispatch(): void
    {
        $this->freezeTime();
        $this->mailbox(array_fill(0, 4, new Response(503, [], json_encode(['error' => ['code' => 503]]))));
        $receipt = $this->receipt();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                (new ProcessGmailImportReceipt($receipt->id))->handle(app(GmailImportIntake::class));
            } catch (GoogleException) {
            }
            $this->travel(6)->minutes();
        }
        $this->assertSame('failed', $this->outcome($receipt)->status);
        $this->assertSame(4, $this->outcome($receipt)->attempts);
        $this->assertSame('intake_failed', $this->outcome($receipt)->reason);
    }

    public function test_lock_contention_does_not_terminally_fail_an_unprocessed_receipt(): void
    {
        $receipt = $this->receipt();
        $lock = Cache::store(config('session.block_store'))->lock('gmail-receipt:'.$receipt->id, 120);
        $lock->get();
        try {
            $job = new ProcessGmailImportReceipt($receipt->id);
            $job->handle(app(GmailImportIntake::class));
            $job->failed(new \RuntimeException('Queue attempts exhausted by contention'));
        } finally {
            $lock->release();
        }
        $this->assertSame('pending', $this->outcome($receipt)->status);
        $this->assertSame(0, $this->outcome($receipt)->attempts);
    }

    public function test_discovery_can_dispatch_intake_after_releasing_mailbox_lock(): void
    {
        Storage::fake('local');
        $this->supplier();
        $this->mailbox([['messages' => [['id' => 'message-1']]], $this->message([$this->part()])]);
        app(GmailImportDiscovery::class)->sync();
        $this->assertSame(PriceListImportStatus::Completed, PriceListImport::sole()->status);
        $this->assertSame('routed', GmailImportReceipt::where('receipt_scope', 'message')->sole()->status);
        $this->assertSame('accepted', GmailImportReceipt::whereNotNull('parent_receipt_id')->sole()->status);
    }

    public function test_validation_applies_configured_csv_delimiter_when_counting_columns(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('wide.csv', implode('|', range(1, 65)));
        $this->expectException(ValidationException::class);
        app(ImportFileValidator::class)->validate(Storage::disk('local')->path('wide.csv'), 'wide.csv', SupplierFileType::Csv, ['csv' => ['delimiter' => '|']]);
    }

    public function test_replaying_a_chunk_preserves_the_first_valid_duplicate_row(): void
    {
        Storage::fake('local');
        Queue::fake([PreparePriceListImport::class]);
        $this->supplier();
        $this->mailbox([$this->message([$this->part('prices.csv', "name,price\nAspirin,10\nAspirin,10\n")])]);
        app(GmailImportIntake::class)->process($this->receipt());
        $import = PriceListImport::sole();
        $import->update(['status' => PriceListImportStatus::Processing]);
        $rows = [2 => ['A' => 'Aspirin', 'B' => 10], 3 => ['A' => 'Aspirin', 'B' => 10]];
        $processor = app(ImportProcessor::class);
        $processor->process($import, $rows);
        $processor->process($import, $rows);
        $this->assertSame('valid', $import->rows()->where('source_row', 2)->sole()->disposition->value);
        $this->assertSame('skipped', $import->rows()->where('source_row', 3)->sole()->disposition->value);
        Queue::assertPushed(PreparePriceListImport::class, 1);
    }

    #[TestWith(['empty'])]
    #[TestWith(['expanded'])]
    #[TestWith(['encrypted'])]
    public function test_unsafe_xlsx_workbooks_are_rejected(string $condition): void
    {
        gc_collect_cycles();
        Storage::fake('local');
        $sheet = new Spreadsheet;
        if ($condition !== 'empty') {
            $sheet->getActiveSheet()->setCellValue('A1', 'name');
        }
        $path = Storage::disk('local')->path('unsafe.xlsx');
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();
        if ($condition === 'expanded') {
            config(['price-list-imports.max_expanded_file_bytes' => 1024]);
        }
        if ($condition === 'encrypted') {
            $zip = new \ZipArchive;
            $zip->open($path);
            $zip->setPassword('test-password');
            $this->assertTrue($zip->setEncryptionName('xl/worksheets/sheet1.xml', \ZipArchive::EM_AES_256));
            $zip->close();
        }
        $this->expectException(ValidationException::class);
        app(ImportFileValidator::class)->validate($path, 'unsafe.xlsx', SupplierFileType::Xlsx);
    }

    public function test_deleted_attachment_is_recorded_as_unavailable(): void
    {
        $this->supplier();
        $part = $this->part();
        $part['body'] = ['attachmentId' => 'deleted', 'size' => 20];
        $this->mailbox([$this->message([$part]), new Response(404, [], json_encode(['error' => ['code' => 404]]))]);
        $receipt = $this->receipt();
        (new ProcessGmailImportReceipt($receipt->id))->handle(app(GmailImportIntake::class));
        $this->assertSame('skipped', $this->outcome($receipt)->status);
        $this->assertSame('attachment_unavailable', $this->outcome($receipt)->reason);
    }

    public function test_existing_supplier_message_constraint_is_handled_without_replacement_import(): void
    {
        Storage::fake('local');
        Queue::fake([PreparePriceListImport::class]);
        $supplier = $this->supplier();
        $existing = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => PriceListImportSource::Email, 'message_id' => 'message-1', 'sha256' => hash('sha256', 'other bytes')]);
        $this->mailbox([$this->message([$this->part()])]);
        $receipt = $this->receipt();
        app(GmailImportIntake::class)->process($receipt);
        $this->assertSame('duplicate_message', $this->outcome($receipt)->reason);
        $this->assertSame($existing->id, $this->outcome($receipt)->price_list_import_id);
        $this->assertSame(1, PriceListImport::count());
        $this->assertSame(0, GmailImportContentClaim::count());
        $this->assertSame([], Storage::disk('local')->allFiles('price-list-imports'));
        Queue::assertNothingPushed();
    }
}
