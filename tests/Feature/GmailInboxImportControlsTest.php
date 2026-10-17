<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Jobs\ProcessGmailImportReceipt;
use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Models\User;
use App\Services\GmailClientFactory;
use App\Services\GmailImportIntake;
use App\Services\PriceList\ProfileValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class GmailInboxImportControlsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test', 'services.google.client_secret' => 'test', 'services.google.redirect_uri' => 'http://localhost/callback', 'auth.admin_two_factor_enabled' => false, 'session.block_store' => 'array']);
    }

    private function mailbox(): GmailConnection
    {
        $connection = GmailConnection::factory()->create();
        config(['gmail-imports.enabled' => true, 'gmail-imports.connection_id' => $connection->id, 'gmail-imports.mailbox' => $connection->email]);
        $this->actingAs($connection->user);

        return $connection;
    }

    private function google(array $responses, ?array &$requests = null): void
    {
        $requests = [];
        $stack = HandlerStack::create(new MockHandler(array_map(fn ($data) => $data instanceof Response ? $data : new Response(200, ['Content-Type' => 'application/json'], json_encode($data)), $responses)));
        $stack->push(Middleware::history($requests));
        $this->app->instance(GmailClientFactory::class, new GmailClientFactory(new Client(['handler' => $stack, 'http_errors' => false])));
    }

    private function message(array $parts = []): array
    {
        return ['id' => 'abc', 'labelIds' => ['INBOX'], 'internalDate' => '1720000000000', 'payload' => ['mimeType' => 'multipart/mixed', 'headers' => [['name' => 'From', 'value' => 'Supplier <PRICE@EXAMPLE.COM>']], 'parts' => $parts ?: [
            ['partId' => '0.1', 'filename' => 'prices.csv', 'mimeType' => 'text/csv', 'body' => ['attachmentId' => 'file', 'size' => strlen("Name,Price\nAspirin,12\n")]],
        ]]];
    }

    private function stage(Organization $supplier): string
    {
        $this->google([$this->message(), ['data' => rtrim(strtr(base64_encode("Name,Price\nAspirin,12\n"), '+/', '-_'), '=')]], $requests);
        $response = $this->post(route('admin.gmail.import-profile', 'abc'), ['part_id' => '0.1', 'supplier_organization_id' => $supplier->id])->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('/attachments/file', $requests[1]['request']->getUri()->getPath());

        return $query['gmail_draft'];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_gmail_sample_is_reviewed_privately_and_saved_with_routing(bool $existing): void
    {
        Storage::fake('local');
        $this->mailbox();
        $supplier = Organization::factory()->wholesaler()->create();
        $profile = $existing ? SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['configuration' => array_replace(ProfileValidator::defaults(), ['mapping' => ['name' => 'A', 'price' => 'B']])]) : null;
        SupplierSenderAddress::factory()->for($supplier, 'supplier')->create(['email' => 'old@example.com', 'normalized_email' => 'old@example.com']);
        $before = $profile?->fresh()->getAttributes();
        $token = $this->stage($supplier);
        $draft = session('gmail_profile_drafts.'.$token);

        $this->assertSame($before, $profile?->fresh()->getAttributes());
        if (! $existing) {
            $this->assertNull($supplier->importProfile()->first());
        }
        $this->get(route('admin.supplier-import-profiles.edit', ['supplier' => $supplier, 'gmail_draft' => $token]))->assertOk()->assertSee('price@example.com')->assertSee('old@example.com')->assertSee('prices.csv')->assertSee('Отменить и вернуться')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(route('admin.supplier-import-profiles.sample.preview', ['supplier' => $supplier, 'gmail_draft' => $token, 'worksheet' => 'Worksheet']))->assertOk()->assertJsonPath('rows.1.cells.A', 'Aspirin');
        $this->put(route('admin.supplier-import-profiles.update', $supplier), [
            'gmail_draft' => $token, 'name' => 'Gmail profile', 'file_type' => 'csv', 'worksheet' => 'Worksheet', 'data_row' => 2,
            'column_mappings' => ['A' => 'name', 'B' => 'price'], 'decimal_separator' => '.', 'matching_strategy' => 'name',
            'sender_emails' => "old@example.com\nprice@example.com", 'attachment_filename_filter' => 'prices',
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('admin.gmail.show', 'abc');

        $saved = $supplier->importProfile()->firstOrFail();
        $this->assertTrue($saved->is_active);
        $this->assertSame('prices', $saved->attachment_filename_filter);
        $this->assertSame(['name' => 'A', 'price' => 'B'], $saved->configuration['mapping']);
        $this->assertSame(2, $supplier->senderAddresses()->count());
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_import_profile.updated', 'subject_id' => $saved->id]);
        Storage::disk('local')->assertMissing($draft['sample']['path']);
        Storage::disk('local')->assertExists($saved->sample_metadata['path']);
        $this->assertNull(session('gmail_profile_drafts.'.$token));
    }

    public function test_abandoned_draft_expires_without_mutating_active_profile_and_cleanup_removes_it(): void
    {
        Storage::fake('local');
        $this->mailbox();
        $supplier = Organization::factory()->wholesaler()->create();
        $profile = SupplierImportProfile::factory()->for($supplier, 'supplier')->create();
        $before = $profile->fresh()->getAttributes();
        $token = $this->stage($supplier);
        $path = session('gmail_profile_drafts.'.$token)['sample']['path'];
        $this->travel(25)->hours();
        touch(Storage::disk('local')->path($path), now()->subHours(25)->timestamp);
        $this->get(route('admin.supplier-import-profiles.edit', ['supplier' => $supplier, 'gmail_draft' => $token]))->assertStatus(410);
        $this->artisan('gmail-imports:cleanup')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    #[TestWith(['missing'])]
    #[TestWith(['oversized'])]
    #[TestWith(['malformed'])]
    public function test_invalid_selected_attachment_cannot_create_a_profile(string $failure): void
    {
        Storage::fake('local');
        $this->mailbox();
        $supplier = Organization::factory()->wholesaler()->create();
        $message = $this->message();
        if ($failure === 'oversized') {
            $message['payload']['parts'][0]['body']['size'] = config('price-list-imports.max_file_kilobytes') * 1024 + 1;
        }
        $this->google($failure === 'malformed' ? [$message, ['data' => '%%%']] : [$message], $requests);
        $this->post(route('admin.gmail.import-profile', 'abc'), ['part_id' => $failure === 'missing' ? 'gone' : '0.1', 'supplier_organization_id' => $supplier->id])->assertSessionHasErrors('part_id');
        $this->assertDatabaseCount('supplier_import_profiles', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('gmail-profile-drafts'));
        $this->assertCount($failure === 'malformed' ? 2 : 1, $requests);
    }

    public function test_replay_before_discovery_is_durable_and_repeated_submissions_preserve_processing(): void
    {
        Queue::fake();
        $connection = $this->mailbox();
        $this->post(route('admin.gmail.replay', 'abc'))->assertRedirectToRoute('admin.gmail.show', 'abc')->assertSessionHas('success');
        $receipt = GmailImportReceipt::sole();
        $receipt->update(['attempts' => 2, 'next_attempt_at' => now()->addMinute()]);
        $this->post(route('admin.gmail.replay', 'abc'))->assertSessionHas('success');
        Queue::assertPushed(ProcessGmailImportReceipt::class, 1);
        $this->assertSame(2, $receipt->fresh()->attempts);
        $this->assertTrue($receipt->fresh()->next_attempt_at->isFuture());
        $this->assertSame(strtolower($connection->email), $receipt->mailbox_email);
    }

    public function test_status_uses_current_import_for_duplicate_and_does_not_affect_other_parts_or_mailboxes(): void
    {
        $connection = $this->mailbox();
        $import = PriceListImport::factory()->create(['status' => PriceListImportStatus::Failed]);
        $manifest = [
            ['part_id' => '0.1', 'filename' => 'same.csv', 'size' => 10, 'supplier_ids' => [$import->supplier_organization_id], 'reason' => null],
            ['part_id' => '0.2', 'filename' => 'same.csv', 'size' => 10, 'supplier_ids' => [], 'reason' => 'unknown_supplier'],
        ];
        GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'abc', 'receipt_scope' => 'message', 'status' => 'routed', 'attachment_manifest' => $manifest]);
        GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'abc', 'receipt_scope' => 'supplier:'.$import->supplier_organization_id, 'status' => 'duplicate', 'price_list_import_id' => $import->id, 'selected_part' => ['part_id' => '0.1'], 'attachment_manifest' => $manifest]);
        GmailImportReceipt::factory()->create(['mailbox_email' => 'other@example.com', 'gmail_message_id' => 'abc', 'status' => 'failed']);
        $this->getJson(route('admin.gmail.import-status', 'abc'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('active', false)->assertJsonPath('attachments.0.outcome.status', 'failed')->assertJsonPath('attachments.0.outcome.duplicate', true)->assertJsonPath('attachments.0.outcome.import_id', $import->id)->assertJsonPath('attachments.1.outcome.label', 'Не импортировано');
        $import->update(['status' => PriceListImportStatus::Completed]);
        $this->getJson(route('admin.gmail.import-status', 'abc'))->assertJsonPath('attachments.0.outcome.label', 'Импортировано');
    }

    public function test_other_mailbox_and_disabled_intake_cannot_use_actions_or_status(): void
    {
        $connection = $this->mailbox();
        $other = GmailConnection::factory()->create();
        $this->actingAs($other->user)->post(route('admin.gmail.replay', 'abc'))->assertForbidden();
        $this->getJson(route('admin.gmail.import-status', 'abc'))->assertForbidden();
        $this->post(route('admin.gmail.import-profile', 'abc'))->assertForbidden();
        $this->actingAs($connection->user);
        config(['gmail-imports.enabled' => false]);
        $this->post(route('admin.gmail.replay', 'abc'))->assertForbidden();
        $this->assertDatabaseCount('gmail_import_receipts', 0);
    }

    public function test_inbox_renders_id_first_and_fetches_deep_attachment_metadata_without_downloading_files(): void
    {
        $this->mailbox();
        $part = ['partId' => '0.1.1.1.1.1.1', 'filename' => '<prices>.csv', 'body' => ['size' => 42, 'attachmentId' => 'never-download']];
        for ($depth = 0; $depth < 7; $depth++) {
            $part = ['partId' => 'container-'.$depth, 'mimeType' => 'multipart/mixed', 'parts' => [$part]];
        }
        $full = $this->message([$part]);
        $boundary = "--batch\r\nContent-Type: application/http\r\nContent-ID: response-abc\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n".json_encode($full)."\r\n--batch--\r\n";
        $this->google([['messages' => [['id' => 'abc']]], new Response(200, ['Content-Type' => 'multipart/mixed; boundary=batch'], $boundary), $full], $requests);
        $this->get(route('admin.gmail.index'))->assertOk()->assertViewHas('error', function ($error): bool {
            $this->assertNull($error);

            return true;
        })->assertSeeInOrder(['<th>ID</th>', '<th>Статус</th>', '<th>Вложения</th>'], false)->assertSee('Вложения: 1')->assertSee('&lt;prices&gt;.csv', false)->assertSee('42 байт')->assertSee('Настроить импорт');
        $this->assertCount(3, $requests);
        $batchRequest = urldecode((string) $requests[1]['request']->getBody());
        $this->assertStringContainsString('body(size,attachmentId)', $batchRequest);
        $this->assertStringNotContainsString('body(data', $batchRequest);
        foreach ($requests as $entry) {
            $this->assertStringNotContainsString('/attachments/', (string) $entry['request']->getUri());
        }
    }

    #[TestWith(['pending'])]
    #[TestWith(['completed'])]
    #[TestWith(['failed'])]
    public function test_replay_keeps_existing_linked_import_and_receipt_outcome(string $status): void
    {
        Queue::fake();
        $connection = $this->mailbox();
        $import = PriceListImport::factory()->create(['status' => $status]);
        $parent = GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'abc', 'status' => 'routed']);
        $child = GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'abc', 'receipt_scope' => 'supplier:'.$import->supplier_organization_id, 'parent_receipt_id' => $parent->id, 'supplier_organization_id' => $import->supplier_organization_id, 'price_list_import_id' => $import->id, 'status' => 'accepted', 'attempts' => 1]);
        $before = $child->fresh()->getAttributes();
        $this->post(route('admin.gmail.replay', 'abc'))->assertSessionHas('success');
        $this->assertSame($before, $child->fresh()->getAttributes());
        $this->assertSame($status, $import->fresh()->status->value);
        Queue::assertPushed(ProcessGmailImportReceipt::class, fn ($job): bool => $job->receiptId === $parent->id);
        $this->assertDatabaseCount('price_list_imports', 1);
    }

    public function test_replay_reconsiders_changed_routing_and_resets_failed_child_without_touching_pending_retry(): void
    {
        Queue::fake();
        $connection = $this->mailbox();
        $supplier = Organization::factory()->wholesaler()->create();
        SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv']);
        SupplierSenderAddress::factory()->for($supplier, 'supplier')->create(['email' => 'price@example.com', 'normalized_email' => 'price@example.com']);
        $parent = GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'abc', 'status' => 'routed']);
        $child = GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'abc', 'receipt_scope' => 'supplier:'.$supplier->id, 'supplier_organization_id' => $supplier->id, 'parent_receipt_id' => $parent->id, 'status' => 'failed', 'attempts' => 4]);
        $this->post(route('admin.gmail.replay', 'abc'))->assertSessionHas('success');
        $this->google([$this->message()]);
        (new ProcessGmailImportReceipt($parent->id))->handle(app(GmailImportIntake::class));
        $this->assertSame('pending', $child->fresh()->status);
        $this->assertSame(0, $child->fresh()->attempts);
        $child->update(['attempts' => 2, 'next_attempt_at' => now()->addMinutes(5)]);
        $before = $child->fresh()->getAttributes();
        $this->post(route('admin.gmail.replay', 'abc'))->assertSessionHas('success');
        $this->google([$this->message()]);
        (new ProcessGmailImportReceipt($parent->id))->handle(app(GmailImportIntake::class));
        $this->assertSame($before, $child->fresh()->getAttributes());
        Queue::assertPushed(ProcessGmailImportReceipt::class, 3);
    }

    public function test_replay_respects_receipt_lock_and_reports_dispatch_failure_with_durable_receipt(): void
    {
        $connection = $this->mailbox();
        $receipt = GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'locked', 'status' => 'skipped']);
        $lock = Cache::store('array')->lock('gmail-receipt:'.$receipt->id, 120);
        $lock->get();
        $this->post(route('admin.gmail.replay', 'locked'))->assertRedirect();
        $this->assertSame('skipped', $receipt->fresh()->status);
        $lock->release();
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('secret-queue-response'));
        $this->post(route('admin.gmail.replay', 'abc'))->assertSessionHas('warning');
        $this->assertStringNotContainsString('secret-queue-response', session('warning'));
        $this->assertDatabaseHas('gmail_import_receipts', ['gmail_message_id' => 'abc', 'status' => 'pending']);
    }

    public function test_drafts_are_bound_to_supplier_user_and_mailbox(): void
    {
        Storage::fake('local');
        $connection = $this->mailbox();
        $supplier = Organization::factory()->wholesaler()->create();
        $token = $this->stage($supplier);
        $otherSupplier = Organization::factory()->wholesaler()->create();
        $this->get(route('admin.supplier-import-profiles.edit', ['supplier' => $otherSupplier, 'gmail_draft' => $token]))->assertNotFound();
        $other = User::factory()->admin()->create();
        $this->actingAs($other)->get(route('admin.supplier-import-profiles.edit', ['supplier' => $supplier, 'gmail_draft' => $token]))->assertForbidden();
        $this->actingAs($connection->user);
        $connection->update(['email' => 'changed@example.com']);
        config(['gmail-imports.mailbox' => 'changed@example.com']);
        $this->get(route('admin.supplier-import-profiles.edit', ['supplier' => $supplier, 'gmail_draft' => $token]))->assertNotFound();
    }

    public function test_actions_require_admin_mode_and_current_mode_version(): void
    {
        config(['account-modes.enabled' => true]);
        $connection = $this->mailbox();
        $this->post(route('admin.gmail.replay', 'abc'))->assertStatus(409);
        $version = session('account_mode.version');
        Queue::fake();
        $this->post(route('admin.gmail.replay', 'abc'), ['_mode_version' => $version])->assertRedirect();
        $this->patch(route('mode.update'), ['mode' => 'supplier', '_mode_version' => $version])->assertRedirect();
        $this->getJson(route('admin.gmail.import-status', 'abc'))->assertForbidden();
        Queue::assertPushed(ProcessGmailImportReceipt::class, 1);
    }
}
