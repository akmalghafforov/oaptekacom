<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\PreparePriceListImport;
use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\PriceList\ProfileValidator;
use App\Services\RoleProvisioningService;
use App\Services\SupplierCartExport;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountModeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['account-modes.enabled' => true]);
    }

    private function version(): string
    {
        return session('account_mode.version');
    }

    private function switchMode(string $mode): TestResponse
    {
        return $this->patch(route('mode.update'), ['mode' => $mode, '_mode_version' => $this->version()]);
    }

    public function test_admin_roles_are_active_and_switches_only_change_the_session(): void
    {
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $this->assertSame(3, $user->roleAssignments()->count());
        $this->assertSame(2, $user->roleAssignments()->whereHas('organization', fn ($query) => $query->where('status', 'active')->where('setup_required', false))->count());
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Рабочий режим');
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $this->assertSame('supplier', session('account_mode.mode'));
        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->assertNull($user->fresh()->organization_id);
        $this->get(route('admin.users'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('profile.edit'))->assertOk();
        $this->switchMode('admin')->assertRedirect(route('dashboard'));
        $this->get(route('admin.users'))->assertOk();
        $this->assertSame(2, AuditEvent::where('event', 'account.mode_switched')->count());
    }

    public function test_supplier_accounts_share_one_pharmacy_and_provisioning_is_idempotent(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $first = User::factory()->wholesaler($supplier)->create();
        $second = User::factory()->wholesaler($supplier)->create();
        app(RoleProvisioningService::class)->provision($first);
        app(RoleProvisioningService::class)->provision($second);
        $this->assertSame(2, Organization::count());
        $this->assertSame(4, UserRoleAssignment::count());
        $pharmacyId = $first->roleAssignments()->where('role', UserRole::Pharmacy)->value('organization_id');
        $this->assertSame($pharmacyId, $second->roleAssignments()->where('role', UserRole::Pharmacy)->value('organization_id'));
        $this->assertSame($pharmacyId, $supplier->fresh()->linked_pharmacy_organization_id);
        $this->actingAs($first)->get(route('dashboard'))->assertOk();
        $this->get(route('partners.index'))->assertForbidden();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->get(route('partners.index'))->assertOk();
        $this->get(route('price-list-imports.index'))->assertForbidden();
        $this->get(route('catalog'))->assertOk();
        $this->switchMode('admin')->assertForbidden();
        $this->assertSame('pharmacy', session('account_mode.mode'));
    }

    public function test_pharmacy_only_account_cannot_select_other_modes(): void
    {
        $user = User::factory()->pharmacy()->create();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertDontSee('data-mode-switcher', false);
        $this->assertSame('pharmacy', session('account_mode.mode'));
        $this->switchMode('supplier')->assertForbidden();
        $this->switchMode('admin')->assertForbidden();
        $this->switchMode('invalid')->assertSessionHasErrors('mode');
        $this->assertSame(1, $user->roleAssignments()->count());
    }

    public function test_stale_forms_are_rejected_even_after_switching_back(): void
    {
        $user = User::factory()->wholesaler()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $original = $this->version();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->patch(route('profile.organization.update'), ['name' => 'Stale', '_mode_version' => $original])->assertStatus(409);
        $this->patchJson(route('mode.update'), ['mode' => 'supplier', '_mode_version' => $original])->assertStatus(409);
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $this->assertNotSame($original, $this->version());
        $this->patch(route('profile.organization.update'), ['name' => 'Stale', '_mode_version' => $original])->assertStatus(409);
        $this->patch(route('profile.organization.update'), ['name' => 'Fresh', '_mode_version' => $this->version()])->assertRedirect();
        $this->assertSame('Fresh', $user->organization->fresh()->name);
    }

    public function test_same_mode_does_not_rotate_the_version_or_emit_an_audit(): void
    {
        $user = User::factory()->wholesaler()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $original = $this->version();
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $this->assertSame($original, $this->version());
        $this->assertDatabaseMissing('audit_events', ['event' => 'account.mode_switched']);
    }

    public function test_invalid_or_removed_selection_falls_back_to_the_primary_mode(): void
    {
        $user = User::factory()->wholesaler()->create();
        $this->actingAs($user)->withSession(['account_mode' => ['user_id' => $user->id, 'mode' => 'admin', 'version' => 'old']])->get(route('dashboard'))->assertOk();
        $this->assertSame('supplier', session('account_mode.mode'));
        $this->assertNotSame('old', $this->version());
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $user->roleAssignments()->where('role', UserRole::Pharmacy)->delete();
        $this->get(route('partners.index'))->assertForbidden();
        $this->assertSame('supplier', session('account_mode.mode'));
    }

    public function test_organization_updates_and_discounts_use_the_selected_pharmacy(): void
    {
        $user = User::factory()->wholesaler()->create();
        $supplier = Organization::factory()->wholesaler()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $pharmacyId = $user->roleAssignments()->where('role', UserRole::Pharmacy)->value('organization_id');
        $originalName = $user->organization->name;
        $this->patch(route('profile.organization.update'), ['name' => 'Selected pharmacy', 'organization_id' => $supplier->id, '_mode_version' => $this->version()])->assertRedirect();
        $this->assertDatabaseHas('organizations', ['id' => $pharmacyId, 'name' => 'Selected pharmacy']);
        $this->assertSame($originalName, $user->organization->fresh()->name);
        $this->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => '12.50', '_mode_version' => $this->version()])->assertRedirect();
        $this->assertDatabaseHas('pharmacy_supplier_discounts', ['pharmacy_organization_id' => $pharmacyId, 'supplier_organization_id' => $supplier->id, 'supplier_discount_percent' => '12.50']);
    }

    public function test_admin_in_business_mode_cannot_view_foreign_orders_or_imports(): void
    {
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = $user->roleAssignments()->where('role', UserRole::Wholesaler)->first()->organization;
        $organization->update(['setup_required' => false, 'status' => 'active']);
        $order = Order::create(['buyer_organization_id' => Organization::factory()->pharmacy()->create()->id, 'supplier_organization_id' => Organization::factory()->wholesaler()->create()->id]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $this->get(route('orders.show', $order))->assertForbidden();
        $this->get(route('orders.index'))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
        $this->assertFalse($user->can('update', $order));
        $this->get(route('admin.providers.index'))->assertForbidden();
    }

    public function test_historical_outgoing_orders_remain_visible_in_pharmacy_mode(): void
    {
        $user = User::factory()->wholesaler()->create();
        $otherSupplier = Organization::factory()->wholesaler()->create();
        $historical = Order::create(['buyer_organization_id' => $user->organization_id, 'supplier_organization_id' => $otherSupplier->id]);
        $incoming = Order::create(['buyer_organization_id' => Organization::factory()->pharmacy()->create()->id, 'supplier_organization_id' => $user->organization_id]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->get(route('orders.show', $historical))->assertForbidden();
        $this->get(route('orders.show', $incoming))->assertOk();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->get(route('orders.show', $historical))->assertOk();
        $this->get(route('orders.show', $incoming))->assertForbidden();
    }

    public function test_generated_admin_organizations_do_not_require_setup_or_approval(): void
    {
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $supplier = $user->roleAssignments()->where('role', UserRole::Wholesaler)->first()->organization;
        $this->assertFalse($supplier->setup_required);
        $this->assertSame('active', $supplier->status);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $this->assertNull($supplier->importProfile);
    }

    public function test_supplier_approval_activates_derived_pharmacy_but_never_unblocks_it(): void
    {
        $supplier = Organization::factory()->wholesaler()->pending()->create();
        $user = User::factory()->wholesaler($supplier)->create(['phone_verified_at' => now()]);
        $pharmacy = $supplier->fresh()->linkedPharmacyOrganization;
        $this->assertSame('pending', $pharmacy->status);
        $pharmacy->update(['setup_required' => true]);
        $supplier->update(['status' => 'active']);
        $this->assertSame('active', $pharmacy->fresh()->status);
        $this->assertFalse($pharmacy->fresh()->setup_required);
        $pharmacy->update(['status' => 'blocked']);
        $supplier->update(['status' => 'pending']);
        $supplier->update(['status' => 'active']);
        $this->artisan('oapteka:backfill-account-roles')->assertSuccessful();
        $this->assertSame('blocked', $pharmacy->fresh()->status);
    }

    public function test_secondary_archive_does_not_block_admin_identity(): void
    {
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = $user->roleAssignments()->where('role', UserRole::Pharmacy)->first()->organization;
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->post(route('admin.pharmacies.archive', $pharmacy), ['_mode_version' => $this->version()])->assertRedirect();
        $this->assertFalse($user->fresh()->is_blocked);
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->get(route('catalog'))->assertRedirect(route('subscription.create'));
        $this->switchMode('admin')->assertRedirect(route('dashboard'));
    }

    public function test_admin_two_factor_and_account_blocking_apply_across_modes(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('two-factor.enroll'));
        $this->patch(route('mode.update'), ['mode' => 'pharmacy', '_mode_version' => $this->version()])->assertRedirect(route('two-factor.enroll'));
        $user->forceFill(['two_factor_confirmed_at' => now(), 'is_blocked' => true])->save();
        $this->get(route('profile.edit'))->assertForbidden();
    }

    public function test_unconfirmed_admin_can_switch_modes_when_two_factor_is_disabled(): void
    {
        config(['auth.admin_two_factor_enabled' => false]);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('admin.index'))->assertOk();
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->get(route('catalog'))->assertOk();
        $this->switchMode('admin')->assertRedirect(route('dashboard'));
        $this->get(route('admin.index'))->assertOk();
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_backfill_dry_run_and_repeated_execution_are_safe(): void
    {
        $user = User::factory()->wholesaler()->create();
        $user->roleAssignments()->delete();
        $this->artisan('oapteka:backfill-account-roles', ['--dry-run' => true])->expectsOutput('Dry run: assignments=2, organizations=0, activated=0, errors=0')->assertSuccessful();
        $this->assertSame(0, $user->roleAssignments()->count());
        $this->artisan('oapteka:backfill-account-roles')->expectsOutput('assignments=2, organizations=0, activated=0, errors=0')->assertSuccessful();
        $this->artisan('oapteka:backfill-account-roles')->expectsOutput('assignments=0, organizations=0, activated=0, errors=0')->assertSuccessful();
    }

    public function test_invalid_primary_accounts_are_reported_without_partial_assignments(): void
    {
        $user = User::factory()->create();
        $this->artisan('oapteka:backfill-account-roles')->expectsOutput("User {$user->id}: missing or mismatched primary organization.")->expectsOutput('assignments=0, organizations=0, activated=0, errors=1')->assertFailed();
        $this->assertDatabaseCount('user_role_assignments', 0);
    }

    public function test_failed_primary_change_rolls_back_the_user(): void
    {
        $user = User::factory()->wholesaler()->create();
        $original = $user->organization_id;
        try {
            $user->forceFill(['organization_id' => Organization::factory()->pharmacy()->create()->id])->save();
            $this->fail('A mismatched organization must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('mismatched primary organization', $exception->getMessage());
        }
        $this->assertSame($original, $user->fresh()->organization_id);
    }

    public function test_switching_requires_authentication_and_can_be_disabled(): void
    {
        $this->patch(route('mode.update'), ['mode' => 'admin'])->assertRedirect(route('login'));
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        config(['account-modes.enabled' => false]);
        $this->actingAs($user)->patch(route('mode.update'), ['mode' => 'pharmacy'])->assertForbidden();
    }

    public function test_new_purchases_and_exports_use_the_linked_pharmacy_and_preserve_the_cart(): void
    {
        config(['orders.placement_enabled' => true]);
        $user = User::factory()->wholesaler()->create();
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => 'completed']);
        $supplier->update(['active_price_list_import_id' => $import->id]);
        $offer = Offer::factory()->create(['organization_id' => $supplier->id, 'price_list_import_id' => $import->id, 'is_active' => true, 'price' => '10.00', 'quantity' => 100]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $pharmacy = $user->roleAssignments()->where('role', UserRole::Pharmacy)->first()->organization;
        $pharmacy->update(['name' => 'Selected buyer']);
        $this->withHeader('X-Mode-Version', $this->version())->postJson(route('cart.add', $offer), ['quantity' => 2])->assertOk();
        $this->flushHeaders();
        $cartId = Cart::where('user_id', $user->id)->value('id');
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $this->get(route('cart'))->assertForbidden();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->assertSame($cartId, Cart::where('user_id', $user->id)->value('id'));
        $this->get(route('cart.suppliers.excel', $supplier))->assertOk();
        $this->assertSame('Selected buyer', app(SupplierCartExport::class)->data($user, $supplier)['buyer']);
        $this->post(route('cart.suppliers.checkout', $supplier), ['_mode_version' => $this->version()])->assertRedirect(route('orders.index'));
        $this->assertDatabaseHas('orders', ['buyer_organization_id' => $pharmacy->id, 'supplier_organization_id' => $supplier->id, 'total' => '20.00']);
        $this->assertDatabaseHas('checkouts', ['buyer_organization_id' => $pharmacy->id, 'user_id' => $user->id]);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_import_submission_uses_selected_supplier_and_keeps_queued_context_after_switch(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $supplier = $user->roleAssignments()->where('role', UserRole::Wholesaler)->first()->organization;
        $supplier->update(['setup_required' => false, 'status' => 'active']);
        $foreign = Organization::factory()->wholesaler()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $upload = UploadedFile::fake()->createWithContent('prices.csv', "name,price\nАспирин,10.00\n");
        $this->post(route('price-list-imports.store'), ['file' => $upload, 'supplier_organization_id' => $foreign->id, '_mode_version' => $this->version()])->assertSessionHasErrors('file');
        SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => ProfileValidator::defaults()]);
        $this->post(route('price-list-imports.store'), ['file' => $upload, 'supplier_organization_id' => $foreign->id, '_mode_version' => $this->version()])->assertRedirect();
        $import = PriceListImport::sole();
        $this->assertSame($supplier->id, $import->supplier_organization_id);
        $this->assertSame($user->id, $import->initiated_by);
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->assertSame($supplier->id, $import->fresh()->supplier_organization_id);
        Queue::assertPushed(PreparePriceListImport::class);
    }

    public function test_new_login_resets_mode_and_discards_incompatible_intended_destinations(): void
    {
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now(), 'password' => 'secret-password']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->post(route('logout'))->assertRedirect();
        $this->withSession(['url.intended' => route('cart')])->post(route('admin.login.authenticate'), ['email' => $user->email, 'password' => 'secret-password'])->assertRedirect(route('two-factor.enroll'));
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame('admin', session('account_mode.mode'));
        $this->post(route('logout'))->assertRedirect();
        $this->app->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->withSession(['url.intended' => route('cart')])->post(route('admin.login.authenticate'), ['email' => $user->email, 'password' => 'secret-password'])->assertRedirect(route('dashboard'));
    }

    public function test_organization_names_are_escaped_and_switch_forms_have_csrf_and_generation_tokens(): void
    {
        $user = User::factory()->wholesaler()->create();
        $user->organization->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('name="_mode_version"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PATCH"', false);
    }

    public function test_pending_or_blocked_selected_organizations_never_inherit_subscription_exemptions(): void
    {
        $user = User::factory()->wholesaler()->create();
        $pharmacy = $user->roleAssignments()->where('role', UserRole::Pharmacy)->first()->organization;
        $pharmacy->update(['status' => 'pending']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
        $this->get(route('catalog'))->assertRedirect(route('subscription.create'));
        $pharmacy->update(['status' => 'blocked']);
        $this->get(route('partners.index'))->assertRedirect(route('subscription.create'));
        $this->get(route('profile.edit'))->assertOk();
        $this->switchMode('supplier')->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $user->organization->update(['status' => 'blocked']);
        $this->get(route('profile.edit'))->assertForbidden();
    }

    public function test_primary_organization_changes_and_promotions_provision_atomically(): void
    {
        $user = User::factory()->wholesaler()->create();
        $replacement = Organization::factory()->wholesaler()->create();
        $user->forceFill(['organization_id' => $replacement->id])->save();
        $this->assertSame($replacement->id, $user->roleAssignments()->where('role', UserRole::Wholesaler)->value('organization_id'));
        $user->forceFill(['role' => UserRole::Admin, 'organization_id' => null])->save();
        $this->assertSame(3, $user->roleAssignments()->count());
        $this->assertSame($replacement->id, $user->roleAssignments()->where('role', UserRole::Wholesaler)->value('organization_id'));
    }

    public function test_session_lock_contention_prevents_mode_updates_until_the_lock_is_released(): void
    {
        config(['session.block' => true, 'session.block_store' => 'database', 'session.block_wait_seconds' => 0]);
        $user = User::factory()->wholesaler()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());
        $lock = Cache::store('database')->lock('session:'.session()->getId(), 300);
        $this->assertTrue($lock->get());
        $this->withoutExceptionHandling();
        try {
            $this->switchMode('pharmacy');
            $this->fail('The session lock must prevent concurrent updates.');
        } catch (LockTimeoutException $exception) {
            $this->assertSame('supplier', session('account_mode.mode'));
        } finally {
            $lock->release();
            $this->withExceptionHandling();
        }
        $this->switchMode('pharmacy')->assertRedirect(route('catalog'));
    }
}
