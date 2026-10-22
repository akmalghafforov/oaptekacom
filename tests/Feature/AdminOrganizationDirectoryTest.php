<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminOrganizationDirectoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_manages_supplier_default_discount_with_audit_and_omission_preservation(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $supplier = Organization::factory()->wholesaler()->create();
        $payload = ['name' => $supplier->name, 'status' => 'active', 'minimum_order' => '0'];

        foreach (['12.34', '100', '0', '15.00'] as $percent) {
            $this->actingAs($admin)->patch(route('admin.providers.update', $supplier), $payload + ['default_discount_percent' => $percent])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame(bcadd($percent, '0', 2), $supplier->fresh()->default_discount_percent);
        }
        $event = AuditEvent::query()->where('event', 'organization.updated_by_admin')->latest('id')->firstOrFail();
        $this->assertSame('0.00', $event->before['default_discount_percent']);
        $this->assertSame('15.00', $event->after['default_discount_percent']);
        $this->actingAs($admin)->patch(route('admin.providers.update', $supplier), $payload)->assertSessionHasNoErrors();
        $this->assertSame('15.00', $supplier->fresh()->default_discount_percent);
        $this->actingAs($admin)->get(route('admin.providers.edit', $supplier))->assertSee('Скидка поставщика по умолчанию, %');
        $this->actingAs($admin)->get(route('admin.providers.show', $supplier))->assertSee('15.00%');
        $this->actingAs($admin)->patch(route('admin.providers.update', $supplier), $payload + ['default_discount_percent' => ''])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $supplier->fresh()->default_discount_percent);
    }

    public function test_supplier_default_discount_rejects_invalid_values_and_pharmacy_updates(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $supplier = Organization::factory()->wholesaler()->create(['default_discount_percent' => '10.00']);
        $payload = ['name' => $supplier->name, 'status' => 'active', 'minimum_order' => '0'];
        foreach (['-0.01', '100.01', '1.234', 'invalid'] as $percent) {
            $this->actingAs($admin)->patch(route('admin.providers.update', $supplier), $payload + ['default_discount_percent' => $percent])->assertSessionHasErrors('default_discount_percent');
        }
        $this->assertSame('10.00', $supplier->fresh()->default_discount_percent);
        $pharmacy = Organization::factory()->pharmacy()->create();
        foreach (['10', ''] as $percent) {
            $this->actingAs($admin)->patch(route('admin.pharmacies.update', $pharmacy), ['name' => $pharmacy->name, 'status' => 'active', 'default_discount_percent' => $percent])->assertSessionHasErrors('default_discount_percent');
        }
        $user = User::factory()->wholesaler($supplier)->create();
        $this->actingAs($user)->patch(route('admin.providers.update', $supplier), $payload + ['default_discount_percent' => '50'])->assertForbidden();
        $this->assertSame('10.00', $supplier->fresh()->default_discount_percent);
    }

    public function test_users_page_links_to_pharmacy_and_provider_directories(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertSee('Аптеки')
            ->assertSee(route('admin.pharmacies.index'), false)
            ->assertSee('Поставщики')
            ->assertSee(route('admin.providers.index'), false);
    }

    public function test_non_admin_cannot_access_organization_directory_routes(): void
    {
        $organization = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($organization)->create();

        $this->actingAs($user)->get(route('admin.pharmacies.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.pharmacies.show', $organization))->assertForbidden();
        $this->actingAs($user)->get(route('admin.pharmacies.edit', $organization))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.pharmacies.update', $organization), [])->assertForbidden();
        $this->actingAs($user)->patch(route('admin.pharmacies.accounts.update', [$organization, $user]), [])->assertForbidden();
        $this->actingAs($user)->post(route('admin.pharmacies.archive', $organization))->assertForbidden();
    }

    public function test_directories_are_type_scoped_and_render_linked_accounts(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = Organization::factory()->pharmacy()->create(['name' => 'Аптека Север']);
        User::factory()->pharmacy($pharmacy)->create(['name' => 'Первый сотрудник']);
        User::factory()->pharmacy($pharmacy)->create(['name' => 'Второй сотрудник']);
        $provider = Organization::factory()->wholesaler()->create();

        $this->actingAs($admin)->get(route('admin.pharmacies.index'))
            ->assertSee('Аптека Север')
            ->assertSee('Первый сотрудник')
            ->assertSee('Второй сотрудник')
            ->assertDontSee($provider->name);
        $this->actingAs($admin)->get(route('admin.providers.show', $pharmacy))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.pharmacies.show', $provider))->assertNotFound();
    }

    public function test_directory_filters_sorts_and_preserves_query_string_in_pagination(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        Organization::factory()->count(21)->pharmacy()->create();
        $matched = Organization::factory()->pharmacy()->create(['name' => 'Нужная аптека']);
        User::factory()->pharmacy($matched)->create(['email' => 'directory@example.test']);

        $this->actingAs($admin)->get(route('admin.pharmacies.index', [
            'name' => 'Нужная',
            'email' => 'directory@example.test',
            'sort' => 'name',
            'direction' => 'asc',
        ]))->assertSee('Нужная аптека');

        $this->actingAs($admin)->get(route('admin.pharmacies.index', ['sort' => 'created_at', 'direction' => 'desc']))
            ->assertSee('page=2', false);
    }

    public function test_admin_can_update_organization_and_linked_account_with_normalized_phone(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->wholesaler()->create(['phone' => '+992900000001']);
        $account = User::factory()->wholesaler($organization)->create(['phone' => '+992900000002']);

        $this->actingAs($admin)->patch(route('admin.providers.update', $organization), [
            'name' => 'Обновлённый поставщик',
            'city' => 'Душанбе',
            'phone' => '900000003',
            'status' => 'active',
            'supplier_mode' => 'both',
            'minimum_order' => '250.50',
            'delivery_conditions' => 'Доставка на следующий день.',
        ])->assertRedirect(route('admin.providers.show', $organization));

        $this->actingAs($admin)->patch(route('admin.providers.accounts.update', [$organization, $account]), [
            'name' => 'Менеджер',
            'email' => 'manager@example.test',
            'phone' => '900000004',
            'is_blocked' => '0',
        ])->assertRedirect(route('admin.providers.edit', $organization));

        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => 'Обновлённый поставщик', 'phone' => '+992900000003', 'supplier_mode' => 'both']);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'name' => 'Менеджер', 'email' => 'manager@example.test', 'phone' => '+992900000004', 'is_blocked' => false]);
    }

    public function test_update_rejects_duplicate_account_email_and_role_scoped_phone(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->pharmacy()->create();
        $account = User::factory()->pharmacy($organization)->create();
        $other = User::factory()->pharmacy()->create(['email' => 'used@example.test', 'phone' => '+992900000005']);

        $this->actingAs($admin)->from(route('admin.pharmacies.edit', $organization))
            ->patch(route('admin.pharmacies.accounts.update', [$organization, $account]), [
                'name' => $account->name,
                'email' => $other->email,
                'phone' => '900000005',
                'is_blocked' => '0',
            ])->assertRedirect(route('admin.pharmacies.edit', $organization))
            ->assertSessionHasErrors(['email', 'phone']);
    }

    public function test_archive_blocks_organization_and_all_linked_accounts_while_retaining_history(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->pharmacy()->create();
        $first = User::factory()->pharmacy($organization)->create();
        $second = User::factory()->pharmacy($organization)->create(['is_blocked' => true]);

        $this->actingAs($admin)->post(route('admin.pharmacies.archive', $organization))
            ->assertRedirect(route('admin.pharmacies.index'));

        $this->assertModelExists($organization->fresh());
        $this->assertSame('blocked', $organization->fresh()->status);
        $this->assertTrue($first->fresh()->is_blocked);
        $this->assertTrue($second->fresh()->is_blocked);
        $this->assertDatabaseCount('audit_events', 3);
        $this->assertSame(1, AuditEvent::query()->where('event', 'organization.archived')->count());
    }
}
