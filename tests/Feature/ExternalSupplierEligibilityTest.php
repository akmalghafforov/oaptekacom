<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SupplierImportProfile;
use App\Services\ExternalSupplierEligibility;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExternalSupplierEligibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_allows_missing_suppliers_and_suppliers_without_active_profiles(): void
    {
        Organization::factory()->wholesaler()->create(['name' => 'Фирма без профиля']);
        SupplierImportProfile::factory()->create(['is_active' => false])->supplier->update(['name' => 'Неактивный профиль']);

        $results = app(ExternalSupplierEligibility::class)->review([
            $this->partner('Новый поставщик'), $this->partner('Фирма без профиля'), $this->partner('Неактивный профиль', 'pricelist_tj'),
        ], []);

        $this->assertSame(['eligible', 'eligible', 'eligible'], array_column($results, 'outcome'));
        $this->assertSame(['supplier_not_registered', 'no_active_import_profile', 'no_active_import_profile'], array_column($results, 'reason'));
    }

    public function test_excludes_active_profiles_using_normalized_cyrillic_names_and_additional_phones(): void
    {
        $profile = SupplierImportProfile::factory()->create();
        $profile->supplier->update(['name' => 'Осон-Фарм', 'additional_phones' => ['+992 92 743-05-70']]);

        $results = app(ExternalSupplierEligibility::class)->review([
            $this->partner('ОСОН ФАРМ'), $this->partner('Другое название', phones: ['927430570']),
        ], []);

        $this->assertSame(['active_import_profile', 'active_import_profile'], array_column($results, 'reason'));
        $this->assertSame([[$profile->supplier_organization_id], [$profile->supplier_organization_id]], array_column($results, 'supplier_organization_ids'));
    }

    public function test_excludes_senders_by_name_phone_and_local_supplier_id_without_requiring_a_profile(): void
    {
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'Известный поставщик']);

        $results = app(ExternalSupplierEligibility::class)->review([
            $this->partner('По имени'),
            $this->partner('По телефону', phones: ['00992927430570']),
            $this->partner('Известный поставщик'),
        ], [
            ['name' => 'По имени'],
            ['phones' => ['+992 92 743-05-70']],
            ['supplier_organization_id' => $supplier->id],
        ]);

        $this->assertSame(['already_sends_price_list', 'already_sends_price_list', 'already_sends_price_list'], array_column($results, 'reason'));
    }

    public function test_holds_ambiguous_and_blocked_suppliers_for_review(): void
    {
        Organization::factory()->wholesaler()->count(2)->create(['name' => 'Общее имя']);
        Organization::factory()->wholesaler()->blocked()->create(['name' => 'Заблокирован']);

        $results = app(ExternalSupplierEligibility::class)->review([$this->partner('Общее имя'), $this->partner('Заблокирован')], []);

        $this->assertSame(['review', 'review'], array_column($results, 'outcome'));
        $this->assertSame(['ambiguous_supplier', 'inactive_supplier'], array_column($results, 'reason'));
    }

    public function test_any_matching_active_profile_prevents_scraping_even_with_conflicting_identity(): void
    {
        Organization::factory()->wholesaler()->create(['name' => 'Общее имя']);
        SupplierImportProfile::factory()->create()->supplier->update(['phone' => '+992927430570']);

        $result = app(ExternalSupplierEligibility::class)->review([$this->partner('Общее имя', phones: ['927430570'])], [])[0];

        $this->assertSame('skip', $result['outcome']);
        $this->assertSame('active_import_profile', $result['reason']);
    }

    public function test_ignores_pharmacies_and_does_not_match_empty_normalized_values(): void
    {
        Organization::factory()->pharmacy()->create(['name' => 'Внешняя фирма']);
        Organization::factory()->wholesaler()->create(['name' => '---', 'phone' => '123']);

        $results = app(ExternalSupplierEligibility::class)->review([
            $this->partner('Внешняя фирма'), $this->partner('...', phones: ['123']),
        ], [['name' => '---', 'phones' => ['123']]]);

        $this->assertSame(['supplier_not_registered', 'supplier_not_registered'], array_column($results, 'reason'));
    }

    public function test_discovery_metadata_remains_compatible_with_cli_supplier_review(): void
    {
        $partner = [...$this->partner('Внешняя фирма'), 'catalog_id' => 'catalog', 'published_at' => '11.11.2026 09:30'];

        $result = app(ExternalSupplierEligibility::class)->review([$partner], []);

        $this->assertSame('eligible', $result[0]['outcome']);
    }

    public function test_rejects_unsupported_sources(): void
    {
        $this->expectException(ValidationException::class);

        app(ExternalSupplierEligibility::class)->review([$this->partner('Фирма', 'unknown')], []);
    }

    public function test_requires_explicit_exclusion_list_before_reviewing_suppliers(): void
    {
        $this->artisan('external-prices:review', ['partners' => 'not-read.json'])
            ->expectsOutputToContain('Provide --exclude')->assertFailed();
    }

    public function test_command_reports_eligibility_without_creating_suppliers_or_profiles(): void
    {
        $partnersPath = tempnam(sys_get_temp_dir(), 'external-partners-');
        $excludedPath = tempnam(sys_get_temp_dir(), 'external-excluded-');
        file_put_contents($partnersPath, json_encode([$this->partner('Новая фирма')], JSON_THROW_ON_ERROR));
        file_put_contents($excludedPath, '[]');

        try {
            $this->artisan('external-prices:review', ['partners' => $partnersPath, '--exclude' => $excludedPath])
                ->expectsOutputToContain('supplier_not_registered')->assertSuccessful();

            $this->assertDatabaseCount('organizations', 0);
            $this->assertDatabaseCount('supplier_import_profiles', 0);
        } finally {
            unlink($partnersPath);
            unlink($excludedPath);
        }
    }

    public function test_command_rejects_a_json_object_instead_of_treating_it_as_an_empty_list(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'external-invalid-');
        file_put_contents($path, '{}');

        try {
            $this->artisan('external-prices:review', ['partners' => $path, '--exclude' => $path])
                ->expectsOutputToContain('Supplier input must contain a JSON array.')->assertFailed();
        } finally {
            unlink($path);
        }
    }

    /**
     * @param  list<string>  $phones
     * @return array{source: string, external_id: string, name: string, phones: list<string>}
     */
    private function partner(string $name, string $source = 'icorp', array $phones = []): array
    {
        return ['source' => $source, 'external_id' => 'external-'.$name, 'name' => $name, 'phones' => $phones];
    }
}
