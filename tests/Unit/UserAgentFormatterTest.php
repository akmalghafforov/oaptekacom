<?php

namespace Tests\Unit;

use App\Support\UserAgentFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAgentFormatterTest extends TestCase
{
    #[DataProvider('userAgents')]
    public function test_formats_a_readable_device_summary(?string $userAgent, string $expected): void
    {
        $this->assertSame($expected, (new UserAgentFormatter)->format($userAgent));
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function userAgents(): array
    {
        return [
            'Chrome on Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140.0.0.0 Safari/537.36', 'Chrome · Linux'],
            'Firefox on Ubuntu' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:143.0) Gecko/20100101 Firefox/143.0', 'Firefox · Ubuntu'],
            'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', 'Edge · Windows'],
            'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.6 Safari/605.1.15', 'Safari · macOS'],
            'missing user agent' => [null, 'Неизвестное устройство'],
            'unrecognized user agent' => ['Custom Client', 'Неизвестный браузер · Неизвестная ОС'],
        ];
    }
}
