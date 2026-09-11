<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_normalizes_supported_tajik_phone_formats(): void
    {
        $this->assertSame('+992901234567', PhoneNormalizer::normalize('901234567'));
        $this->assertSame('+992901234567', PhoneNormalizer::normalize('992901234567'));
        $this->assertSame('+992901234567', PhoneNormalizer::normalize('+992 901 234 567'));
        $this->assertSame('+992875404005', PhoneNormalizer::normalize('+992 (87) 540-40-05'));
        $this->assertSame('992901234567', PhoneNormalizer::osonRecipient('+992901234567'));
    }

    public function test_rejects_invalid_phone_formats(): void
    {
        $this->assertSame(null, PhoneNormalizer::normalize('88012345'));
        $this->assertSame(null, PhoneNormalizer::normalize('+99280123456'));
        $this->assertSame(null, PhoneNormalizer::normalize('phone'));
    }
}
