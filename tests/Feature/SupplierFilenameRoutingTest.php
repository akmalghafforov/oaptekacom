<?php

namespace Tests\Feature;

use App\DataTransferObjects\StoredImportFile;
use App\Jobs\PreparePriceListImport;
use App\Jobs\ProcessGmailImportReceipt;
use App\Models\GmailConnection;
use App\Models\GmailImportContentClaim;
use App\Models\GmailImportReceipt;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Services\GmailClientFactory;
use App\Services\GmailImportIntake;
use App\Services\SupplierAttachmentResolver;
use App\Services\SupplierPriceListIngestor;
use Google\Service\Exception as GoogleException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SupplierFilenameRoutingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test', 'services.google.client_secret' => 'test', 'services.google.redirect_uri' => 'http://localhost/callback', 'session.block_store' => 'array', 'gmail-imports.enabled' => true]);
    }

    private function supplier(?string $filter): SupplierImportProfile
    {
        $profile = SupplierImportProfile::factory()->create(['file_type' => 'csv', 'attachment_filename_filter' => $filter]);
        SupplierSenderAddress::factory()->for($profile->supplier, 'supplier')->create(['email' => 'shared@example.com', 'normalized_email' => 'shared@example.com']);

        return $profile;
    }

    private function google(array $responses): void
    {
        $this->requests = [];
        $stack = HandlerStack::create(new MockHandler(array_map(fn ($data) => $data instanceof Response ? $data : new Response(200, ['Content-Type' => 'application/json'], json_encode($data)), $responses)));
        $stack->push(Middleware::history($this->requests));
        $this->app->instance(GmailClientFactory::class, new GmailClientFactory(new Client(['handler' => $stack, 'http_errors' => false])));
    }

    private function mailbox(array $responses): GmailImportReceipt
    {
        $connection = GmailConnection::factory()->create(['email' => 'imports@example.com']);
        config(['gmail-imports.connection_id' => $connection->id, 'gmail-imports.mailbox' => $connection->email]);
        $this->google($responses);

        return GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'message-1']);
    }

    private function part(string $filename, string $bytes = "name,price\nAspirin,10\n"): array
    {
        return ['partId' => $filename, 'filename' => $filename, 'body' => ['size' => strlen($bytes), 'data' => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=')]];
    }

    private function message(array $parts, string $sender = 'Shared <SHARED@example.com>'): array
    {
        return ['id' => 'message-1', 'internalDate' => '1760000000000', 'labelIds' => ['INBOX'], 'payload' => ['headers' => [['name' => 'From', 'value' => $sender]], 'parts' => $parts]];
    }

    #[TestWith(['ПРАЙС', 'ежедневный_прайс.CSV', true])]
    #[TestWith(['[a]*.csv', 'report-[A]*.CSV', true])]
    #[TestWith(['[a]*.csv', 'aaaa.csv', false])]
    #[TestWith(['%', 'daily.csv', false])]
    #[TestWith(['_', 'daily.csv', false])]
    #[TestWith(['med', 'MEDICINES.csv', true])]
    #[TestWith([null, 'anything.csv', true])]
    #[TestWith(['', 'anything.csv', true])]
    public function test_resolves_literal_case_insensitive_filename_substrings(?string $filter, string $filename, bool $matches): void
    {
        $profile = $this->supplier($filter);
        $resolver = app(SupplierAttachmentResolver::class);

        $this->assertSame($matches ? [$profile->supplier_organization_id] : [], $resolver->matchingSuppliers(' SHARED@EXAMPLE.COM ', $filename)->modelKeys());
        $this->assertSame([], $resolver->matchingSuppliers('different@example.com', $filename)->modelKeys());
    }

    public function test_shared_sender_routes_nested_attachments_to_separate_supplier_receipts(): void
    {
        Queue::fake();
        Storage::fake('local');
        $first = $this->supplier('alpha');
        $second = $this->supplier('БЕТА');
        $message = $this->message([['parts' => [$this->part('ALPHA.csv'), ['parts' => [$this->part('бета.csv')]]]], $this->part('unmatched.csv')]);
        $parent = $this->mailbox([$message, $message, $message]);

        app(GmailImportIntake::class)->process($parent);

        $this->assertSame('routed', $parent->fresh()->status);
        $this->assertSame(2, $parent->children()->count());
        $this->assertSame('unknown_supplier', $parent->fresh()->attachment_manifest[2]['reason']);
        $this->assertCount(1, $this->requests);
        $this->assertSame(0, PriceListImport::count());
        Queue::assertPushed(ProcessGmailImportReceipt::class, 2);
        foreach ($parent->children()->get() as $child) {
            (new ProcessGmailImportReceipt($child->id))->handle(app(GmailImportIntake::class));
            $this->assertSame('accepted', $child->fresh()->status, (string) $child->fresh()->reason);
        }
        $this->assertSame([$first->supplier_organization_id, $second->supplier_organization_id], PriceListImport::orderBy('id')->pluck('supplier_organization_id')->all());
        $this->assertSame(2, GmailImportContentClaim::count());
        Queue::assertPushed(PreparePriceListImport::class, 2);
        app(GmailImportIntake::class)->process($parent->fresh());
        $this->assertSame(3, GmailImportReceipt::count());
    }

    #[TestWith([null, false])]
    #[TestWith(['price', false])]
    #[TestWith(['price', true])]
    public function test_overlapping_filters_and_catch_all_are_ambiguous_before_eligibility(?string $secondFilter, bool $inactive): void
    {
        Queue::fake();
        $first = $this->supplier('price');
        $second = $this->supplier($secondFilter);
        if ($inactive) {
            $second->update(['is_active' => false]);
            $second->supplier->update(['status' => 'blocked']);
        }
        $part = $this->part('prices.csv');
        $part['body'] = ['attachmentId' => 'must-not-download', 'size' => 20];
        $parent = $this->mailbox([$this->message([$part])]);
        Log::spy();

        app(GmailImportIntake::class)->process($parent);

        $this->assertSame('ambiguous_supplier', $parent->fresh()->attachment_manifest[0]['reason']);
        $this->assertSame([$first->supplier_organization_id, $second->supplier_organization_id], $parent->fresh()->attachment_manifest[0]['supplier_ids']);
        $this->assertSame(0, $parent->children()->count());
        $this->assertCount(1, $this->requests);
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('error')->once()->with('Ambiguous Gmail attachment supplier', ['message_id' => 'message-1', 'sender' => 'shared@example.com', 'filename' => 'prices.csv', 'supplier_ids' => [$first->supplier_organization_id, $second->supplier_organization_id]]);
    }

    public function test_multiple_candidates_skip_only_the_affected_supplier(): void
    {
        Queue::fake();
        Storage::fake('local');
        $first = $this->supplier('alpha');
        $second = $this->supplier('beta');
        $message = $this->message([$this->part('alpha-1.csv'), $this->part('alpha-2.csv'), $this->part('beta.csv')]);
        $parent = $this->mailbox([$message, $message]);

        app(GmailImportIntake::class)->process($parent);
        $skipped = $parent->children()->where('supplier_organization_id', $first->supplier_organization_id)->sole();
        $accepted = $parent->children()->where('supplier_organization_id', $second->supplier_organization_id)->sole();
        (new ProcessGmailImportReceipt($accepted->id))->handle(app(GmailImportIntake::class));

        $this->assertSame('multiple_candidates', $skipped->reason);
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame($second->supplier_organization_id, PriceListImport::sole()->supplier_organization_id);
        Queue::assertPushed(ProcessGmailImportReceipt::class, 1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_attachment_validation_and_transient_failures_stay_on_their_child(bool $transient): void
    {
        Queue::fake();
        Storage::fake('local');
        $first = $this->supplier('alpha');
        $this->supplier('beta');
        $bad = $this->part('alpha.csv', "\x00\x01\x02\x03");
        if ($transient) {
            $bad['body'] = ['attachmentId' => 'remote', 'size' => 20];
        }
        $message = $this->message([$bad, $this->part('beta.csv')]);
        $parent = $this->mailbox([$message]);
        app(GmailImportIntake::class)->process($parent);
        $failed = $parent->children()->where('supplier_organization_id', $first->supplier_organization_id)->sole();
        $other = $parent->children()->whereKeyNot($failed->id)->sole();
        $responses = $transient ? [$message, new Response(503, [], json_encode(['error' => ['code' => 503]])), $message] : [$message, $message];
        $this->google($responses);

        try {
            (new ProcessGmailImportReceipt($failed->id))->handle(app(GmailImportIntake::class));
            $this->assertFalse($transient);
        } catch (GoogleException) {
            $this->assertTrue($transient);
        }
        (new ProcessGmailImportReceipt($other->id))->handle(app(GmailImportIntake::class));

        $this->assertSame($transient ? 'pending' : 'skipped', $failed->fresh()->status);
        $this->assertSame($transient ? null : 'invalid_attachment', $failed->fresh()->reason);
        $this->assertSame($transient, $failed->fresh()->next_attempt_at !== null);
        $this->assertSame('accepted', $other->fresh()->status);
        $this->assertSame('routed', $parent->fresh()->status);
        $this->assertSame(1, PriceListImport::count());
        Queue::assertPushed(PreparePriceListImport::class, 1);
    }

    #[TestWith(['filter'])]
    #[TestWith(['shared_sender'])]
    #[TestWith(['sender'])]
    public function test_handoff_rechecks_filename_and_sender_resolution(string $change): void
    {
        Queue::fake();
        Storage::fake('local');
        $profile = $this->supplier('alpha');
        $parent = $this->mailbox([$this->message([$this->part('alpha.csv')])]);
        app(GmailImportIntake::class)->process($parent);
        $child = $parent->children()->sole();
        $child->update(['selected_part' => ['file_type' => 'csv', 'filename' => 'alpha.csv']]);
        if ($change === 'filter') {
            $profile->update(['attachment_filename_filter' => 'beta']);
        } elseif ($change === 'sender') {
            $profile->supplier->senderAddresses()->delete();
        } else {
            $this->supplier(null);
        }
        Storage::disk('local')->put('handoff.csv', 'content');
        $file = new StoredImportFile('local', 'handoff.csv', 'alpha.csv', 'text/csv', 7, hash('sha256', 'content'));

        $result = app(SupplierPriceListIngestor::class)->ingestGmailReceipt($child, $file);

        $this->assertSame('skipped', $result['outcome']);
        $this->assertSame($change === 'sender' ? 'sender_mapping_changed' : 'supplier_resolution_changed', $child->fresh()->reason);
        $this->assertSame(0, PriceListImport::count());
        Storage::disk('local')->assertMissing('handoff.csv');
    }

    public function test_email_ingestion_rejects_ambiguity_instead_of_selecting_the_first_supplier(): void
    {
        $this->supplier('alpha');
        $this->supplier(null);
        $file = new StoredImportFile('local', 'file.csv', 'alpha.csv', 'text/csv', 7, hash('sha256', 'content'));

        $this->expectException(ValidationException::class);
        app(SupplierPriceListIngestor::class)->ingestFromEmail($file, 'shared@example.com');
    }

    public function test_explicit_parent_replay_reconsiders_unresolved_routing_and_preserves_accepted_children(): void
    {
        Queue::fake();
        Storage::fake('local');
        $acceptedProfile = $this->supplier('alpha');
        $failedProfile = $this->supplier('beta');
        $message = $this->message([$this->part('alpha.csv'), $this->part('beta-1.csv'), $this->part('beta-2.csv'), $this->part('gamma.csv')]);
        $parent = $this->mailbox([$message, $message]);
        app(GmailImportIntake::class)->process($parent);
        $accepted = $parent->children()->where('supplier_organization_id', $acceptedProfile->supplier_organization_id)->sole();
        (new ProcessGmailImportReceipt($accepted->id))->handle(app(GmailImportIntake::class));
        $acceptedState = $accepted->fresh()->getAttributes();
        $failed = $parent->children()->where('supplier_organization_id', $failedProfile->supplier_organization_id)->sole();
        $failed->update(['status' => 'failed', 'attempts' => 4]);
        $failedProfile->update(['attachment_filename_filter' => 'beta-1']);
        $newProfile = $this->supplier('gamma');
        $this->google([$message, $message, $message]);

        $this->artisan('gmail-imports:replay', ['--receipt' => $parent->id])->assertSuccessful();
        app(GmailImportIntake::class)->process($parent->fresh());
        foreach ($parent->children()->where('status', 'pending')->get() as $child) {
            (new ProcessGmailImportReceipt($child->id))->handle(app(GmailImportIntake::class));
        }

        $this->assertSame($acceptedState, $accepted->fresh()->getAttributes());
        $this->assertSame('accepted', $failed->fresh()->status);
        $this->assertSame(1, $failed->fresh()->attempts);
        $this->assertSame('accepted', $parent->children()->where('supplier_organization_id', $newProfile->supplier_organization_id)->sole()->status);
        $this->assertSame(3, PriceListImport::count());
        Queue::assertPushed(PreparePriceListImport::class, 3);
    }

    public function test_parent_replay_preserves_every_import_linked_child(): void
    {
        Queue::fake();
        $parent = $this->mailbox([$this->message([])]);
        $parent->update(['status' => 'routed']);
        $children = [];
        foreach (['accepted', 'duplicate', 'completed', 'failed'] as $status) {
            $import = PriceListImport::factory()->create();
            $children[] = GmailImportReceipt::factory()->create(['mailbox_email' => $parent->mailbox_email, 'gmail_message_id' => $parent->gmail_message_id, 'receipt_scope' => 'supplier:'.$import->supplier_organization_id, 'parent_receipt_id' => $parent->id, 'price_list_import_id' => $import->id, 'status' => $status])->refresh();
        }
        $before = array_map(fn ($child) => $child->getAttributes(), $children);

        $this->artisan('gmail-imports:replay', ['--receipt' => $parent->id])->assertSuccessful();
        app(GmailImportIntake::class)->process($parent->fresh());

        $this->assertSame($before, array_map(fn ($child) => $child->fresh()->getAttributes(), $children));
        Queue::assertNothingPushed();
    }

    public function test_preview_uses_attachment_routing_without_receipts_or_attachment_data(): void
    {
        Queue::fake();
        $this->supplier('alpha');
        $this->supplier('beta');
        $part = $this->part('alpha.csv');
        $part['body'] = ['attachmentId' => 'remote', 'size' => 20];
        $this->mailbox([['messages' => [['id' => 'message-1']]], $this->message([$part, $this->part('beta.csv'), $this->part('unmatched.csv')])])->delete();

        $this->artisan('gmail-imports:preview')->expectsOutputToContain('eligible')->expectsOutputToContain('unknown_supplier')->assertSuccessful();

        $this->assertSame(0, GmailImportReceipt::count());
        $this->assertSame(0, PriceListImport::count());
        $this->assertCount(2, $this->requests);
        parse_str($this->requests[1]['request']->getUri()->getQuery(), $query);
        $this->assertStringContainsString('body(size,attachmentId)', $query['fields']);
        $this->assertStringNotContainsString('data', $query['fields']);
        Queue::assertNothingPushed();
    }

    public function test_child_dispatch_occurs_after_committing_and_releasing_mailbox_lock(): void
    {
        Storage::fake('local');
        $this->supplier('alpha');
        $message = $this->message([$this->part('alpha.csv')]);
        $parent = $this->mailbox([$message, $message]);
        Queue::fake([PreparePriceListImport::class]);

        app(GmailImportIntake::class)->process($parent);

        $this->assertSame('routed', $parent->fresh()->status);
        $this->assertSame('accepted', $parent->children()->sole()->status);
        Queue::assertPushed(PreparePriceListImport::class, 1);
        $lock = Cache::store('array')->lock('gmail-owner:'.GmailConnection::sole()->user_id, 300);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_child_dispatch_failure_does_not_prevent_other_suppliers_from_importing(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $first = $this->supplier('alpha');
        $this->supplier('beta');
        $message = $this->message([$this->part('alpha.csv'), $this->part('beta.csv')]);
        $parent = $this->mailbox([$message, new Response(503, [], json_encode(['error' => ['code' => 503]])), $message]);

        app(GmailImportIntake::class)->process($parent);

        $failed = $parent->children()->where('supplier_organization_id', $first->supplier_organization_id)->sole();
        $other = $parent->children()->whereKeyNot($failed->id)->sole();
        $this->assertSame('pending', $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertNotNull($failed->next_attempt_at);
        $this->assertSame('accepted', $other->status);
        $this->assertSame('routed', $parent->fresh()->status);
        Queue::assertPushed(PreparePriceListImport::class, 1);
    }

    public function test_failed_routing_transaction_neither_keeps_children_nor_dispatches_them(): void
    {
        Queue::fake();
        $this->supplier('alpha');
        $parent = $this->mailbox([$this->message([$this->part('alpha.csv')])]);
        $event = 'eloquent.updating: '.GmailImportReceipt::class;
        Event::listen($event, function (GmailImportReceipt $receipt): void {
            if ($receipt->receipt_scope === 'message' && $receipt->status === 'routed') {
                throw new RuntimeException('Routing transaction interrupted');
            }
        });
        try {
            app(GmailImportIntake::class)->process($parent);
            $this->fail('Expected routing to fail before commit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Routing transaction interrupted', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame('pending', $parent->fresh()->status);
        $this->assertSame(0, $parent->children()->count());
        Queue::assertNothingPushed();
    }

    public function test_child_rechecks_resolution_before_downloading_an_attachment(): void
    {
        Queue::fake();
        $profile = $this->supplier('alpha');
        $part = $this->part('alpha.csv');
        $part['body'] = ['attachmentId' => 'must-not-download', 'size' => 20];
        $message = $this->message([$part]);
        $parent = $this->mailbox([$message, $message]);
        app(GmailImportIntake::class)->process($parent);
        $profile->update(['attachment_filename_filter' => 'beta']);
        $child = $parent->children()->sole();

        (new ProcessGmailImportReceipt($child->id))->handle(app(GmailImportIntake::class));

        $this->assertSame('supplier_resolution_changed', $child->fresh()->reason);
        $this->assertCount(2, $this->requests);
        $this->assertSame(0, PriceListImport::count());
        Queue::assertNotPushed(PreparePriceListImport::class);
    }

    public function test_preview_expands_deeply_nested_metadata_without_requesting_file_bytes(): void
    {
        Queue::fake();
        $this->supplier('alpha');
        $nested = $this->part('alpha.csv');
        $nested['body'] = ['attachmentId' => 'must-not-download', 'size' => 20];
        for ($depth = 0; $depth < 6; $depth++) {
            $nested = ['partId' => 'level-'.$depth, 'parts' => [$nested]];
        }
        $message = $this->message([$nested]);
        $this->mailbox([['messages' => [['id' => 'message-1']]], $message, $message])->delete();

        $this->artisan('gmail-imports:preview')->expectsOutputToContain('eligible')->assertSuccessful();

        $this->assertCount(3, $this->requests);
        foreach (array_slice($this->requests, 1) as $request) {
            parse_str($request['request']->getUri()->getQuery(), $query);
            $this->assertStringNotContainsString('data', $query['fields']);
        }
        $this->assertSame(0, GmailImportReceipt::count());
        Queue::assertNothingPushed();
    }

    public function test_supplier_scoped_replay_and_reconciliation_retry_only_the_failed_child(): void
    {
        Queue::fake();
        $first = $this->supplier('alpha');
        $this->supplier('beta');
        $message = $this->message([$this->part('alpha.csv'), $this->part('beta.csv')]);
        $parent = $this->mailbox([$message]);
        app(GmailImportIntake::class)->process($parent);
        $failed = $parent->children()->where('supplier_organization_id', $first->supplier_organization_id)->sole();
        $other = $parent->children()->whereKeyNot($failed->id)->sole();
        $failed->update(['status' => 'failed', 'attempts' => 4, 'reason' => 'intake_failed']);
        $other->update(['status' => 'completed']);
        Queue::fake();

        $this->artisan('gmail-imports:replay', ['--supplier' => $first->supplier_organization_id, '--from' => '2025-10-01', '--to' => '2025-11-01'])->assertSuccessful();
        $this->assertSame('pending', $failed->fresh()->status);
        $this->assertSame(0, $failed->fresh()->attempts);
        $this->assertSame('completed', $other->fresh()->status);
        $this->assertSame('routed', $parent->fresh()->status);
        $failed->fresh()->forceFill(['updated_at' => now()->subMinutes(3)])->save(['timestamps' => false]);
        $this->artisan('gmail-imports:sync')->assertSuccessful();

        Queue::assertPushed(ProcessGmailImportReceipt::class, 1);
        Queue::assertPushed(ProcessGmailImportReceipt::class, fn ($job): bool => $job->receiptId === $failed->id);
        $this->artisan('gmail-imports:status')->expectsOutputToContain('message')->expectsOutputToContain('supplier:'.$first->supplier_organization_id)->assertSuccessful();
    }

    public function test_email_ingestion_selects_the_unique_filtered_supplier_from_a_shared_sender(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->supplier('alpha');
        $second = $this->supplier('beta');
        Storage::disk('local')->put('beta.csv', "name,price\nAspirin,10\n");
        $file = new StoredImportFile('local', 'beta.csv', 'BETA.csv', 'text/csv', 21, hash('sha256', "name,price\nAspirin,10\n"));

        $import = app(SupplierPriceListIngestor::class)->ingestFromEmail($file, 'SHARED@example.com', 'direct-email');

        $this->assertSame($second->supplier_organization_id, $import->supplier_organization_id);
        Queue::assertPushed(PreparePriceListImport::class, 1);
    }
}
