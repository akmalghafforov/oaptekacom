<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OneTimePassword;
use App\Models\User;
use App\Services\OsonSmsGateway;
use App\Services\PhoneOtpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneOtpAllowanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.development_otp_code' => null, 'cache.default' => 'array']);
        $this->freezeTime();
    }

    /** @return array<string, array{UserRole, string}> */
    public static function flows(): array
    {
        $flows = [];
        foreach ([UserRole::Pharmacy, UserRole::Wholesaler] as $role) {
            foreach (['login', 'registration', 'phone_change'] as $purpose) {
                $flows[$role->value.' '.$purpose] = [$role, $purpose];
            }
        }

        return $flows;
    }

    #[DataProvider('flows')]
    public function test_all_flows_enforce_deadlines_and_exhaustion(UserRole $role, string $purpose): void
    {
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->times(3)->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        $service = app(PhoneOtpService::class);
        $phone = '+992901234567';
        $this->assertTrue($service->send($purpose, '90 123-45-67', $role));
        $this->assertSame(now()->addSeconds(60)->getTimestampMs(), $service->state($phone)['resend_available_at']);
        $this->assertRejected($service, $purpose, $role);
        $this->travel(59)->seconds();
        $this->assertRejected($service, $purpose, $role);
        $this->travel(1)->seconds();
        $this->assertTrue($service->send($purpose, $phone, $role));
        $this->assertSame(now()->addSeconds(60)->getTimestampMs(), $service->state($phone)['resend_available_at']);
        $this->travel(60)->seconds();
        $this->assertTrue($service->send($purpose, $phone, $role));
        $reset = $service->state($phone)['allowance_resets_at'];
        $this->assertRejected($service, $purpose, $role);
        $this->travel(60)->seconds();
        $this->assertRejected($service, $purpose, $role);
        $this->assertSame($reset, $service->state($phone)['allowance_resets_at']);
        $otp = OneTimePassword::latest('id')->firstOrFail();
        $otp->update(['code_hash' => Hash::make('123456')]);
        $this->assertTrue($service->consume($purpose, $phone, $role, '123456'));
        $this->assertSame(3, $service->state($phone)['send_count']);
        $this->travel(1739)->seconds();
        $this->assertSame(3, $service->state($phone)['send_count']);
        $this->travel(1)->seconds();
        $this->assertSame(0, $service->state($phone)['send_count']);
    }

    #[DataProvider('flows')]
    public function test_screens_and_resend_requests_keep_the_shared_countdown(UserRole $role, string $purpose): void
    {
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->twice()->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        $phone = '+992901234567';
        $supplier = $role === UserRole::Wholesaler;
        if ($purpose === 'phone_change') {
            $factory = User::factory();
            $this->actingAs(($supplier ? $factory->wholesaler() : $factory->pharmacy())->create(['phone' => '+992909999999']));
            $formRoute = 'profile.phone.verify';
            $resendRoute = 'profile.phone.send';
            $key = 'phone_otp.change_phone';
            $value = $phone;
        } else {
            $prefix = $supplier ? 'provider.' : '';
            $formRoute = $prefix.($purpose === 'login' ? ($supplier ? 'otp.form' : 'login.otp.form') : 'register.otp.form');
            $resendRoute = $prefix.($purpose === 'login' ? ($supplier ? 'otp.resend' : 'login.otp.resend') : 'register.otp.resend');
            $key = 'phone_otp.'.($supplier ? 'supplier_' : '').$purpose;
            $value = $purpose === 'login' ? $phone : ['phone' => $phone];
        }
        if (! $supplier && $purpose !== 'phone_change') {
            User::factory()->pharmacy()->create(['phone' => $phone]);
        }
        app(PhoneOtpService::class)->send($purpose, $phone, $role);
        $this->withSession([$key => $value]);
        $label = ! $supplier && $purpose !== 'phone_change' ? 'Запросить новый пароль через ' : 'Отправить код повторно через ';
        $this->get(route($formRoute))->assertSee($label.'01:00');
        $this->get(route($formRoute))->assertSee($label.'01:00');
        $payload = $purpose === 'phone_change' ? ['phone' => $phone] : [];
        $this->followingRedirects()->from(route($formRoute))->post(route($resendRoute), $payload)->assertSee('Попробуйте отправить код позже.');
        $this->followRedirects = false;
        $this->travel(59)->seconds();
        $this->post(route($resendRoute), $payload)->assertSessionHasErrors('phone');
        $this->get(route($formRoute))->assertSee($label.'00:01');
        $this->travel(1)->seconds();
        $this->post(route($resendRoute), $payload)->assertSessionHasNoErrors();
        $this->get(route($formRoute))->assertSee($label.'01:00');
        $this->assertSame(2, app(PhoneOtpService::class)->state($phone)['send_count']);
    }

    public function test_failed_replacement_preserves_code_and_deadline(): void
    {
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->once()->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        $service = app(PhoneOtpService::class);
        $service->send('login', '+992901234567', UserRole::Pharmacy);
        $otp = OneTimePassword::firstOrFail();
        $otp->update(['code_hash' => Hash::make('123456')]);
        $reset = $service->state('901234567')['allowance_resets_at'];
        $this->travel(60)->seconds();
        $gateway = $this->mock(OsonSmsGateway::class);
        $gateway->shouldReceive('send')->once()->andThrow(new \RuntimeException('Unavailable'));
        $service = app(PhoneOtpService::class);
        $this->assertFalse($service->send('login', '901234567', UserRole::Pharmacy));
        $this->assertSame('sent', $otp->fresh()->status);
        $this->assertSame($reset, $service->state('901234567')['allowance_resets_at']);
        $this->assertFalse($service->consume('login', '901234567', UserRole::Pharmacy, '000000'));
        $this->assertTrue($service->consume('login', '901234567', UserRole::Pharmacy, '123456'));
        $this->travel(1740)->seconds();
        $this->assertSame(0, $service->state('901234567')['send_count']);
    }

    public function test_contended_lock_sends_nothing(): void
    {
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $lock = Cache::lock('otp:lock:'.hash('sha256', '+992901234567'), 60);
        $this->assertTrue($lock->get());
        try {
            $this->assertRejected(app(PhoneOtpService::class), 'login', UserRole::Pharmacy);
            $this->assertDatabaseCount('one_time_passwords', 0);
        } finally {
            $lock->release();
        }
    }

    public function test_successful_replacement_invalidates_only_matching_scope(): void
    {
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->times(4)->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        $service = app(PhoneOtpService::class);
        $service->send('login', '901234567', UserRole::Pharmacy);
        $this->travel(60)->seconds();
        $service->send('registration', '901234567', UserRole::Pharmacy);
        $this->travel(60)->seconds();
        $service->send('login', '901234567', UserRole::Wholesaler);
        $this->travel(30)->minutes();
        $service->send('login', '901234567', UserRole::Pharmacy);
        $this->assertDatabaseHas('one_time_passwords', ['id' => 1, 'status' => 'invalidated']);
        $this->assertDatabaseHas('one_time_passwords', ['id' => 2, 'status' => 'sent', 'consumed_at' => null]);
        $this->assertDatabaseHas('one_time_passwords', ['id' => 3, 'status' => 'sent', 'consumed_at' => null]);
    }

    #[DataProvider('supportContacts')]
    public function test_third_code_remains_available_with_fallback(?string $contact, bool $valid): void
    {
        config(['contact.whatsapp_phone' => $contact]);
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->times(3)->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        $service = app(PhoneOtpService::class);
        foreach (range(1, 3) as $attempt) {
            $service->send('login', '901234567', UserRole::Pharmacy);
            if ($attempt < 3) {
                $this->travel(60)->seconds();
            }
        }
        $this->withSession(['phone_otp.login' => '+992901234567']);
        $response = $this->get(route('login.otp.form'))
            ->assertSee('Не получили пароль? Свяжитесь с нами в WhatsApp.')
            ->assertSee('Подтвердить');
        $valid ? $response->assertSee('https://wa.me/992989999292') : $response->assertDontSee('https://wa.me/');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//button[@data-otp-resend-button and @disabled]')->length);
        $this->assertSame(0, $xpath->query('//div[@data-otp-fallback and @hidden]')->length);
        $otp = OneTimePassword::latest('id')->firstOrFail();
        $otp->update(['code_hash' => Hash::make('123456')]);
        $this->assertTrue($service->consume('login', '901234567', UserRole::Pharmacy, '123456'));
    }

    /** @return list<array{?string, bool}> */
    public static function supportContacts(): array
    {
        return [['+992 (98) 999-92-92', true], [null, false], ['invalid', false]];
    }

    public function test_development_sends_share_policy_without_external_delivery(): void
    {
        config(['auth.development_otp_code' => '000000']);
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $service = app(PhoneOtpService::class);
        $this->assertTrue($service->send('login', '901234567', UserRole::Pharmacy));
        $this->assertRejected($service, 'phone_change', UserRole::Wholesaler);
        $this->assertSame(1, $service->state('901234567')['send_count']);
    }

    private function assertRejected(PhoneOtpService $service, string $purpose, UserRole $role): void
    {
        try {
            $service->send($purpose, '+992901234567', $role);
            $this->fail('Expected phone validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }
    }
}
