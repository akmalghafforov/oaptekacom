<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnlimitedSubscriptionAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['account-modes.enabled' => true]);
    }

    public static function unlimitedModes(): array
    {
        return [
            'admin' => [UserRole::Admin, 'admin', 'dashboard'],
            'admin supplier' => [UserRole::Admin, 'supplier', 'dashboard'],
            'admin pharmacy' => [UserRole::Admin, 'pharmacy', 'catalog'],
            'supplier' => [UserRole::Wholesaler, 'supplier', 'dashboard'],
            'supplier pharmacy' => [UserRole::Wholesaler, 'pharmacy', 'catalog'],
        ];
    }

    #[DataProvider('unlimitedModes')]
    public function test_unlimited_access_ignores_expired_subscriptions_in_every_assigned_mode(UserRole $role, string $mode, string $landing): void
    {
        $this->freezeTime();
        $user = ($role === UserRole::Admin ? User::factory()->admin() : User::factory()->wholesaler())->create(['two_factor_confirmed_at' => now()]);
        $subscription = Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Expired, 'starts_on' => now()->subDays(20), 'ends_on' => now()->subDays(10)]);
        $original = $subscription->fresh()->getAttributes();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $this->patch(route('mode.update'), ['mode' => $mode, '_mode_version' => session('account_mode.version')])->assertRedirect();

        $this->get(route($landing))->assertOk();
        $this->get(route('subscription.create'))->assertSee('Безлимитный доступ')->assertDontSee('Оплата подписки')->assertDontSee('Подписка действует по')->assertDontSee('Требуется активная платная подписка')->assertDontSee('Выберите тариф');
        $this->assertSame($original, $subscription->fresh()->getAttributes());
        $this->assertSame(1, $user->subscriptions()->count());
    }

    #[DataProvider('unlimitedModes')]
    public function test_every_assigned_mode_opens_without_any_subscription(UserRole $role, string $mode, string $landing): void
    {
        $user = ($role === UserRole::Admin ? User::factory()->admin() : User::factory()->wholesaler())->create(['two_factor_confirmed_at' => now()]);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $this->patch(route('mode.update'), ['mode' => $mode, '_mode_version' => session('account_mode.version')])->assertRedirect();

        $this->get(route($landing))->assertOk()->assertSee('Безлимитный доступ');
        $this->assertSame(0, $user->subscriptions()->count());
    }

    public function test_pending_supplier_has_unlimited_entitlement_but_waits_for_approval_in_both_modes(): void
    {
        $supplier = Organization::factory()->wholesaler()->pending()->create();
        $user = User::factory()->wholesaler($supplier)->create();
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('subscription.create'));
        $this->get(route('subscription.create'))->assertSee('Безлимитный доступ')->assertSee('ожидает подтверждения')->assertDontSee('Оплата подписки');
        $this->patch(route('mode.update'), ['mode' => 'pharmacy', '_mode_version' => session('account_mode.version')])->assertRedirect();
        $this->get(route('catalog'))->assertRedirect(route('subscription.create'));

        $supplier->update(['status' => 'active']);

        $this->assertSame('active', $supplier->fresh()->linkedPharmacyOrganization->status);
        $this->get(route('catalog'))->assertOk();
        $this->assertSame(0, $user->subscriptions()->count());
    }

    public function test_blocked_admin_business_organizations_remain_blocked_after_reconciliation(): void
    {
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = $user->roleAssignments()->where('role', UserRole::Pharmacy)->first()->organization;
        $pharmacy->update(['status' => 'blocked', 'setup_required' => true]);
        $this->artisan('oapteka:backfill-account-roles')->expectsOutput('assignments=0, organizations=0, activated=0, errors=0')->assertSuccessful();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->patch(route('mode.update'), ['mode' => 'pharmacy', '_mode_version' => session('account_mode.version')])->assertRedirect();

        $this->get(route('catalog'))->assertRedirect(route('subscription.create'));
        $this->get(route('subscription.create'))->assertSee('Безлимитный доступ')->assertSee('организация заблокирована')->assertDontSee('Оплата подписки');
        $this->assertSame('blocked', $pharmacy->fresh()->status);
    }

    public function test_backfill_activates_existing_admin_and_shared_supplier_organizations_once(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Organization::factory()->wholesaler()->create();
        User::factory()->wholesaler($supplier)->count(2)->create();
        $admin->roleAssignments()->whereNotNull('organization_id')->get()->each(fn ($assignment) => $assignment->organization->update(['status' => 'pending', 'setup_required' => true]));
        $pharmacy = $supplier->fresh()->linkedPharmacyOrganization;
        $pharmacy->update(['status' => 'pending', 'setup_required' => true]);

        $this->artisan('oapteka:backfill-account-roles', ['--dry-run' => true, '--chunk' => 1])->expectsOutput('Dry run: assignments=0, organizations=0, activated=3, errors=0')->assertSuccessful();
        $this->assertSame(3, Organization::where('status', 'pending')->count());
        $this->artisan('oapteka:backfill-account-roles', ['--chunk' => 1])->expectsOutput('assignments=0, organizations=0, activated=3, errors=0')->assertSuccessful();
        $this->assertSame(0, Organization::where('status', 'pending')->count());
        $this->assertSame(0, Organization::where('setup_required', true)->count());
        $this->artisan('oapteka:backfill-account-roles')->expectsOutput('assignments=0, organizations=0, activated=0, errors=0')->assertSuccessful();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_backfill_counts_shared_admin_supplier_and_linked_pharmacy_activations_once(): void
    {
        $supplier = Organization::factory()->wholesaler()->pending()->create();
        User::factory()->wholesaler($supplier)->create();
        $admin = User::factory()->admin()->create();
        $admin->roleAssignments()->where('role', UserRole::Wholesaler)->update(['organization_id' => $supplier->id]);

        $this->artisan('oapteka:backfill-account-roles', ['--dry-run' => true])->expectsOutput('Dry run: assignments=0, organizations=0, activated=2, errors=0')->assertSuccessful();
        $this->artisan('oapteka:backfill-account-roles')->expectsOutput('assignments=0, organizations=0, activated=2, errors=0')->assertSuccessful();
        $this->assertSame('active', $supplier->fresh()->linkedPharmacyOrganization->status);
        $this->artisan('oapteka:backfill-account-roles')->expectsOutput('assignments=0, organizations=0, activated=0, errors=0')->assertSuccessful();
    }

    public function test_unapproved_and_blocked_suppliers_are_not_activated_by_backfill(): void
    {
        $supplier = Organization::factory()->wholesaler()->pending()->create();
        User::factory()->wholesaler($supplier)->create();
        $blockedSupplier = Organization::factory()->wholesaler()->create(['status' => 'blocked']);
        User::factory()->wholesaler($blockedSupplier)->create();

        $this->artisan('oapteka:backfill-account-roles')->expectsOutput('assignments=0, organizations=0, activated=0, errors=0')->assertSuccessful();

        $this->assertSame('pending', $supplier->fresh()->status);
        $this->assertSame('pending', $supplier->fresh()->linkedPharmacyOrganization->status);
        $this->assertSame('blocked', $blockedSupplier->fresh()->status);
        $this->assertSame('pending', $blockedSupplier->fresh()->linkedPharmacyOrganization->status);
    }

    public function test_ordinary_pharmacy_with_expired_subscription_still_requires_payment(): void
    {
        $this->freezeTime();
        $user = User::factory()->pharmacy()->create();
        Subscription::factory()->for($user)->create(['status' => SubscriptionStatus::Active, 'starts_on' => now()->subDays(20), 'ends_on' => now()->subDay()]);

        $this->actingAs($user)->get(route('catalog'))->assertRedirect(route('subscription.create'));
        $this->get(route('subscription.create'))->assertDontSee('Безлимитный доступ')->assertSee('Оплата подписки');
    }

    public function test_secondary_supplier_assignment_does_not_give_an_ordinary_pharmacy_unlimited_access(): void
    {
        $user = User::factory()->pharmacy()->create();
        $supplier = Organization::factory()->wholesaler()->create();
        $user->roleAssignments()->create(['role' => UserRole::Wholesaler, 'organization_id' => $supplier->id]);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $this->patch(route('mode.update'), ['mode' => 'supplier', '_mode_version' => session('account_mode.version')])->assertRedirect();

        $this->get(route('dashboard'))->assertRedirect(route('subscription.create'));
        $this->assertFalse($user->hasUnlimitedSubscriptionAccess());
    }

    public function test_account_blocking_overrides_unlimited_access(): void
    {
        $user = User::factory()->admin()->create(['is_blocked' => true, 'two_factor_confirmed_at' => now()]);

        $this->assertFalse($user->hasActiveSubscription());
        $this->actingAs($user)->get(route('catalog'))->assertForbidden();
    }
}
