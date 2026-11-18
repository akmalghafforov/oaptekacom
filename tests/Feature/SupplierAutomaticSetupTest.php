<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\SupplierSynchronization;
use App\Models\User;
use App\Services\ExternalPriceWorkbook;
use App\Services\SupplierAutomaticSetup;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupplierAutomaticSetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dry_run_reports_missing_profiles_without_writing(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $partner = ExternalSupplierPartner::factory()->for($supplier, 'supplier')->create();

        $this->artisan('suppliers:setup-automatic')->expectsOutputToContain('Dry run')->expectsOutputToContain('hourly_daily_versions')->assertSuccessful();

        $this->assertNull($partner->fresh()->profile_configuration);
        $this->assertDatabaseCount('supplier_synchronizations', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_apply_creates_strict_profiles_and_hourly_configuration_and_is_repeatable(): void
    {
        config(['price-list-imports.daily_versions' => true]);
        $supplier = Organization::factory()->wholesaler()->create();
        $partner = ExternalSupplierPartner::factory()->for($supplier, 'supplier')->create();

        $this->artisan('suppliers:setup-automatic', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Prices', $partner->fresh()->profile_configuration['worksheet']);
        $this->assertSame(['minimum_valid_rows' => 1, 'maximum_error_rows' => 0, 'maximum_error_percentage' => 0, 'fatal_warning_codes' => []], $partner->fresh()->profile_configuration['automatic']);
        $this->assertTrue($partner->fresh()->profile_active);
        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail();
        $this->assertSame(60, $configuration->fetch_interval_minutes);
        $this->assertSame('daily_versions', $configuration->selection_mode);
        $this->assertTrue($configuration->automatic_enabled);
        $this->assertTrue($configuration->settings_confirmed);
        $version = $configuration->version;
        $profileVersion = $partner->fresh()->profile_version;
        $audits = AuditEvent::count();

        $this->artisan('suppliers:setup-automatic', ['--apply' => true])->assertSuccessful();

        $this->assertSame($version, $configuration->fresh()->version);
        $this->assertSame($profileVersion, $partner->fresh()->profile_version);
        $this->assertSame($audits, AuditEvent::count());
        $this->assertSame($supplier->id, $partner->fresh()->supplier_organization_id);
    }

    public function test_dry_run_reports_proposed_setup_without_writes(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $partner = ExternalSupplierPartner::factory()->for($supplier, 'supplier')->create();
        $audits = AuditEvent::count();

        $this->artisan('suppliers:setup-automatic')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertNull($partner->fresh()->profile_configuration);
        $this->assertDatabaseCount('supplier_synchronizations', 0);
        $this->assertSame($audits, AuditEvent::count());
    }

    #[TestWith(['active', true, 'Заблокировано: нет подтверждённого сопоставления.'])]
    #[TestWith(['pending', true, 'Заблокировано: поставщик ещё не одобрен или неактивен.'])]
    #[TestWith(['active', false, 'Исключён из внешней загрузки: регулярная почтовая доставка.'])]
    public function test_admin_supplier_page_explains_blocked_or_excluded_state(string $status, bool $externalEnabled, string $message): void
    {
        config(['price-list-imports.daily_versions' => true]);
        $supplier = Organization::factory()->wholesaler()->create(['status' => $status, 'external_fetch_enabled' => $externalEnabled, 'external_fetch_exclusion_reason' => $externalEnabled ? null : 'regular_email']);
        $admin = User::factory()->create(['role' => 'admin', 'organization_id' => null]);

        $this->actingAs($admin)->get(route('admin.supplier-sync.show', $supplier))->assertSee($message);
    }

    public function test_admin_page_displays_global_dispatch_pause(): void
    {
        config(['price-list-imports.daily_versions' => true]);
        Cache::forever('supplier-imports:dispatch-paused', true);
        $supplier = Organization::factory()->wholesaler()->create();
        $admin = User::factory()->create(['role' => 'admin', 'organization_id' => null]);

        $this->actingAs($admin)->get(route('admin.supplier-sync.show', $supplier))
            ->assertSee('Автоматические запуски приостановлены до завершения проверки источников.');
    }

    public function test_preserves_custom_profiles_and_blocks_inactive_profiles(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $custom = ExternalPriceWorkbook::profile(['decimal_separator' => ',']);
        $partner = ExternalSupplierPartner::factory()->for($supplier, 'supplier')->create(['profile_configuration' => $custom, 'profile_active' => false]);

        $result = app(SupplierAutomaticSetup::class)->configure($supplier, true);

        $this->assertSame(['status' => 'blocked', 'reason' => 'missing_profile'], $result);
        $this->assertSame($custom, $partner->fresh()->profile_configuration);
        $this->assertFalse($partner->fresh()->profile_active);
        $this->assertDatabaseCount('supplier_synchronizations', 0);
    }

    public function test_keeps_active_custom_profile_while_enabling_hourly_updates(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $custom = ExternalPriceWorkbook::profile(['decimal_separator' => ',']);
        $partner = ExternalSupplierPartner::factory()->for($supplier, 'supplier')->create(['profile_configuration' => $custom, 'profile_active' => true, 'profile_version' => 7]);
        $profileVersion = $partner->profile_version;

        $result = app(SupplierAutomaticSetup::class)->configure($supplier, true);

        $this->assertSame('enabled', $result['status']);
        $this->assertSame($custom, $partner->fresh()->profile_configuration);
        $this->assertSame($profileVersion, $partner->fresh()->profile_version);
        $this->assertDatabaseHas('supplier_synchronizations', ['supplier_organization_id' => $supplier->id, 'fetch_interval_minutes' => 60, 'automatic_enabled' => true]);
    }

    public function test_rollout_preserves_explicit_administrator_pause_and_disable(): void
    {
        $disabled = SupplierSynchronization::factory()->create(['settings_confirmed' => true, 'automatic_enabled' => false]);
        $paused = SupplierSynchronization::factory()->create(['paused' => true, 'automatic_enabled' => true]);
        ExternalSupplierPartner::factory()->for($disabled->supplier, 'supplier')->create();
        ExternalSupplierPartner::factory()->for($paused->supplier, 'supplier')->create();

        $this->artisan('suppliers:rollout-versions', ['--prepare' => true])->assertSuccessful();

        $this->assertFalse($disabled->fresh()->automatic_enabled);
        $this->assertTrue($paused->fresh()->paused);
        $this->assertDatabaseMissing('external_supplier_partners', ['profile_active' => true]);
    }

    public function test_preserves_administrator_disables_and_exclusions(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $partner = ExternalSupplierPartner::factory()->for($supplier, 'supplier')->create();
        $configuration = SupplierSynchronization::factory()->for($supplier, 'supplier')->create(['settings_confirmed' => true, 'automatic_enabled' => false]);

        $result = app(SupplierAutomaticSetup::class)->configure($supplier, true);

        $this->assertSame('administrator_disabled', $result['reason']);
        $this->assertFalse($configuration->fresh()->automatic_enabled);
        $this->assertNull($partner->fresh()->profile_configuration);

        $supplier->update(['external_fetch_enabled' => false, 'external_fetch_overridden' => true, 'external_fetch_exclusion_reason' => 'Keep email']);
        $result = app(SupplierAutomaticSetup::class)->configure($supplier, true);

        $this->assertSame(['status' => 'excluded', 'reason' => 'Keep email'], $result);
        $this->assertTrue($supplier->fresh()->external_fetch_overridden);
        $this->assertFalse($supplier->fresh()->external_fetch_enabled);
    }

    public function test_blocks_pending_unmapped_and_paused_suppliers_without_changing_approval(): void
    {
        $pending = Organization::factory()->wholesaler()->create(['status' => 'pending']);
        ExternalSupplierPartner::factory()->for($pending, 'supplier')->create();
        $unmapped = Organization::factory()->wholesaler()->create();
        $paused = SupplierSynchronization::factory()->create(['paused' => true]);

        $setup = app(SupplierAutomaticSetup::class);
        $this->assertSame('supplier_not_active', $setup->configure($pending, true)['reason']);
        $this->assertSame('unmapped', $setup->configure($unmapped, true)['reason']);
        $this->assertSame('administrator_paused', $setup->configure($paused->supplier, true)['reason']);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('external_supplier_partners', 1);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_apply_requires_prepared_daily_workflow(): void
    {
        $this->artisan('suppliers:setup-automatic', ['--apply' => true])->assertFailed();
        $this->assertDatabaseCount('supplier_synchronizations', 0);
    }
}
