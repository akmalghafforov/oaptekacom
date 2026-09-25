<?php

namespace Tests\Unit;

use App\Enums\PriceListRowDisposition;
use App\Services\PriceList\ProfileValidator;
use App\Services\PriceList\RowParser;
use App\Services\PriceList\ValueNormalizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PriceListParsingTest extends TestCase
{
    public function test_normalizes_unicode_whitespace_dashes_and_cyrillic_case(): void
    {
        $normalizer = new ValueNormalizer;

        $this->assertSame('парацетамол-500 мг', $normalizer->name('  ПАРАЦЕТАМОЛ— 500   мг '));
        $this->assertSame('abc-12', $normalizer->sku(' AB C-12 '));
    }

    public function test_parses_locale_decimals_excel_dates_and_two_digit_years(): void
    {
        $normalizer = new ValueNormalizer;

        $this->assertSame(1234.50, $normalizer->decimal('1 234,50', ',', ' '));
        $this->assertSame('2026-01-01', $normalizer->date(46023, ['d.m.Y'])->toDateString());
        $this->assertSame('2069-12-31', $normalizer->date('31.12.69', ['d.m.y'])->toDateString());
    }

    public function test_row_parser_keeps_unknown_quantity_and_isolates_invalid_price(): void
    {
        $profile = ProfileValidator::defaults();
        $parser = app(RowParser::class);

        $valid = $parser->parse(['A' => 'Аспирин', 'B' => '12.50'], 2, $profile);
        $invalid = $parser->parse(['A' => 'Аспирин', 'B' => '0'], 3, $profile);

        $this->assertSame(PriceListRowDisposition::Valid, $valid['disposition']);
        $this->assertNull($valid['parsed_values']['quantity']);
        $this->assertSame(PriceListRowDisposition::Error, $invalid['disposition']);
    }

    public function test_zero_stock_and_expired_rows_remain_processable(): void
    {
        $this->travelTo('2026-09-15 12:00:00');
        $profile = array_replace(ProfileValidator::defaults(), [
            'mapping' => ['name' => 'A', 'price' => 'B', 'quantity' => 'C', 'expiration' => 'D'],
        ]);

        $result = app(RowParser::class)->parse(['A' => 'Аспирин', 'B' => '12.50', 'C' => '0', 'D' => '14.09.2026'], 2, $profile);

        $this->assertSame(PriceListRowDisposition::Warning, $result['disposition']);
        $this->assertSame(0.0, $result['parsed_values']['quantity']);
        $this->assertSame('2026-09-14', $result['parsed_values']['expiration']);
        $this->assertSame(['Срок годности истёк.'], $result['warnings']);
    }

    public function test_row_parser_treats_absent_and_unparseable_expiration_as_undetected(): void
    {
        $profile = array_replace(ProfileValidator::defaults(), [
            'mapping' => ['name' => 'A', 'price' => 'B', 'expiration' => 'C'],
        ]);
        $parser = app(RowParser::class);

        $absent = $parser->parse(['A' => 'Аспирин', 'B' => '12.50', 'C' => ''], 2, $profile);
        $unparseable = $parser->parse(['A' => 'Ибупрофен', 'B' => '15.00', 'C' => 'неизвестно'], 3, $profile);

        $this->assertSame(PriceListRowDisposition::Valid, $absent['disposition']);
        $this->assertNull($absent['parsed_values']['expiration']);
        $this->assertSame([], $absent['warnings']);
        $this->assertSame(PriceListRowDisposition::Valid, $unparseable['disposition']);
        $this->assertNull($unparseable['parsed_values']['expiration']);
        $this->assertSame([], $unparseable['warnings']);
    }

    public function test_profile_rejects_unexpected_configuration_and_operators(): void
    {
        $this->expectException(ValidationException::class);

        app(ProfileValidator::class)->validate(['data_row' => 2, 'mapping' => ['name' => 'A'], 'service_class' => 'Dangerous']);
    }

    public function test_profile_rejects_duplicate_column_assignments(): void
    {
        $this->expectException(ValidationException::class);

        app(ProfileValidator::class)->validate(['data_row' => 2, 'mapping' => ['name' => 'A', 'price' => 'A']]);
    }
}
