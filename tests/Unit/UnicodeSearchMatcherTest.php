<?php

namespace Tests\Unit;

use App\Services\UnicodeSearchMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnicodeSearchMatcherTest extends TestCase
{
    #[DataProvider('matchingCases')]
    public function test_matches_with_unicode_edit_allowance(string $query, string $text, bool $substring, bool $expected): void
    {
        $this->assertSame($expected, (new UnicodeSearchMatcher)->matches($query, $text, $substring));
    }

    public static function matchingCases(): array
    {
        return [
            'Cyrillic replacement' => ['Амлозипин', 'амлозопин 10 мг №20', false, true],
            'Latin replacement' => ['abcde', 'abxde package', false, true],
            'insertion' => ['abcde', 'abxcde', false, true],
            'deletion' => ['abcde', 'abde', false, true],
            'first character' => ['abcde', 'xbcde', false, true],
            'swap needs two' => ['abcde', 'acbde', false, false],
            'four has no edits' => ['abcd', 'abxd', false, false],
            'nineteen allows one' => ['abcdefghijklmnopqrs', 'xxcdefghijklmnopqrs', false, false],
            'twenty allows two' => ['abcdefghijklmnopqrst', 'xxcdefghijklmnopqrst', false, true],
            'twenty nine rejects three' => [str_repeat('a', 29), 'xxx'.str_repeat('a', 26), false, false],
            'thirty allows three' => [str_repeat('a', 30), 'xxx'.str_repeat('a', 27), false, true],
            'prefix anchored' => ['abcde', 'zzabcde', false, false],
            'substring fuzzy' => ['abcde', 'zzabxde package', true, true],
            'too many edits' => ['abcde', 'abxxe', true, false],
            'literal wildcards' => ['10%_mix', '10abmix', false, false],
        ];
    }
}
