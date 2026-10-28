<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LoginSessionLifetime;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LoginSessionLifetimeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'auth.development_otp_code' => '000000']);
        app('session')->forgetDrivers();
        Route::middleware(['web', 'auth'])->post('/session-probe', function () {
            DB::table('users')->where('id', auth()->id())->update(['name' => 'Protected action']);

            return response('ok');
        });
    }

    #[TestWith(['pharmacy', 'login'])]
    #[TestWith(['wholesaler', 'login'])]
    #[TestWith(['pharmacy', 'registration'])]
    #[TestWith(['wholesaler', 'registration'])]
    #[TestWith(['pharmacy', 'replacement'])]
    #[TestWith(['wholesaler', 'replacement'])]
    #[TestWith(['admin', 'login'])]
    public function test_authentication_starts_a_persistent_thirty_day_session(string $role, string $flow): void
    {
        $this->freezeTime();
        $user = User::factory()->{$role}()->create(['phone' => '+992901234567', 'password' => $role === 'pharmacy' ? '000000' : 'password']);
        if ($role === 'admin') {
            $response = $this->post(route('admin.login.authenticate'), ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        } else {
            $supplier = $role === 'wholesaler';
            if ($flow === 'registration') {
                $user->organization->update(['status' => 'pending']);
                $user->update(['phone_verified_at' => null]);
                $key = 'phone_otp.'.($supplier ? 'supplier_' : '').'registration';
                $state = ['phone' => $user->phone, 'user_id' => $user->id];
                $route = ($supplier ? 'provider.' : '').'register.otp.verify';
            } elseif ($flow === 'replacement') {
                $key = 'phone_otp.pending_'.($supplier ? 'supplier_' : '').'login_user_id';
                $state = $user->id;
                $route = ($supplier ? 'provider' : 'login').'.session.confirm';
            } else {
                $key = 'phone_otp.'.($supplier ? 'supplier_' : '').'login';
                $state = $user->phone;
                $route = ($supplier ? 'provider' : 'login').'.otp.verify';
            }
            $response = $this->withSession([$key => $state, 'phone_otp.pending_password_hash' => $user->password])->post(route($route), ['code' => '000000']);
        }
        $response->assertSessionHas(LoginSessionLifetime::EXPIRES_AT, now()->addDays(30)->timestamp);
        $this->assertAuthenticatedAs($user);
        $response->assertCookieMissing(auth()->guard()->getRecallerName());
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertSame(now()->addDays(30)->timestamp, $cookie->getExpiresTime());
        $this->newBrowserRequest($cookie->getValue(), true);
        $this->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_activity_preserves_initial_month_and_extends_later_visits(): void
    {
        $this->freezeTime();
        $start = now();
        $user = User::factory()->pharmacy()->create();
        $id = $this->storedSession($user, $start->copy()->addDays(30)->timestamp);
        foreach ([[5, 30], [25, 35], [34, 44]] as [$day, $expiryDay]) {
            $this->travelTo($start->copy()->addDays($day));
            $this->newBrowserRequest($id);
            $this->get(route('profile.edit'))->assertOk()
                ->assertSessionHas(LoginSessionLifetime::EXPIRES_AT, $start->copy()->addDays($expiryDay)->timestamp);
            $this->assertAuthenticatedAs($user);
        }
    }

    #[TestWith([0])]
    #[TestWith([1])]
    public function test_expired_session_cannot_execute_protected_actions(int $secondsAfter): void
    {
        $this->freezeTime();
        $user = User::factory()->pharmacy()->create();
        $id = $this->storedSession($user, now()->subSeconds($secondsAfter)->timestamp);
        $this->newBrowserRequest($id);
        $this->post('/session-probe')->assertRedirectToRoute('login')->assertSessionMissing(LoginSessionLifetime::EXPIRES_AT);
        $this->assertGuest();
        $this->assertNotSame('Protected action', $user->fresh()->name);
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
    }

    public function test_existing_session_receives_a_month_and_logout_ends_it(): void
    {
        $this->freezeTime();
        $user = User::factory()->pharmacy()->create();
        $id = $this->storedSession($user, null);
        $this->newBrowserRequest($id);
        $this->get(route('profile.edit'))->assertOk()->assertSessionHas(LoginSessionLifetime::EXPIRES_AT, now()->addDays(30)->timestamp);
        $this->post(route('logout'))->assertRedirect();
        $this->newBrowserRequest($id);
        $this->get(route('profile.edit'))->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    #[TestWith([false, false])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function test_device_detection_respects_expiry_and_encryption(bool $encrypted, bool $expired): void
    {
        $this->freezeTime();
        config(['session.encrypt' => $encrypted]);
        app('session')->forgetDrivers();
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '000000']);
        $payload = json_encode([auth()->guard()->getName() => $user->id, LoginSessionLifetime::EXPIRES_AT => $expired ? now()->timestamp : now()->addDays(30)->timestamp]);
        DB::table('sessions')->insert([
            'id' => str_repeat('a', 40), 'user_id' => $user->id,
            'payload' => base64_encode($encrypted ? encrypt($payload) : $payload),
            'last_activity' => now()->timestamp,
        ]);
        $this->withSession(['phone_otp.login' => $user->phone])->post(route('login.otp.verify'), ['code' => '000000'])
            ->assertRedirectToRoute($expired ? 'catalog' : 'login.session.confirmation');
    }

    public function test_legacy_remember_cookie_cannot_restore_authentication(): void
    {
        $user = User::factory()->admin()->create(['remember_token' => 'legacy-token']);
        $guard = auth()->guard();
        $this->withCookie($guard->getRecallerName(), $user->id.'|legacy-token|'.$user->password)
            ->get(route('profile.edit'))->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    private function storedSession(User $user, ?int $expiry): string
    {
        $store = app('session')->driver();
        $store->start();
        $store->put(auth()->guard()->getName(), $user->id);
        if ($expiry !== null) {
            $store->put(LoginSessionLifetime::EXPIRES_AT, $expiry);
        }
        $store->save();
        DB::table('sessions')->where('id', $store->getId())->update(['user_id' => $user->id]);
        $id = $store->getId();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        return $id;
    }

    private function newBrowserRequest(string $sessionId, bool $encrypted = false): void
    {
        auth()->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        if ($encrypted) {
            $this->withUnencryptedCookie(config('session.cookie'), $sessionId);
        } else {
            $this->withCookie(config('session.cookie'), $sessionId);
        }
    }
}
