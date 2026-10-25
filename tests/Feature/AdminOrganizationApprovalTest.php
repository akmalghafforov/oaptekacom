<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminOrganizationApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['pending'])]
    #[TestWith(['blocked'])]
    public function test_incomplete_unverified_pharmacy_can_be_approved_without_changing_supplier(string $supplierStatus): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = Organization::factory()->pharmacy()->pending()->create(['setup_required' => true, 'city' => null, 'phone' => null]);
        $account = User::factory()->pharmacy($pharmacy)->create(['phone_verified_at' => null, 'subscription_plan' => SubscriptionPlan::Premium]);
        $supplier = Organization::factory()->wholesaler()->create([
            'name' => 'Связанный поставщик',
            'status' => $supplierStatus,
            'linked_pharmacy_organization_id' => $pharmacy->id,
        ]);
        $supplierAccount = User::factory()->wholesaler($supplier)->create();
        $supplierBefore = $supplier->fresh()->getAttributes();
        $supplierAccountBefore = $supplierAccount->fresh()->getAttributes();

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertSee('Данные можно исправить после одобрения.')
            ->assertSee(route('admin.approve', $pharmacy), false);
        $this->from(route('admin.index'))->post(route('admin.approve', $pharmacy))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('active', $pharmacy->fresh()->status);
        $this->assertFalse($pharmacy->fresh()->setup_required);
        $this->assertNotNull($account->fresh()->approved_at);
        $this->assertNull($account->fresh()->phone_verified_at);
        $this->assertSame(SubscriptionPlan::Free, $account->fresh()->subscription_plan);
        $this->assertSame($supplierBefore, $supplier->fresh()->getAttributes());
        $this->assertSame($supplierAccountBefore, $supplierAccount->fresh()->getAttributes());
        $this->assertDatabaseHas('audit_events', ['event' => 'organization.approved', 'subject_id' => $pharmacy->id]);

        $this->patch(route('admin.pharmacies.update', $pharmacy), ['name' => 'Исправленная аптека', 'status' => 'active', 'phone' => null, 'city' => null])
            ->assertRedirect(route('admin.pharmacies.show', $pharmacy))
            ->assertSessionHasNoErrors();
        $this->assertSame('Исправленная аптека', $pharmacy->fresh()->name);
    }

    public function test_verified_pharmacy_with_active_supplier_can_be_approved(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = Organization::factory()->pharmacy()->pending()->create();
        $account = User::factory()->pharmacy($pharmacy)->create(['phone_verified_at' => now()]);
        $phoneVerifiedAt = $account->fresh()->getRawOriginal('phone_verified_at');
        Organization::factory()->wholesaler()->create(['linked_pharmacy_organization_id' => $pharmacy->id]);

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertSee(route('admin.approve', $pharmacy), false);
        $this->from(route('admin.index'))->post(route('admin.approve', $pharmacy))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('success', 'Организация одобрена.');

        $this->assertSame('active', $pharmacy->fresh()->status);
        $this->assertNotNull($account->fresh()->approved_at);
        $this->assertSame($phoneVerifiedAt, $account->fresh()->getRawOriginal('phone_verified_at'));
        $this->assertDatabaseHas('audit_events', ['event' => 'organization.approved', 'subject_id' => $pharmacy->id]);
    }

    #[TestWith(['active', false, 'Организация больше не ожидает одобрения. Обновите страницу.'])]
    #[TestWith(['blocked', false, 'Организация больше не ожидает одобрения. Обновите страницу.'])]
    public function test_ineligible_organization_redirects_with_explanation(string $status, bool $setupRequired, string $message): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->pharmacy()->create(['status' => $status, 'setup_required' => $setupRequired]);
        $account = User::factory()->pharmacy($organization)->create(['phone_verified_at' => now()]);

        $this->actingAs($admin)->from(route('admin.index'))->post(route('admin.approve', $organization))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHasErrors(['organization' => $message]);

        $this->assertSame($status, $organization->fresh()->status);
        $this->assertNull($account->fresh()->approved_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_supplier_without_an_account_returns_a_422_json_validation_error(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->wholesaler()->pending()->create();

        $this->actingAs($admin)->postJson(route('admin.approve', $organization))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization')
            ->assertJsonPath('errors.organization.0', 'Для одобрения организации необходимо подтверждение телефона по SMS.');

        $this->assertSame('pending', $organization->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_pending_pharmacy_without_an_account_can_be_approved(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = Organization::factory()->pharmacy()->pending()->create(['setup_required' => true, 'phone' => null]);

        $this->actingAs($admin)->get(route('admin.index'))->assertSee(route('admin.approve', $pharmacy), false);
        $this->post(route('admin.approve', $pharmacy))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('active', $pharmacy->fresh()->status);
        $this->assertFalse($pharmacy->fresh()->setup_required);
        $this->assertSame(0, $pharmacy->assignedUsers()->count());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_events', ['event' => 'organization.approved', 'subject_id' => $pharmacy->id]);
    }

    #[TestWith([true, true])]
    #[TestWith([false, false])]
    public function test_supplier_approval_still_requires_profile_and_sms(bool $setupRequired, bool $verified): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $supplier = Organization::factory()->wholesaler()->pending()->create(['setup_required' => $setupRequired]);
        $account = User::factory()->wholesaler($supplier)->create(['phone_verified_at' => $verified ? now() : null]);

        $this->actingAs($admin)->get(route('admin.index'))->assertDontSee(route('admin.approve', $supplier), false);
        $this->postJson(route('admin.approve', $supplier))->assertUnprocessable()->assertJsonValidationErrors('organization');

        $this->assertSame('pending', $supplier->fresh()->status);
        $this->assertNull($account->fresh()->approved_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_non_admin_cannot_approve_a_pending_pharmacy(): void
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Organization::factory()->pharmacy()->pending()->create();

        $this->actingAs($user)->post(route('admin.approve', $pharmacy))->assertForbidden();

        $this->assertSame('pending', $pharmacy->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
