<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_trusted_proxy_preserves_https_url_and_client_address(): void
    {
        config(['trustedproxy.proxies' => ['172.30.85.10']]);
        Route::get('/proxy-check', fn () => response()->json(['secure' => request()->secure(), 'ip' => request()->ip(), 'url' => url('/login')]));
        $this->withServerVariables(['REMOTE_ADDR' => '172.30.85.10'])->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '203.0.113.10'])->get('http://op.fannjourney.com/proxy-check')->assertJson(['secure' => true, 'ip' => '203.0.113.10', 'url' => 'https://op.fannjourney.com/login']);
    }

    public function test_untrusted_source_cannot_spoof_https_or_client_address(): void
    {
        config(['trustedproxy.proxies' => ['172.30.85.10']]);
        Route::get('/proxy-check', fn () => response()->json(['secure' => request()->secure(), 'ip' => request()->ip()]));
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.10'])->get('http://op.fannjourney.com/proxy-check')->assertJson(['secure' => false, 'ip' => '198.51.100.10']);
    }
}
