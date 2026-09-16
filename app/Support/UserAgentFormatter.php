<?php

namespace App\Support;

class UserAgentFormatter
{
    public function format(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Неизвестное устройство';
        }

        return $this->browser($userAgent).' · '.$this->operatingSystem($userAgent);
    }

    private function browser(string $userAgent): string
    {
        return match (true) {
            preg_match('/(?:Edg|Edge)\//i', $userAgent) === 1 => 'Edge',
            preg_match('/(?:OPR|Opera)\//i', $userAgent) === 1 => 'Opera',
            preg_match('/(?:Chrome|CriOS)\//i', $userAgent) === 1 => 'Chrome',
            preg_match('/(?:Firefox|FxiOS)\//i', $userAgent) === 1 => 'Firefox',
            preg_match('/Safari\//i', $userAgent) === 1 => 'Safari',
            default => 'Неизвестный браузер',
        };
    }

    private function operatingSystem(string $userAgent): string
    {
        return match (true) {
            preg_match('/Windows NT/i', $userAgent) === 1 => 'Windows',
            preg_match('/Android/i', $userAgent) === 1 => 'Android',
            preg_match('/(?:iPhone|iPad|iPod)/i', $userAgent) === 1 => 'iOS',
            preg_match('/(?:Macintosh|Mac OS X)/i', $userAgent) === 1 => 'macOS',
            preg_match('/Ubuntu/i', $userAgent) === 1 => 'Ubuntu',
            preg_match('/Linux/i', $userAgent) === 1 => 'Linux',
            default => 'Неизвестная ОС',
        };
    }
}
