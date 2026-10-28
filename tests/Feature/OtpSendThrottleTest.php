<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OsonSmsGateway;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OtpSendThrottleTest extends TestCase
{
    use LazilyRefreshDatabase {
        migrateFreshUsing as private baseMigrateFreshUsing;
    }

    /** @return array<string, mixed> */
    protected function migrateFreshUsing(): array
    {
        return array_merge($this->baseMigrateFreshUsing(), ['--force' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['cache.default' => 'array', 'cache.limiter' => 'array', 'session.driver' => 'array']);
        $this->freezeTime();
        Http::preventStrayRequests();
    }

    public function test_different_phones_can_request_codes_from_one_shared_ip(): void
    {
        $this->expectSmsCount(2);
        $first = User::factory()->pharmacy()->create(['phone' => '+992901234567']);
        $second = User::factory()->pharmacy()->create(['phone' => '+992901234568']);

        $this->post(route('login.password.send'), ['phone' => $first->phone])->assertRedirect(route('login'));
        $this->post(route('login.password.send'), ['phone' => $second->phone])->assertRedirect(route('login'));

        $this->assertDatabaseCount('one_time_passwords', 2);
    }

    public function test_phone_can_send_again_after_one_minute_regardless_of_ip_or_format(): void
    {
        $this->expectSmsCount(2);
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567']);
        $this->post(route('login.password.send'), ['phone' => $user->phone])->assertRedirect(route('login'));
        $this->travel(59)->seconds();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post(route('login.password.send'), ['phone' => '901234567'])
            ->assertSessionHasErrors(['phone' => 'Попробуйте отправить код позже.']);
        $this->assertDatabaseCount('one_time_passwords', 1);

        $this->travel(1)->seconds();
        $this->post(route('login.password.send'), ['phone' => $user->phone])->assertRedirect(route('login'));
        $this->assertDatabaseCount('one_time_passwords', 2);
    }

    public function test_same_phone_cannot_bypass_the_minute_limit_by_switching_roles(): void
    {
        $this->expectSmsCount(1);
        $phone = '+992901234567';
        User::factory()->pharmacy()->create(['phone' => $phone]);
        User::factory()->wholesaler()->create(['phone' => $phone]);
        $this->post(route('login.password.send'), ['phone' => $phone])->assertRedirect(route('login'));

        $this->post(route('provider.otp.send'), ['phone' => $phone])
            ->assertSessionHasErrors(['phone' => 'Попробуйте отправить код позже.']);

        $this->assertDatabaseCount('one_time_passwords', 1);
    }

    public function test_phone_has_three_sends_per_allowance(): void
    {
        $this->expectSmsCount(3);
        $phone = '+992901234567';
        User::factory()->pharmacy()->create(['phone' => $phone]);
        RateLimiter::increment('otp-send:day:phone:pharmacy:'.$phone, 86400, 10);
        RateLimiter::increment('otp-send:day:ip:pharmacy:127.0.0.1', 86400, 500);

        foreach (range(1, 3) as $attempt) {
            $this->post(route('login.password.send'), ['phone' => $phone])->assertRedirect(route('login'));
            $this->travel(60)->seconds();
        }

        $this->post(route('login.password.send'), ['phone' => $phone])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('one_time_passwords', 3);
    }

    /** @return array<string, array{string, string}> */
    public static function resendRoutes(): array
    {
        return [
            'pharmacy login' => ['login.otp.resend', 'phone_otp.login'],
            'pharmacy registration' => ['register.otp.resend', 'phone_otp.registration'],
            'supplier login' => ['provider.otp.resend', 'phone_otp.supplier_login'],
            'supplier registration' => ['provider.register.otp.resend', 'phone_otp.supplier_registration'],
        ];
    }

    #[DataProvider('resendRoutes')]
    public function test_resends_share_the_same_phone_limit(string $route, string $sessionKey): void
    {
        $this->expectSmsCount(1);
        $phone = '+992901234567';
        User::factory()->pharmacy()->create(['phone' => $phone]);
        $this->post(route('login.password.send'), ['phone' => $phone])->assertRedirect(route('login'));

        $this->withSession([$sessionKey => ['phone' => $phone]])->post(route($route))
            ->assertSessionHasErrors(['phone' => 'Попробуйте отправить код позже.']);

        $this->assertDatabaseCount('one_time_passwords', 1);
    }

    private function expectSmsCount(int $count): void
    {
        $this->mock(OsonSmsGateway::class, function (MockInterface $mock) use ($count): void {
            $mock->shouldReceive('send')->times($count)->andReturn(['transaction_id' => 'test', 'message_id' => 'test']);
        });
    }
}
