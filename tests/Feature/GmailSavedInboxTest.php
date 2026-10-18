<?php

namespace Tests\Feature;

use App\Jobs\ProcessGmailImportReceipt;
use App\Jobs\SyncGmailMessages;
use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use App\Models\GmailMessage;
use App\Services\GmailApiException;
use App\Services\GmailClientFactory;
use App\Services\GmailImportDiscovery;
use App\Services\GmailImportIntake;
use App\Services\GmailMessageSync;
use App\Services\GmailService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GmailSavedInboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test', 'services.google.client_secret' => 'test', 'services.google.redirect_uri' => 'http://localhost/callback', 'auth.admin_two_factor_enabled' => false, 'session.block_store' => 'array', 'gmail-imports.enabled' => false]);
    }

    private function google(array $responses, ?array &$requests): void
    {
        $requests = [];
        $stack = HandlerStack::create(new MockHandler(array_map(fn ($data) => $data instanceof Response ? $data : new Response(200, ['Content-Type' => 'application/json'], json_encode($data)), $responses)));
        $stack->push(Middleware::history($requests));
        $this->app->instance(GmailClientFactory::class, new GmailClientFactory(new Client(['handler' => $stack, 'http_errors' => false])));
    }

    private function message(string $id): array
    {
        return ['id' => $id, 'internalDate' => '1720000000000', 'labelIds' => ['INBOX', 'UNREAD'], 'payload' => ['mimeType' => 'multipart/mixed', 'headers' => [['name' => 'Subject', 'value' => '<script>subject</script>']], 'parts' => [
            ['mimeType' => 'text/html', 'body' => ['data' => rtrim(strtr(base64_encode('<p>Saved body</p><script>bad()</script><img src="https://evil.test">'), '+/', '-_'), '=')]],
            ['partId' => '1', 'filename' => '<script>prices.csv</script>', 'body' => ['attachmentId' => 'file', 'size' => 12]],
        ]]];
    }

    private function saved(GmailConnection $connection, array $attributes = []): GmailMessage
    {
        return GmailMessage::factory()->create(array_merge(['user_id' => $connection->user_id, 'mailbox_email' => strtolower($connection->email)], $attributes));
    }

    public function test_backfill_resumes_and_catches_arrivals_since_baseline_without_downloading_files(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([
            ['historyId' => '100'], ['messages' => [['id' => 'first']], 'nextPageToken' => 'second-page'], $this->message('first'),
            ['messages' => [['id' => 'first'], ['id' => 'second']]], $this->message('second'),
            ['history' => [['messagesAdded' => [['message' => ['id' => 'arrival']], ['message' => ['id' => 'first']]]]], 'historyId' => '110'], $this->message('arrival'),
        ], $requests);
        $sync = app(GmailMessageSync::class);

        $this->assertTrue($sync->sync($connection->id));
        $this->assertSame('second-page', $connection->fresh()->scan_page);
        $this->assertNull($connection->fresh()->last_synced_at);
        $this->assertTrue($sync->sync($connection->id));
        $this->assertSame('100', $connection->fresh()->history_cursor);
        $this->assertFalse($sync->sync($connection->id));

        $this->assertDatabaseCount('gmail_messages', 3);
        $this->assertDatabaseCount('gmail_import_receipts', 0);
        $this->assertSame('110', $connection->fresh()->history_cursor);
        $this->assertNotNull($connection->fresh()->last_synced_at);
        $this->assertSame('<p>Saved body</p>', GmailMessage::first()->html_body);
        $this->assertCount(7, $requests);
        foreach ($requests as $request) {
            $this->assertStringNotContainsString('/attachments/', $request['request']->getUri()->getPath());
        }
        parse_str($requests[1]['request']->getUri()->getQuery(), $query);
        $this->assertSame('has:attachment', $query['q']);
        $this->assertSame('50', $query['maxResults']);
        parse_str($requests[5]['request']->getUri()->getQuery(), $query);
        $this->assertSame('100', $query['startHistoryId']);
    }

    public function test_incremental_sync_deduplicates_events_and_retains_saved_mail_after_removal(): void
    {
        $connection = GmailConnection::factory()->create(['scan_completed_at' => now(), 'history_cursor' => '100']);
        $saved = $this->saved($connection, ['gmail_message_id' => 'old']);
        $outside = $this->message('outside');
        $outside['labelIds'] = [];
        $this->google([
            ['historyId' => '120', 'nextPageToken' => 'next', 'history' => [['messagesAdded' => [['message' => ['id' => 'old']], ['message' => ['id' => 'new']], ['message' => ['id' => 'outside']]], 'labelsAdded' => [['message' => ['id' => 'new'], 'labelIds' => ['INBOX']]]]]], $this->message('new'), $outside,
            ['historyId' => '125', 'history' => [['messagesDeleted' => [['message' => ['id' => 'old']]]]]],
        ], $requests);

        $this->assertTrue(app(GmailMessageSync::class)->sync($connection->id));
        $this->assertSame('100', $connection->fresh()->history_cursor);
        $this->assertSame('next', $connection->fresh()->history_page);
        $this->assertFalse(app(GmailMessageSync::class)->sync($connection->id));

        $this->assertModelExists($saved);
        $this->assertDatabaseCount('gmail_messages', 2);
        $this->assertSame('125', $connection->fresh()->history_cursor);
        $this->assertCount(4, $requests);
    }

    public function test_failed_page_retries_skip_persisted_ids_without_advancing_checkpoint(): void
    {
        $connection = GmailConnection::factory()->create(['scan_baseline' => '100']);
        $failure = new Response(503, ['Retry-After' => '120'], '{"error":{"code":503}}');
        $this->google([['messages' => [['id' => 'first'], ['id' => 'second']]], $this->message('first'), $failure, ['messages' => [['id' => 'first'], ['id' => 'second']]], $this->message('second')], $requests);
        try {
            app(GmailMessageSync::class)->sync($connection->id);
            $this->fail('The failed batch must be retried.');
        } catch (GmailApiException $exception) {
            $this->assertSame(120, $exception->retryAfter);
        }
        $this->assertNull($connection->fresh()->scan_completed_at);
        $this->assertDatabaseCount('gmail_messages', 1);

        app(GmailMessageSync::class)->sync($connection->id);

        $this->assertDatabaseCount('gmail_messages', 2);
        $this->assertCount(5, $requests);
    }

    public function test_expired_history_recovers_with_attachment_scan_and_skips_saved_ids(): void
    {
        $connection = GmailConnection::factory()->create(['scan_completed_at' => now(), 'history_cursor' => 'expired']);
        $this->saved($connection, ['gmail_message_id' => 'old']);
        $this->google([new Response(404, [], '{"error":{"code":404}}'), ['historyId' => '200'], ['messages' => [['id' => 'old'], ['id' => 'new']]], $this->message('new'), ['historyId' => '201']], $requests);

        $sync = app(GmailMessageSync::class);
        $this->assertTrue($sync->sync($connection->id));
        $this->assertTrue($sync->sync($connection->id));
        $this->assertFalse($sync->sync($connection->id));

        $this->assertSame('201', $connection->fresh()->history_cursor);
        $this->assertDatabaseCount('gmail_messages', 2);
        $this->assertCount(5, $requests);
    }

    public function test_saved_inbox_filter_pagination_and_details_never_call_gmail(): void
    {
        $connection = GmailConnection::factory()->create(['last_synced_at' => now(), 'scan_completed_at' => now()]);
        $this->saved($connection, ['gmail_message_id' => 'first']);
        $this->saved($connection, ['gmail_message_id' => 'second']);
        $this->saved($connection, ['gmail_message_id' => 'third', 'subject' => '<script>subject</script>', 'html_body' => '<p>Saved</p>', 'attachment_manifest' => [['part_id' => '1', 'name' => '<script>file.csv</script>', 'size' => 12]]]);
        $this->google([], $requests);
        $this->actingAs($connection->user);
        $first = app(GmailService::class)->inbox($connection->user, 1);
        $second = app(GmailService::class)->inbox($connection->user, 1, $first['next']);
        $third = app(GmailService::class)->inbox($connection->user, 1, $second['next']);

        $this->assertSame(['third', 'second', 'first'], [$first['messages'][0]['id'], $second['messages'][0]['id'], $third['messages'][0]['id']]);
        $this->assertNull($third['next']);
        foreach ([[], ['import_status' => 'unchecked'], ['import_status' => 'failed']] as $query) {
            $this->get(route('admin.gmail.index', $query))->assertOk();
        }
        $this->get(route('admin.gmail.show', 'third'))->assertSee('sandbox=""', false)->assertSee('<script>file.csv</script>')->assertDontSee('<script>subject</script>', false);
        $this->assertCount(0, $requests);
    }

    public function test_filter_searches_entire_mailbox_and_preserves_all_attachments(): void
    {
        $connection = GmailConnection::factory()->create();
        $match = $this->saved($connection, ['gmail_message_id' => 'match', 'attachment_manifest' => [['part_id' => '1', 'name' => 'one.csv', 'size' => 12], ['part_id' => '2', 'name' => 'two.csv', 'size' => 12]]]);
        GmailMessage::factory()->count(105)->create(['user_id' => $connection->user_id, 'mailbox_email' => strtolower($connection->email)]);
        GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => $match->gmail_message_id, 'status' => 'failed']);
        $this->google([], $requests);

        $inbox = app(GmailService::class)->inbox($connection->user, 1, null, 'failed');

        $this->assertCount(1, $inbox['messages']);
        $this->assertSame('match', $inbox['messages'][0]['id']);
        $this->assertCount(2, $inbox['messages'][0]['attachments']);
        $this->assertNull($inbox['next']);
        $this->assertCount(0, $requests);
    }

    public function test_saved_data_and_cursors_are_isolated_by_owner_mailbox_filter_and_size(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->saved($connection, ['gmail_message_id' => 'one']);
        $this->saved($connection, ['gmail_message_id' => 'two']);
        $cursor = app(GmailService::class)->inbox($connection->user, 1)['next'];
        $this->actingAs($connection->user)->get(route('admin.gmail.index', ['page' => $cursor, 'size' => 2]))->assertSee('Страница устарела.');
        $this->get(route('admin.gmail.index', ['page' => $cursor, 'size' => 1, 'import_status' => 'failed']))->assertSee('Страница устарела.');
        $connection->update(['email' => 'other@example.com']);
        $this->get(route('admin.gmail.index', ['page' => $cursor, 'size' => 1]))->assertSee('Страница устарела.');
        $this->get(route('admin.gmail.show', 'one'))->assertSessionHas('warning');
        $connection->update(['email' => strtoupper($connection->getOriginal('email'))]);
        $other = GmailConnection::factory()->create();
        $this->actingAs($other->user)->get(route('admin.gmail.show', 'one'))->assertSessionHas('warning');
    }

    public function test_scheduler_syncs_all_authorized_mailboxes_with_import_disabled_and_refresh_queues_only(): void
    {
        Queue::fake([SyncGmailMessages::class]);
        $connection = GmailConnection::factory()->create();
        $other = GmailConnection::factory()->create();
        GmailConnection::factory()->create(['status' => 'reconnect_required']);
        $this->google([], $requests);

        $this->artisan('gmail-imports:sync')->assertSuccessful();
        Queue::assertPushed(SyncGmailMessages::class, 2);
        $this->actingAs($connection->user)->post(route('admin.gmail.refresh'))->assertRedirectToRoute('admin.gmail.index');
        Queue::assertPushed(SyncGmailMessages::class, fn ($job): bool => $job->connectionId === $other->id);
        $this->assertCount(0, $requests);
    }

    public function test_enabling_import_discovers_saved_messages_without_gmail_and_preserves_receipts(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->saved($connection, ['gmail_message_id' => 'new']);
        $this->saved($connection, ['gmail_message_id' => 'done']);
        $receipt = GmailImportReceipt::factory()->create(['mailbox_email' => $connection->email, 'gmail_message_id' => 'done', 'status' => 'completed']);
        config(['gmail-imports.enabled' => true, 'gmail-imports.connection_id' => $connection->id, 'gmail-imports.mailbox' => $connection->email]);
        Queue::fake([ProcessGmailImportReceipt::class]);
        $this->google([], $requests);

        app(GmailImportDiscovery::class)->sync();
        app(GmailImportDiscovery::class)->sync();

        $this->assertSame('completed', $receipt->fresh()->status);
        $this->assertDatabaseCount('gmail_import_receipts', 2);
        Queue::assertPushed(ProcessGmailImportReceipt::class, 1);
        $this->assertCount(0, $requests);
    }

    public function test_refresh_deduplicates_queued_sync_requests(): void
    {
        config(['queue.default' => 'database']);
        $connection = GmailConnection::factory()->create();
        $this->google([], $requests);
        $this->actingAs($connection->user);

        $this->post(route('admin.gmail.refresh'))->assertRedirectToRoute('admin.gmail.index');
        $this->post(route('admin.gmail.refresh'))->assertRedirectToRoute('admin.gmail.index');

        $this->assertDatabaseCount('jobs', 1);
        $this->assertCount(0, $requests);
    }

    public function test_reconnect_failure_keeps_saved_content_available_and_same_mailbox_reuses_it(): void
    {
        $connection = GmailConnection::factory()->create(['status' => 'reconnect_required', 'last_sync_error' => 'sync_failed']);
        $saved = $this->saved($connection, ['gmail_message_id' => 'saved', 'subject' => 'Retained mail']);
        $this->google([], $requests);
        $this->actingAs($connection->user);

        $this->get(route('admin.gmail.index'))->assertSee('Retained mail')->assertSee('Не удалось синхронизировать почту.');
        $this->get(route('admin.gmail.show', 'saved'))->assertSee('Retained mail');
        $connection->update(['status' => 'connected', 'email' => strtoupper($connection->email)]);
        $this->get(route('admin.gmail.show', 'saved'))->assertSee('Retained mail');

        $this->assertModelExists($saved);
        $this->assertDatabaseCount('gmail_messages', 1);
        $this->assertCount(0, $requests);
    }

    public function test_deleted_attachment_leaves_saved_email_and_reports_unavailability(): void
    {
        $connection = GmailConnection::factory()->create();
        $saved = $this->saved($connection, ['gmail_message_id' => 'deleted']);
        config(['gmail-imports.enabled' => true, 'gmail-imports.connection_id' => $connection->id, 'gmail-imports.mailbox' => $connection->email]);
        $receipt = GmailImportReceipt::factory()->create(['mailbox_email' => strtolower($connection->email), 'gmail_message_id' => 'deleted']);
        $this->google([new Response(404, [], '{"error":{"code":404}}')], $requests);

        (new ProcessGmailImportReceipt($receipt->id))->handle(app(GmailImportIntake::class));

        $this->assertSame('message_unavailable', $receipt->fresh()->reason);
        $this->assertModelExists($saved);
        $this->actingAs($connection->user)->get(route('admin.gmail.show', 'deleted'))->assertSee('Письмо или вложение больше недоступно в Gmail.');
        $this->assertCount(1, $requests);
    }

    public function test_new_arrival_between_pages_does_not_repeat_or_lose_existing_results(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->saved($connection, ['gmail_message_id' => 'old']);
        $this->saved($connection, ['gmail_message_id' => 'recent']);
        $first = app(GmailService::class)->inbox($connection->user, 1);
        $this->saved($connection, ['gmail_message_id' => 'arrival']);

        $second = app(GmailService::class)->inbox($connection->user, 1, $first['next']);

        $this->assertSame('recent', $first['messages'][0]['id']);
        $this->assertSame('old', $second['messages'][0]['id']);
        $this->assertNull($second['next']);
    }

    public function test_message_newly_labeled_inbox_is_saved_without_a_message_added_event(): void
    {
        $connection = GmailConnection::factory()->create(['scan_completed_at' => now(), 'history_cursor' => '100']);
        $this->google([['historyId' => '101', 'history' => [['labelsAdded' => [['message' => ['id' => 'returned'], 'labelIds' => ['INBOX']], ['message' => ['id' => 'unrelated'], 'labelIds' => ['STARRED']]]]]], $this->message('returned')], $requests);

        app(GmailMessageSync::class)->sync($connection->id);

        $this->assertDatabaseHas('gmail_messages', ['gmail_message_id' => 'returned', 'user_id' => $connection->user_id]);
        $this->assertDatabaseCount('gmail_messages', 1);
        $this->assertCount(2, $requests);
    }
}
