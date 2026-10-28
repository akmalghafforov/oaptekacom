<?php

namespace Tests\Feature;

use App\Jobs\DeliverSupplierReview;
use App\Models\Organization;
use App\Models\SupplierReview;
use App\Models\User;
use App\Services\OrganizationApprovalService;
use App\Services\TelegramSupplierReviews;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TelegramSupplierReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        config()->set('telegram', ['enabled' => true, 'bot_token' => '123:fake', 'webhook_secret' => 'secret', 'group_chat_id' => '-100123', 'admin_mapping' => ['12345' => $this->admin->id]]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => -100123]]])]);
    }

    private function review(bool $sent = true): SupplierReview
    {
        $supplier = Organization::factory()->wholesaler()->pending()->create(['name' => 'Компания Тест', 'city' => 'Душанбе', 'phone' => '+992901234567']);
        User::factory()->wholesaler($supplier)->create(['phone_verified_at' => now()]);

        return SupplierReview::create(['organization_id' => $supplier->id, 'token' => bin2hex(random_bytes(16)),
            'chat_id' => $sent ? '-100123' : null, 'message_id' => $sent ? '42' : null,
            'delivery_state' => $sent ? 'sent' : 'pending', 'delivered_version' => $sent ? 1 : 0]);
    }

    /** @return array<string, mixed> */
    private function callbackPayload(SupplierReview $review, string $action = 'approve'): array
    {
        return ['callback_query' => ['id' => 'callback', 'from' => ['id' => 12345], 'data' => 'supplier:'.$action.':'.$review->token,
            'message' => ['message_id' => 42, 'chat' => ['id' => -100123]]]];
    }

    /** @param array<string, mixed> $payload */
    private function webhook(array $payload): TestResponse
    {
        return $this->postJson(route('telegram.webhook'), $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'secret']);
    }

    public function test_verified_supplier_signup_creates_exactly_one_review(): void
    {
        config()->set('auth.development_otp_code', '123456');
        $supplier = Organization::factory()->wholesaler()->pending()->create();
        $user = User::factory()->wholesaler($supplier)->create(['phone' => '+992901234567', 'phone_verified_at' => null]);
        $session = ['phone_otp.supplier_registration' => ['phone' => $user->phone, 'user_id' => $user->id]];
        $this->withSession($session)->post(route('provider.register.otp.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('supplier_reviews', 0);
        $this->withSession($session)->post(route('provider.register.otp.verify'), ['code' => '123456'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('supplier_reviews', 1);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        Queue::assertPushed(DeliverSupplierReview::class, 1);
        $this->withSession($session)->post(route('provider.register.otp.verify'), ['code' => '123456']);
        $this->assertDatabaseCount('supplier_reviews', 1);
        Queue::assertPushed(DeliverSupplierReview::class, 1);
    }

    public function test_review_creation_rolls_back_with_signup(): void
    {
        $supplier = Organization::factory()->wholesaler()->pending()->create();
        DB::beginTransaction();
        app(TelegramSupplierReviews::class)->create($supplier);
        DB::rollBack();
        $this->assertDatabaseCount('supplier_reviews', 0);
        Queue::assertNothingPushed();
    }

    public function test_delivery_contains_details_buttons_and_tracks_message(): void
    {
        $review = $this->review(false);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Компания Тест') && str_contains($request['text'], '+992901234567')
            && $request['reply_markup']['inline_keyboard'][0][0]['text'] === 'Approve'
            && $request['reply_markup']['inline_keyboard'][0][1]['callback_data'] === 'supplier:reject:'.$review->token);
        $this->assertSame('42', $review->fresh()->message_id);
        $this->assertSame('sent', $review->fresh()->delivery_state);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        Http::assertSentCount(1);
    }

    #[TestWith(['approve', 'active', 'organization.approved'])]
    #[TestWith(['reject', 'blocked', 'organization.rejected'])]
    public function test_decisions_are_attributed_and_idempotent(string $action, string $status, string $event): void
    {
        $review = $this->review();
        $pharmacy = Organization::factory()->pharmacy()->pending()->create();
        $review->organization->update(['linked_pharmacy_organization_id' => $pharmacy->id]);
        $this->webhook($this->callbackPayload($review, $action))->assertOk();
        $this->webhook($this->callbackPayload($review, 'approve'))->assertOk();
        $this->webhook($this->callbackPayload($review, 'reject'))->assertOk();
        $this->assertSame($status, $review->organization->fresh()->status);
        $this->assertSame($action === 'approve' ? 'active' : 'pending', $pharmacy->fresh()->status);
        $user = $review->organization->users()->firstOrFail();
        $this->assertSame($action === 'reject', $user->is_blocked);
        $this->assertSame($action === 'approve', $user->approved_at !== null);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseHas('audit_events', ['event' => $event, 'user_id' => $this->admin->id]);
        $this->assertSame('12345', $review->fresh()->telegram_user_id);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/editMessageText') && $request['reply_markup']['inline_keyboard'] === []
            && str_contains($request['text'], $this->admin->name) && str_contains($request['text'], '12345'));
    }

    #[TestWith(['missing'])]
    #[TestWith(['wrong'])]
    public function test_webhook_requires_secret(string $secret): void
    {
        $this->postJson(route('telegram.webhook'), [], $secret === 'wrong' ? ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'] : [])->assertForbidden();
        Http::assertNothingSent();
    }

    #[TestWith(['unmapped'])]
    #[TestWith(['blocked'])]
    #[TestWith(['nonadmin'])]
    #[TestWith(['deleted'])]
    #[TestWith(['token'])]
    #[TestWith(['chat'])]
    #[TestWith(['message'])]
    #[TestWith(['action'])]
    #[TestWith(['malformed'])]
    #[TestWith(['pharmacy'])]
    public function test_invalid_or_unauthorized_callbacks_cannot_mutate(string $case): void
    {
        $review = $this->review();
        $payload = $this->callbackPayload($review);
        match ($case) {
            'unmapped' => $payload['callback_query']['from']['id'] = 999,
            'blocked' => $this->admin->forceFill(['is_blocked' => true])->save(),
            'nonadmin' => $this->admin->forceFill(['role' => 'pharmacy'])->save(),
            'deleted' => $this->admin->delete(),
            'token' => $payload['callback_query']['data'] = 'supplier:approve:'.str_repeat('a', 32),
            'chat' => $payload['callback_query']['message']['chat']['id'] = -999,
            'message' => $payload['callback_query']['message']['message_id'] = 99,
            'action' => $payload['callback_query']['data'] = 'supplier:delete:'.$review->token,
            'malformed' => $payload['callback_query']['from'] = 'invalid',
            'pharmacy' => $review->organization->update(['type' => 'pharmacy']),
        };
        $this->webhook($payload)->assertOk();
        $this->assertSame('pending', $review->organization->fresh()->status);
        $this->assertNull($review->fresh()->decision);
        $this->assertDatabaseCount('audit_events', 0);
    }

    #[TestWith(['profile'])]
    #[TestWith(['sms'])]
    public function test_approval_prerequisites_are_retained(string $case): void
    {
        $review = $this->review();
        if ($case === 'profile') {
            $review->organization->update(['setup_required' => true]);
        } else {
            $review->organization->users()->update(['phone_verified_at' => null]);
        }
        $this->webhook($this->callbackPayload($review))->assertOk();
        $this->assertSame('pending', $review->organization->fresh()->status);
        $this->assertNull($review->fresh()->decision);
    }

    public function test_web_approval_wins_and_overrides_never_reactivate_buttons(): void
    {
        $review = $this->review();
        $this->actingAs($this->admin)->post(route('admin.approve', $review->organization))->assertSessionHasNoErrors();
        $this->webhook($this->callbackPayload($review, 'reject'))->assertOk();
        $this->assertSame('active', $review->organization->fresh()->status);
        $this->assertSame('approved', $review->fresh()->decision);
        $this->assertNull($review->fresh()->telegram_user_id);
        $decidedAt = $review->fresh()->decided_at->toIso8601String();
        foreach (['blocked', 'pending'] as $status) {
            $this->patch(route('admin.providers.update', $review->organization), ['name' => 'Updated', 'status' => $status, 'minimum_order' => 0])->assertSessionHasNoErrors();
        }
        $this->webhook($this->callbackPayload($review))->assertOk();
        $this->assertSame('pending', $review->organization->fresh()->status);
        $message = app(TelegramSupplierReviews::class)->message($review->fresh());
        $this->assertSame([], $message['reply_markup']['inline_keyboard']);
        $this->assertStringContainsString('approved', $message['text']);
        $this->assertSame($decidedAt, $review->fresh()->decided_at->toIso8601String());
        $this->assertDatabaseCount('audit_events', 3);
    }

    #[TestWith(['disabled'])]
    #[TestWith(['invalid_mapping'])]
    #[TestWith(['invalid_secret'])]
    public function test_invalid_configuration_fails_closed(string $case): void
    {
        config()->set(match ($case) {
            'disabled' => 'telegram.enabled', 'invalid_mapping' => 'telegram.admin_mapping', 'invalid_secret' => 'telegram.webhook_secret'
        }, match ($case) {
            'disabled' => false, 'invalid_mapping' => ['bad' => 1], 'invalid_secret' => 'bad secret'
        });
        $review = $this->review(false);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        $this->webhook($this->callbackPayload($review))->assertForbidden();
        Http::assertNothingSent();
    }

    #[TestWith([429, 'retry'])]
    #[TestWith([500, 'ambiguous'])]
    #[TestWith([403, 'failed'])]
    public function test_send_failures_are_classified_safely(int $code, string $state): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => $code], $code)]);
        $review = $this->review(false);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        $this->assertSame($state, $review->fresh()->delivery_state);
        $this->artisan('telegram:reconcile-reviews')->assertSuccessful();
        Queue::assertNothingPushed();
        if ($state === 'retry') {
            $this->travel(2)->minutes();
            $this->artisan('telegram:reconcile-reviews')->assertSuccessful();
            Queue::assertPushed(DeliverSupplierReview::class);
        }
    }

    public function test_transport_failure_does_not_resend_ambiguous_send(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::failedConnection()]);
        $review = $this->review(false);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        $this->assertSame('ambiguous', $review->fresh()->delivery_state);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        $this->artisan('telegram:reconcile-reviews')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_interrupted_send_is_flagged_and_interrupted_edit_is_recovered(): void
    {
        $review = $this->review(false);
        $review->update(['delivery_state' => 'sending']);
        $this->travel(6)->minutes();
        $this->artisan('telegram:reconcile-reviews')->assertSuccessful();
        $this->assertSame('ambiguous', $review->fresh()->delivery_state);
        $review->update(['message_id' => '42', 'chat_id' => '-100123', 'delivery_state' => 'editing']);
        $this->artisan('telegram:reconcile-reviews')->assertSuccessful();
        Queue::assertPushed(DeliverSupplierReview::class);
    }

    public function test_safe_edit_retry_and_not_modified_response(): void
    {
        $review = $this->review();
        app(OrganizationApprovalService::class)->approve($review->organization, $this->admin);
        Http::swap(new Factory);
        Http::fake(['*' => Http::failedConnection()]);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        $this->assertSame('retry', $review->fresh()->delivery_state);
        $this->travel(2)->minutes();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad Request: message is not modified'], 400)]);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        $this->assertSame('sent', $review->fresh()->delivery_state);
        $this->assertSame($review->fresh()->version, $review->fresh()->delivered_version);
    }

    public function test_pharmacy_verification_does_not_create_a_review(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->pending()->create();
        $user = User::factory()->pharmacy($pharmacy)->create(['phone' => '+992901234567', 'phone_verified_at' => null, 'password' => '123456']);
        $this->withSession(['phone_otp.registration' => ['phone' => $user->phone, 'user_id' => $user->id]])
            ->post(route('register.otp.verify'), ['code' => '123456'])->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseCount('supplier_reviews', 0);
        Queue::assertNothingPushed();
    }

    public function test_directory_pending_approval_requires_verified_account(): void
    {
        $review = $this->review();
        $review->organization->users()->update(['phone_verified_at' => null]);
        $this->actingAs($this->admin)->patchJson(route('admin.providers.update', $review->organization), ['name' => 'Test', 'status' => 'active', 'minimum_order' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('organization');
        $this->assertSame('pending', $review->organization->fresh()->status);
        $this->assertNull($review->fresh()->decision);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_archive_invalidates_buttons_even_after_reopening(): void
    {
        $review = $this->review();
        $this->actingAs($this->admin)->post(route('admin.providers.archive', $review->organization))->assertRedirect();
        $this->assertSame('overridden', $review->fresh()->decision);
        $this->patch(route('admin.providers.update', $review->organization), ['name' => 'Test', 'status' => 'pending', 'minimum_order' => 0])->assertSessionHasNoErrors();
        $this->webhook($this->callbackPayload($review))->assertOk();
        $this->assertSame('pending', $review->organization->fresh()->status);
        $this->assertSame([], app(TelegramSupplierReviews::class)->message($review->fresh())['reply_markup']['inline_keyboard']);
    }

    public function test_telegram_decision_prevents_later_web_approval(): void
    {
        $review = $this->review();
        $this->webhook($this->callbackPayload($review, 'reject'))->assertOk();
        $this->actingAs($this->admin)->postJson(route('admin.approve', $review->organization))->assertUnprocessable();
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame('blocked', $review->organization->fresh()->status);
    }

    public function test_edit_failure_stops_after_bounded_attempts(): void
    {
        $review = $this->review();
        $review->update(['version' => 2, 'attempts' => 4]);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 500], 500)]);
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        $this->assertSame('failed', $review->fresh()->delivery_state);
        $this->artisan('telegram:reconcile-reviews')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_decision_during_send_leaves_an_outstanding_edit(): void
    {
        $review = $this->review(false);
        Http::swap(new Factory);
        Http::fake(function () use ($review) {
            app(OrganizationApprovalService::class)->approve($review->organization, $this->admin);

            return Http::response(['ok' => true, 'result' => ['message_id' => 42, 'chat' => ['id' => -100123]]]);
        });
        (new DeliverSupplierReview($review->id))->handle(app(TelegramSupplierReviews::class));
        $this->assertGreaterThan($review->fresh()->delivered_version, $review->fresh()->version);
        $this->assertSame('approved', $review->fresh()->decision);
        $this->artisan('telegram:reconcile-reviews')->assertSuccessful();
        Queue::assertPushed(DeliverSupplierReview::class);
    }

    public function test_csrf_exemption_is_limited_to_webhook(): void
    {
        $this->app->instance('env', 'local');
        $this->webhook(['update_id' => 123])->assertOk();
        $this->post(route('provider.register.otp.verify'), ['code' => '123456'])->assertStatus(419);
    }

    public function test_webhook_registration_uses_secret_and_only_callback_updates(): void
    {
        config()->set('app.url', 'https://example.test');
        app('url')->forceRootUrl('https://example.test');
        $this->artisan('telegram:register-webhook')->assertSuccessful();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') && $request['secret_token'] === 'secret' && $request['allowed_updates'] === ['callback_query']);
    }
}
