<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\SuspendProviderAccess;
use App\Models\OneTimePassword;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use OsonSMS\OsonSMSService\OsonSmsService;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProviderAccessSuspensionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.provider_access_suspended' => true]);
        Http::preventStrayRequests();
        $this->mock(OsonSmsService::class)->shouldNotReceive('sendSMS');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_every_provider_endpoint_blocks_pending_flows_without_side_effects(bool $json): void
    {
        $user = User::factory()->wholesaler()->create(['phone' => '+992901234567', 'phone_verified_at' => null]);
        $user->organization->update(['status' => 'pending']);
        foreach (['login', 'registration'] as $purpose) {
            OneTimePassword::factory()->create(['purpose' => $purpose, 'account_type' => UserRole::Wholesaler, 'phone' => $user->phone]);
        }
        $organizationCount = Organization::count();
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'provider.'));
        $this->assertCount(15, $routes);
        foreach ($routes as $route) {
            $response = $this->withSession([
                'phone_otp.supplier_login' => $user->phone,
                'phone_otp.supplier_registration' => ['phone' => $user->phone, 'user_id' => $user->id],
                'phone_otp.pending_supplier_login_user_id' => $user->id,
            ])->call($route->methods()[0], route($route->getName()), [
                'phone' => $user->phone, 'code' => '123456', 'supplier_name' => 'Компания', 'city' => 'Душанбе',
            ], [], [], $json ? ['HTTP_ACCEPT' => 'application/json'] : []);
            if ($json) {
                $response->assertStatus(503)->assertJsonPath('message', SuspendProviderAccess::MESSAGE);
            } else {
                $response->assertRedirect(route('login'))->assertSessionHas('warning', SuspendProviderAccess::MESSAGE);
            }
            $response->assertSessionMissing('phone_otp.supplier_login')
                ->assertSessionMissing('phone_otp.supplier_registration')
                ->assertSessionMissing('phone_otp.pending_supplier_login_user_id');
            $this->assertGuest();
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizations', $organizationCount);
        $this->assertDatabaseCount('one_time_passwords', 2);
        $this->assertSame(0, OneTimePassword::whereNotNull('consumed_at')->count());
        $this->assertSame(0, OneTimePassword::sum('attempts'));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    #[TestWith(['supplier', 'GET', false])]
    #[TestWith(['pharmacy', 'GET', false])]
    #[TestWith(['pharmacy', 'PATCH', false])]
    #[TestWith(['supplier', 'PATCH', true])]
    public function test_provider_sessions_are_invalidated_before_reads_writes_and_mode_resolution(string $mode, string $method, bool $json): void
    {
        config(['account-modes.enabled' => true]);
        $user = User::factory()->wholesaler()->create();
        $this->actingAs($user)->withSession(['account_mode.mode' => $mode, 'private_state' => 'secret', '_token' => 'old-token']);
        $oldSessionId = session()->getId();
        $response = $this->call($method, route('profile.'.($method === 'GET' ? 'edit' : 'update')), ['name' => 'Changed'], [], [], $json ? ['HTTP_ACCEPT' => 'application/json'] : []);
        if ($json) {
            $response->assertStatus(503)->assertJsonPath('message', SuspendProviderAccess::MESSAGE);
        } else {
            $response->assertRedirect(route('login'))->assertSessionHas('warning', SuspendProviderAccess::MESSAGE);
        }
        $this->assertGuest();
        $response->assertSessionMissing('private_state')->assertSessionMissing('account_mode');
        $this->assertNotSame('old-token', session()->token());
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertDatabaseHas('organizations', ['id' => $user->organization_id]);
    }

    public function test_redirect_shows_shared_warning_and_hides_provider_login_link(): void
    {
        $this->followingRedirects()->get(route('provider.login'))->assertOk()->assertSee(SuspendProviderAccess::MESSAGE)
            ->assertDontSee('href="'.route('provider.login').'"', false);
    }

    #[TestWith(['pharmacy'])]
    #[TestWith(['admin'])]
    public function test_separate_accounts_sharing_provider_phone_can_still_sign_in(string $role): void
    {
        $provider = User::factory()->wholesaler()->create(['phone' => '+992901234567']);
        $user = User::factory()->{$role}()->create(['phone' => $provider->phone, 'password' => 'password']);
        if ($role === 'admin') {
            $this->get(route('admin.login'))->assertOk();
            $this->post(route('admin.login.authenticate'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        } else {
            config(['auth.development_otp_code' => '000000']);
            $this->get(route('login'))->assertOk();
            $this->withSession(['phone_otp.login' => $user->phone])->post(route('login.otp.verify'), ['code' => '000000'])->assertRedirect();
        }
        $this->assertAuthenticatedAs($user);
        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_disabling_suspension_restores_provider_login_and_access(): void
    {
        config(['auth.provider_access_suspended' => false, 'auth.development_otp_code' => '000000']);
        $user = User::factory()->wholesaler()->create(['phone' => '+992901234567']);
        $this->get(route('login'))->assertSee('href="'.route('provider.login').'"', false);
        $this->get(route('provider.login'))->assertOk();
        $this->get(route('provider.register'))->assertOk();
        $this->withSession(['phone_otp.supplier_login' => $user->phone])->post(route('provider.otp.verify'), ['code' => '000000'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->get(route('profile.edit'))->assertOk();
    }
}
