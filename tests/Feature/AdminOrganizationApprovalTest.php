<?php

namespace Tests\Feature;

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
    public function test_linked_pharmacy_waits_for_supplier_approval(string $supplierStatus): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = Organization::factory()->pharmacy()->pending()->create();
        $account = User::factory()->pharmacy($pharmacy)->create(['phone_verified_at' => now()]);
        $supplier = Organization::factory()->wholesaler()->create([
            'name' => 'Связанный поставщик',
            'status' => $supplierStatus,
            'linked_pharmacy_organization_id' => $pharmacy->id,
        ]);
        $message = 'Сначала одобрите связанного поставщика: Связанный поставщик.';

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertSee($message)
            ->assertDontSee(route('admin.approve', $pharmacy), false);
        $this->from(route('admin.index'))->post(route('admin.approve', $pharmacy))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHasErrors(['organization' => $message]);

        $this->assertSame('pending', $pharmacy->fresh()->status);
        $this->assertSame($supplierStatus, $supplier->fresh()->status);
        $this->assertNull($account->fresh()->approved_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_verified_pharmacy_with_active_supplier_can_be_approved(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = Organization::factory()->pharmacy()->pending()->create();
        $account = User::factory()->pharmacy($pharmacy)->create(['phone_verified_at' => now()]);
        Organization::factory()->wholesaler()->create(['linked_pharmacy_organization_id' => $pharmacy->id]);

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertSee(route('admin.approve', $pharmacy), false);
        $this->from(route('admin.index'))->post(route('admin.approve', $pharmacy))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('success', 'Организация одобрена.');

        $this->assertSame('active', $pharmacy->fresh()->status);
        $this->assertNotNull($account->fresh()->approved_at);
        $this->assertDatabaseHas('audit_events', ['event' => 'organization.approved', 'subject_id' => $pharmacy->id]);
    }

    #[TestWith(['active', false, 'Организация больше не ожидает одобрения. Обновите страницу.'])]
    #[TestWith(['blocked', false, 'Организация больше не ожидает одобрения. Обновите страницу.'])]
    #[TestWith(['pending', true, 'Владелец должен сохранить данные организации в профиле рабочего режима.'])]
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

    public function test_organization_without_an_account_returns_a_422_json_validation_error(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->pending()->create();

        $this->actingAs($admin)->postJson(route('admin.approve', $organization))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization')
            ->assertJsonPath('errors.organization.0', 'Для одобрения организации необходимо подтверждение телефона по SMS.');

        $this->assertSame('pending', $organization->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
