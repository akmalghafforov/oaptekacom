<?php

namespace Tests\Feature;

use App\Services\OsonSmsGateway;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OsonSmsGatewayTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sms_is_sent_to_the_configured_server_with_bounded_timeouts(): void
    {
        config()->set([
            'services.oson_sms.driver' => 'oson',
            'services.oson_sms.sender' => 'OAPTEKA',
            'osonsmsservice.server_url' => 'https://sms.example.test/custom',
            'osonsmsservice.login' => 'test-login',
            'osonsmsservice.bearer_token' => 'test-token',
        ]);
        Http::preventStrayRequests();
        Http::fake(function ($request, array $options) {
            $this->assertSame(10, $options['timeout']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['msg_id' => 123]);
        });

        $result = app(OsonSmsGateway::class)->send('+992901234567', 'Test message', 'transaction-1');

        $this->assertSame(['transaction_id' => 'transaction-1', 'message_id' => '123'], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://sms.example.test/custom/sendsms_v1.php?')
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['from'] === 'OAPTEKA'
            && $request['phone_number'] === '992901234567'
            && $request['msg'] === 'Test message'
            && $request['txn_id'] === 'transaction-1'
            && $request['login'] === 'test-login');
        $this->assertDatabaseHas('osonsms_log', ['msgid' => '123', 'phonenumber' => '992901234567', 'is_sent' => 1]);
    }

    public function test_provider_rejection_is_not_reported_as_a_success(): void
    {
        config()->set([
            'services.oson_sms.driver' => 'oson',
            'osonsmsservice.server_url' => 'https://sms.example.test',
            'osonsmsservice.login' => 'test-login',
            'osonsmsservice.bearer_token' => 'test-token',
        ]);
        Http::preventStrayRequests();
        Http::fake(['sms.example.test/*' => Http::response(['error' => ['msg' => 'Invalid credentials']], 422)]);

        try {
            app(OsonSmsGateway::class)->send('+992901234567', 'Test message', 'transaction-1');
            $this->fail('Rejected SMS must throw an exception.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('osonsms_log', ['phonenumber' => '992901234567', 'is_sent' => 0, 'server_response' => 'Invalid credentials']);
        }
    }
}
