<?php

namespace Tests\Feature;

use App\Models\GmailConnection;
use App\Models\User;
use App\Services\GmailClientFactory;
use App\Services\GmailException;
use App\Services\GmailMessageReader;
use App\Services\GmailService;
use Google\Service\Gmail\Message;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class GmailIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect_uri' => 'http://localhost/admin/gmail/callback', 'session.block' => true, 'session.block_store' => 'array', 'auth.admin_two_factor_enabled' => false]);
    }

    private function google(array $responses, ?array &$history): void
    {
        $history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create(function (RequestInterface $request, array $options) use ($mock, &$history): PromiseInterface {
            $history[] = ['request' => $request];

            return $mock($request, $options);
        });
        $this->app->instance(GmailClientFactory::class, new GmailClientFactory(new Client(['handler' => $stack, 'http_errors' => false])));
    }

    private function googleResponse(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    private function state(User $user): string
    {
        $response = $this->actingAs($user)->post(route('admin.gmail.connect'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(GmailClientFactory::SCOPE, $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);

        $this->withCookie(config('session.cookie'), session()->getId());

        return $query['state'];
    }

    public function test_connect_saves_encrypted_private_credentials_and_consumes_state(): void
    {
        $user = User::factory()->admin()->create();
        $this->google([$this->googleResponse(['access_token' => 'secret-access', 'refresh_token' => 'secret-refresh', 'expires_in' => 3600, 'scope' => GmailClientFactory::SCOPE]), $this->googleResponse(['emailAddress' => 'admin@gmail.com'])], $history);
        $state = $this->state($user);

        $this->get(route('admin.gmail.callback', ['state' => $state, 'code' => 'secret-code']))->assertRedirectToRoute('admin.gmail.index')->assertSessionHas('success')->assertSessionMissing('gmail_oauth');

        $connection = $user->gmailConnection()->sole();
        $this->assertSame('secret-access', $connection->access_token);
        $this->assertSame('admin@gmail.com', $connection->email);
        $this->assertNotSame('secret-access', DB::table('gmail_connections')->value('access_token'));
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        $this->get(route('admin.gmail.callback', ['state' => $state, 'code' => 'replay']))->assertSessionHas('warning');
        $this->assertCount(2, $history);
    }

    #[TestWith(['mismatch'])]
    #[TestWith(['expired'])]
    #[TestWith(['user'])]
    #[TestWith(['session'])]
    #[TestWith(['denial'])]
    #[TestWith(['missing-code'])]
    public function test_invalid_callbacks_do_not_exchange_codes(string $failure): void
    {
        $user = User::factory()->admin()->create();
        $this->google([], $history);
        $state = $this->state($user);
        $query = ['state' => $state, 'code' => 'code'];
        if ($failure === 'mismatch') {
            $query['state'] = 'wrong';
        } elseif ($failure === 'expired') {
            $this->travel(11)->minutes();
        } elseif ($failure === 'user') {
            $this->actingAs(User::factory()->admin()->create());
        } elseif ($failure === 'session') {
            $this->app['session']->put('gmail_oauth.session', 'different-session');
            $this->app['session']->save();
        } elseif ($failure === 'denial') {
            $query['error'] = 'access_denied';
        } else {
            unset($query['code']);
        }

        $this->get(route('admin.gmail.callback', $query))->assertRedirectToRoute('admin.gmail.index')->assertSessionHas('warning')->assertSessionMissing('gmail_oauth');

        $this->assertDatabaseCount('gmail_connections', 0);
        $this->assertCount(0, $history);
    }

    public function test_new_flow_invalidates_previous_state_and_unauthenticated_callback_never_sets_intended_url(): void
    {
        $user = User::factory()->admin()->create();
        $this->google([], $history);
        $old = $this->state($user);
        $this->state($user);
        $this->get(route('admin.gmail.callback', ['state' => $old, 'code' => 'code']))->assertSessionHas('warning');
        auth()->logout();

        $this->get(route('admin.gmail.callback', ['code' => 'sensitive']))->assertRedirectToRoute('login')->assertSessionMissing('url.intended')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_non_admins_blocked_admins_and_unconfirmed_admins_cannot_access_gmail(): void
    {
        $this->actingAs(User::factory()->pharmacy()->create())->get(route('admin.gmail.index'))->assertForbidden();
        $this->actingAs(User::factory()->wholesaler()->create())->post(route('admin.gmail.connect'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(['is_blocked' => true]))->get(route('admin.gmail.index'))->assertRedirectToRoute('login');
        config(['auth.admin_two_factor_enabled' => true]);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.gmail.index'))->assertRedirectToRoute('two-factor.enroll');
    }

    public function test_gate_bypass_does_not_allow_another_admin_to_view_connection(): void
    {
        $connection = GmailConnection::factory()->create();
        $other = User::factory()->admin()->create();
        $this->assertFalse(Gate::forUser($other)->allows('view', $connection));
        $this->assertTrue(Gate::forUser($connection->user)->allows('view', $connection));
        $this->google([], $history);
        $this->actingAs($other)->get(route('admin.gmail.index'))->assertSee('Gmail не подключён.')->assertDontSee($connection->email);
        $this->actingAs($other)->get(route('admin.gmail.show', 'abc'))->assertRedirectToRoute('admin.gmail.index');
        $this->assertCount(0, $history);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_reconnection_preserves_refresh_token_only_for_same_account(bool $different): void
    {
        $connection = GmailConnection::factory()->create(['email' => 'old@gmail.com']);
        $this->google([$this->googleResponse(['access_token' => 'new-access', 'expires_in' => 3600, 'scope' => GmailClientFactory::SCOPE]), $this->googleResponse(['emailAddress' => $different ? 'new@gmail.com' : 'old@gmail.com'])], $history);
        $state = $this->state($connection->user);

        $response = $this->get(route('admin.gmail.callback', ['state' => $state, 'code' => 'code']));

        $response->assertSessionHas($different ? 'warning' : 'success');
        $this->assertSame('old@gmail.com', $connection->fresh()->email);
        $this->assertSame('test-refresh', $connection->fresh()->refresh_token);
        $this->assertSame($different ? 'test-access' : 'new-access', $connection->fresh()->access_token);
    }

    public function test_failed_exchange_preserves_existing_connection_without_exposing_google_error(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['error' => 'invalid_grant', 'error_description' => 'secret-google-detail'], 400)], $history);
        $state = $this->state($connection->user);

        $this->get(route('admin.gmail.callback', ['state' => $state, 'code' => 'secret-code']))->assertSessionHas('warning');

        $this->assertSame('test-access', $connection->fresh()->access_token);
        $this->assertStringNotContainsString('secret-google-detail', session('warning'));
    }

    private function batch(array $entries): Response
    {
        $body = '';
        foreach ($entries as $id => $entry) {
            $body .= "--batch\r\nContent-Type: application/http\r\nContent-ID: response-{$id}\r\n\r\nHTTP/1.1 ".($entry['status'] ?? 200)." OK\r\nContent-Type: application/json\r\n\r\n".json_encode($entry['data'])."\r\n";
        }

        return new Response(200, ['Content-Type' => 'multipart/mixed; boundary=batch'], $body."--batch--\r\n");
    }

    public function test_inbox_uses_page_token_inbox_filter_and_only_batches_returned_ids(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([
            $this->googleResponse(['messages' => [['id' => 'abc'], ['id' => 'gone']], 'nextPageToken' => 'opaque-next']),
            $this->batch([
                'abc' => ['data' => [
                    'id' => 'abc', 'labelIds' => ['INBOX', 'UNREAD'], 'snippet' => '<script>snippet</script>',
                    'payload' => ['headers' => [
                        ['name' => 'Subject', 'value' => '<script>subject</script>'],
                        ['name' => 'From', 'value' => '<b>sender</b>'],
                    ]],
                ]],
                'gone' => ['status' => 404, 'data' => ['error' => ['code' => 404, 'message' => 'gone']]],
            ]),
        ], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.index', ['page' => 'opaque-page', 'size' => 10]))->assertSee('Непрочитано')->assertSee('&lt;script&gt;subject&lt;/script&gt;', false)->assertDontSee('<script>subject</script>', false)->assertSee('opaque-next')->assertHeader('Referrer-Policy', 'no-referrer');

        parse_str($history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame('INBOX', $query['labelIds']);
        $this->assertSame('opaque-page', $query['pageToken']);
        $this->assertSame('10', $query['maxResults']);
        $this->assertCount(2, $history);
        $this->assertStringContainsString('/messages/abc', (string) $history[1]['request']->getBody());
        $this->assertStringContainsString('format=full', (string) $history[1]['request']->getBody());
    }

    public function test_expiring_access_refreshes_and_preserves_refresh_token(): void
    {
        $connection = GmailConnection::factory()->create(['expires_at' => now()->subMinute()]);
        $this->google([$this->googleResponse(['access_token' => 'fresh-access', 'expires_in' => 3600]), $this->googleResponse([])], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.index'))->assertSee('Входящих писем нет');

        $this->assertSame('fresh-access', $connection->fresh()->access_token);
        $this->assertSame('test-refresh', $connection->fresh()->refresh_token);
        $this->assertSame('Bearer fresh-access', $history[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_invalid_grant_marks_reconnect_required_without_leaking_response(): void
    {
        $connection = GmailConnection::factory()->create(['expires_at' => now()->subMinute()]);
        $this->google([$this->googleResponse(['error' => 'invalid_grant', 'error_description' => 'secret'], 400)], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.index'))->assertSee('Подключите аккаунт заново.')->assertDontSee('secret');

        $this->assertSame('reconnect_required', $connection->fresh()->status);
        $this->assertNull($connection->fresh()->refresh_token);
    }

    public function test_read_retries_are_bounded_and_failed_batch_entries_alone_are_retried(): void
    {
        Sleep::fake();
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['messages' => [['id' => 'ok'], ['id' => 'retry']]]), $this->batch(['ok' => ['data' => ['id' => 'ok']], 'retry' => ['status' => 503, 'data' => ['error' => ['code' => 503, 'message' => 'unavailable']]]]), $this->batch(['retry' => ['data' => ['id' => 'retry']]])], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.index'))->assertSee('(Без темы)');

        $this->assertCount(3, $history);
        $this->assertStringNotContainsString('/messages/ok', (string) $history[2]['request']->getBody());
        $this->assertStringContainsString('/messages/retry', (string) $history[2]['request']->getBody());
    }

    public function test_server_failure_stops_after_three_reads(): void
    {
        Sleep::fake();
        $connection = GmailConnection::factory()->create();
        $this->google(array_fill(0, 3, $this->googleResponse(['error' => ['code' => 503, 'message' => 'secret']], 503)), $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.index'))->assertSee('Gmail временно недоступен.')->assertDontSee('secret');

        $this->assertCount(3, $history);
        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_disconnect_removes_credentials_even_if_revocation_fails(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['error' => 'unavailable'], 503)], $history);

        $this->actingAs($connection->user)->withSession(['gmail_oauth' => ['hash' => 'pending']])->delete(route('admin.gmail.destroy'))->assertRedirectToRoute('admin.gmail.index')->assertSessionHas('warning')->assertSessionMissing('gmail_oauth');

        $this->assertModelMissing($connection);
        $this->assertCount(1, $history);
    }

    #[TestWith([0])]
    #[TestWith([51])]
    public function test_page_size_is_validated(int $size): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.gmail.index', ['size' => $size]))->assertSessionHasErrors('size');
    }

    public function test_refresh_returns_to_first_page_and_missing_configuration_has_safe_feedback(): void
    {
        config(['services.google.client_id' => null]);
        $user = User::factory()->admin()->create();
        $this->actingAs($user)->post(route('admin.gmail.refresh'))->assertRedirectToRoute('admin.gmail.index');
        $this->get(route('admin.gmail.index'))->assertSee('Gmail не настроен.');
        $this->post(route('admin.gmail.connect'))->assertSessionHas('warning')->assertSessionMissing('gmail_oauth');
    }

    public function test_nested_alternatives_attachment_bodies_encodings_and_sanitization(): void
    {
        $encode = fn (string $text): string => rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
        $message = new Message(['id' => 'abc', 'payload' => ['mimeType' => 'multipart/mixed', 'headers' => [['name' => 'Subject', 'value' => '=?UTF-8?B?0J/RgNC40LLQtdGC?=']], 'parts' => [
            ['mimeType' => 'multipart/alternative', 'parts' => [
                ['mimeType' => 'text/plain', 'body' => ['data' => $encode('old alternative')]],
                ['mimeType' => 'text/plain', 'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset=ISO-8859-1']], 'body' => ['data' => $encode("caf\xe9 <script>plain</script>")]],
                ['mimeType' => 'text/html', 'body' => ['attachmentId' => 'body-html']],
            ]],
            ['mimeType' => 'text/plain', 'filename' => '<script>file.txt</script>', 'body' => ['attachmentId' => 'named-file', 'size' => 123]],
        ]]]);
        $fetched = [];

        $result = app(GmailMessageReader::class)->read($message, function (string $id) use (&$fetched, $encode): string {
            $fetched[] = $id;

            return $encode('<p onclick="bad()">Safe <strong>bold</strong></p><script>bad()</script><img src="https://evil.test"><a href="https://evil.test">link</a><form>form</form><svg onload="bad()"></svg>');
        });

        $this->assertSame('Привет', $result['subject']);
        $this->assertSame('café <script>plain</script>', $result['plain']);
        $this->assertSame(['body-html'], $fetched);
        $this->assertSame([['part_id' => '', 'name' => '<script>file.txt</script>', 'size' => 123]], $result['attachments']);
        $this->assertStringContainsString('<strong>bold</strong>', $result['html']);
        foreach (['script', 'onclick', '<img', 'href=', '<form', '<svg', 'https://evil.test'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $result['html']);
        }
        $this->assertStringContainsString("default-src 'none'", $result['document']);
    }

    #[TestWith(['%%%'])]
    #[TestWith(['a'])]
    #[TestWith(['Zh'])]
    #[TestWith(['Zg='])]
    public function test_malformed_base64_has_safe_empty_body(string $data): void
    {
        $result = app(GmailMessageReader::class)->read(new Message(['payload' => ['mimeType' => 'text/plain', 'body' => ['data' => $data]]]), fn (string $id): string => '');

        $this->assertSame('', $result['plain']);
    }

    public function test_full_message_view_is_sandboxed_and_escapes_plain_text_and_headers(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['id' => 'abc', 'payload' => ['mimeType' => 'multipart/alternative', 'headers' => [['name' => 'Subject', 'value' => '<script>title</script>']], 'parts' => [['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode('<script>plain</script>'), '+/', '-_'), '=')]], ['mimeType' => 'text/html', 'body' => ['data' => rtrim(strtr(base64_encode('<p>Safe</p><script>bad()</script>'), '+/', '-_'), '=')]]]]])], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.show', 'abc'))->assertSee('sandbox=""', false)->assertSee('referrerpolicy="no-referrer"', false)->assertSee('&lt;script&gt;plain&lt;/script&gt;', false)->assertDontSee('<script>title</script>', false)->assertDontSee('bad()');

        $this->assertStringContainsString('format=full', $history[0]['request']->getUri()->getQuery());
        $this->assertSame('GET', $history[0]['request']->getMethod());
    }

    public function test_callback_consumes_state_durably_while_session_and_owner_locks_are_held(): void
    {
        $user = User::factory()->admin()->create();
        $this->google([function () use ($user): Response {
            $sessionId = session()->getId();
            $this->assertFalse(Cache::store('array')->lock('session:'.$sessionId, 300)->get());
            $this->assertFalse(Cache::store('array')->lock('gmail-owner:'.$user->id, 300)->get());
            $this->assertStringNotContainsString('gmail_oauth', session()->getHandler()->read($sessionId));

            return $this->googleResponse(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'scope' => GmailClientFactory::SCOPE]);
        }, $this->googleResponse(['emailAddress' => 'admin@gmail.com'])], $history);
        $state = $this->state($user);

        $this->get(route('admin.gmail.callback', ['state' => $state, 'code' => 'code']))->assertSessionHas('success');

        $this->assertDatabaseCount('gmail_connections', 1);
        $this->assertTrue(Cache::store('array')->lock('gmail-owner:'.$user->id, 300)->get());
    }

    public function test_credentials_are_reread_after_a_different_request_refreshes_or_disconnects(): void
    {
        $connection = GmailConnection::factory()->create(['expires_at' => now()->subMinute()]);
        $user = $connection->user;
        $user->setRelation('gmailConnection', $connection);
        $connection->fresh()->update(['access_token' => 'other-request-token', 'expires_at' => now()->addHour()]);
        $this->google([$this->googleResponse([])], $history);

        $this->assertSame([], app(GmailService::class)->inbox($user)['messages']);
        $this->assertCount(1, $history);
        $this->assertSame('Bearer other-request-token', $history[0]['request']->getHeaderLine('Authorization'));
        $connection->fresh()->delete();
        try {
            app(GmailService::class)->inbox($user);
            $this->fail('A removed connection must not be restored.');
        } catch (GmailException $exception) {
            $this->assertSame('Подключите Gmail заново.', $exception->getMessage());
        }
        $this->assertDatabaseCount('gmail_connections', 0);
        $this->assertCount(1, $history);
    }

    public function test_different_account_replaces_credentials_only_with_new_refresh_token(): void
    {
        $connection = GmailConnection::factory()->create(['email' => 'old@gmail.com']);
        $this->google([$this->googleResponse(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => GmailClientFactory::SCOPE]), $this->googleResponse(['emailAddress' => 'new@gmail.com'])], $history);
        $state = $this->state($connection->user);

        $this->get(route('admin.gmail.callback', ['state' => $state, 'code' => 'code']))->assertSessionHas('success');

        $this->assertSame('new@gmail.com', $connection->fresh()->email);
        $this->assertSame('new-refresh', $connection->fresh()->refresh_token);
        $this->assertDatabaseCount('gmail_connections', 1);
    }

    public function test_missing_granted_scope_preserves_existing_connection(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => 'email'])], $history);
        $state = $this->state($connection->user);

        $this->get(route('admin.gmail.callback', ['state' => $state, 'code' => 'code']))->assertSessionHas('warning', 'Разрешение на чтение Gmail не предоставлено.');

        $this->assertSame('test-access', $connection->fresh()->access_token);
        $this->assertCount(1, $history);
    }

    public function test_persistent_unauthorized_access_requires_reconnect_after_one_refresh(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['error' => ['code' => 401, 'message' => 'secret']], 401), $this->googleResponse(['access_token' => 'fresh', 'expires_in' => 3600]), $this->googleResponse(['error' => ['code' => 401]], 401)], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.index'))->assertSee('Требуется повторное подключение');

        $this->assertSame('reconnect_required', $connection->fresh()->status);
        $this->assertCount(3, $history);
    }

    public function test_expired_page_token_and_missing_message_have_clean_feedback(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['error' => ['code' => 400, 'message' => 'secret-page']], 400), $this->googleResponse(['error' => ['code' => 404, 'message' => 'gone']], 404)], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.index', ['page' => 'expired']))->assertSee('Страница устарела.')->assertDontSee('secret-page');
        $this->get(route('admin.gmail.show', 'gone'))->assertSessionHas('warning', 'Письмо больше недоступно.');

        $this->assertCount(2, $history);
    }

    public function test_attachment_backed_body_is_read_but_named_file_is_not_downloaded(): void
    {
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['id' => 'abc', 'payload' => ['mimeType' => 'multipart/mixed', 'parts' => [['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'text-body']], ['mimeType' => 'application/pdf', 'filename' => 'invoice.pdf', 'body' => ['attachmentId' => 'file', 'size' => 99]]]]]), $this->googleResponse(['data' => 'SGVsbG8'])], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.show', 'abc'))->assertSee('Hello')->assertSee('invoice.pdf')->assertSee('99 байт');

        $this->assertCount(2, $history);
        $this->assertStringContainsString('/attachments/text-body', $history[1]['request']->getUri()->getPath());
    }

    public function test_account_mode_forms_require_current_version_and_supplier_mode_cannot_access_gmail(): void
    {
        config(['account-modes.enabled' => true]);
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $this->actingAs($user)->get(route('admin.gmail.index'))->assertSee('name="_mode_version"', false);
        $this->post(route('admin.gmail.refresh'))->assertStatus(409)->assertHeader('Referrer-Policy', 'no-referrer');
        $version = session('account_mode.version');
        $this->post(route('admin.gmail.refresh'), ['_mode_version' => $version])->assertRedirectToRoute('admin.gmail.index');
        $this->patch(route('mode.update'), ['mode' => 'supplier', '_mode_version' => $version])->assertRedirect();

        $this->get(route('admin.gmail.index'))->assertForbidden();
        $this->get(route('admin.gmail.callback', ['code' => 'code']))->assertForbidden();
    }

    public function test_quota_failure_retries_but_permission_denial_does_not(): void
    {
        Sleep::fake();
        $connection = GmailConnection::factory()->create();
        $this->google([$this->googleResponse(['error' => ['code' => 403, 'errors' => [['reason' => 'userRateLimitExceeded']]]], 403), $this->googleResponse([]), $this->googleResponse(['error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]]], 403)], $history);

        $this->actingAs($connection->user)->get(route('admin.gmail.index'))->assertSee('Входящих писем нет');
        $this->get(route('admin.gmail.index'))->assertSee('Gmail временно недоступен.');

        $this->assertCount(3, $history);
    }

    public function test_disconnect_removes_unreadable_credentials_without_network_fallback(): void
    {
        $connection = GmailConnection::factory()->create();
        DB::table('gmail_connections')->where('id', $connection->id)->update(['refresh_token' => 'corrupted-ciphertext']);
        $this->google([], $history);

        $this->actingAs($connection->user)->delete(route('admin.gmail.destroy'))->assertSessionHas('warning');

        $this->assertModelMissing($connection);
        $this->assertCount(0, $history);
    }
}
