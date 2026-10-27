<?php

namespace Tests\Feature;

use App\Services\OsonSmsGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use OsonSMS\OsonSMSService\OsonSmsService;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class OsonSmsGatewayTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sms_is_sent_through_the_sdk_to_the_configured_server(): void
    {
        config()->set([
            'services.oson_sms.driver' => 'oson',
            'services.oson_sms.sender' => 'OAPTEKA',
            'osonsmsservice.server_url' => 'https://sms.example.test/custom',
            'osonsmsservice.login' => 'test-login',
            'osonsmsservice.bearer_token' => 'test-token',
        ]);
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"msg_id":123}'),
        ]));
        $handler->push(Middleware::history($history));
        $service = new OsonSmsService;
        (new ReflectionProperty(OsonSmsService::class, 'httpClient'))->setValue($service, new Client(['handler' => $handler]));
        $this->app->instance(OsonSmsService::class, $service);

        $result = app(OsonSmsGateway::class)->send('+992901234567', 'Test message', 'transaction-1');

        $this->assertSame(['transaction_id' => 'transaction-1', 'message_id' => '123'], $result);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('https://sms.example.test/custom/sendsms_v1.php', (string) $request->getUri()->withQuery(''));
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame([
            'from' => 'OAPTEKA',
            'phone_number' => '992901234567',
            'msg' => 'Test message',
            'txn_id' => 'transaction-1',
            'login' => 'test-login',
        ], $query);
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
        $service = new OsonSmsService;
        $handler = new MockHandler([
            new Response(422, [], '{"error":{"msg":"Invalid credentials"}}'),
        ]);
        (new ReflectionProperty(OsonSmsService::class, 'httpClient'))->setValue($service, new Client(['handler' => HandlerStack::create($handler)]));
        $this->app->instance(OsonSmsService::class, $service);

        try {
            app(OsonSmsGateway::class)->send('+992901234567', 'Test message', 'transaction-1');
            $this->fail('Rejected SMS must throw an exception.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('osonsms_log', ['phonenumber' => '992901234567', 'is_sent' => 0, 'server_response' => 'Invalid credentials']);
        }
    }
}
