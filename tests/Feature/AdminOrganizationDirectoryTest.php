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
