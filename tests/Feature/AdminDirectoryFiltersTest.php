<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminDirectoryFiltersTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['pharmacy', 'admin.pharmacies'])]
    #[TestWith(['wholesaler', 'admin.providers'])]
    public function test_searches_match_organization_and_linked_account_contacts(string $type, string $prefix): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->{$type}()->create([
            'name' => 'Directory target', 'city' => 'Dushanbe', 'phone' => '+992900123456',
            'contact_email' => 'contact@directory.test', 'created_at' => '2026-08-10 12:00:00',
        ]);
        User::factory()->{$type}($organization)->create(['name' => 'Linked manager', 'email' => 'manager@directory.test', 'phone' => '+992900654321']);
        Organization::factory()->{$type}()->create(['name' => 'Unrelated organization']);
        Organization::factory()->create(['type' => $type === 'pharmacy' ? 'wholesaler' : 'pharmacy', 'name' => 'Directory target']);
        $this->actingAs($admin);

        foreach ([['name' => 'target'], ['name' => 'Linked'], ['email' => 'contact@'], ['email' => 'manager@'], ['phone' => '123456'], ['phone' => '654321'], ['city' => 'shanbe'], ['created_from' => '2026-08-10', 'created_to' => '2026-08-10'], [
            'name' => 'Linked', 'email' => 'contact@', 'phone' => '654321', 'city' => 'shanbe', 'status' => 'active', 'created_from' => '2026-08-10', 'created_to' => '2026-08-10',
        ]] as $filters) {
            $this->get(route($prefix.'.index', $filters))->assertViewHas('organizations', fn ($rows) => $rows->modelKeys() === [$organization->id]);
        }

        $this->get(route($prefix.'.index', ['name' => 'No such organization']))
            ->assertViewHas('organizations', fn ($rows) => $rows->isEmpty())
            ->assertSee('Измените параметры поиска или сбросьте фильтры.');
    }

    #[TestWith(['pharmacy', 'admin.pharmacies'])]
    #[TestWith(['wholesaler', 'admin.providers'])]
    public function test_status_and_inclusive_date_boundaries_filter_organizations(string $type, string $prefix): void
    {
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));
        $first = Organization::factory()->{$type}()->pending()->create(['created_at' => '2026-08-10 00:00:00']);
        $last = Organization::factory()->{$type}()->blocked()->create(['created_at' => '2026-08-11 23:59:59']);
        Organization::factory()->{$type}()->create(['created_at' => '2026-08-09 23:59:59']);
        Organization::factory()->{$type}()->create(['created_at' => '2026-08-12 00:00:00']);

        $this->get(route($prefix.'.index', ['created_from' => '2026-08-10', 'created_to' => '2026-08-11']))
            ->assertViewHas('organizations', fn ($rows) => $rows->modelKeys() === [$last->id, $first->id]);
        $this->get(route($prefix.'.index', ['status' => 'pending']))
            ->assertViewHas('organizations', fn ($rows) => $rows->modelKeys() === [$first->id]);
        $this->get(route($prefix.'.index', ['status' => 'blocked']))
            ->assertViewHas('organizations', fn ($rows) => $rows->modelKeys() === [$last->id]);
        $this->get(route($prefix.'.index', ['created_from' => '2026-08-11', 'created_to' => '2026-08-12']))
            ->assertViewHas('organizations', fn ($rows) => $rows->total() === 2);
        $this->get(route($prefix.'.index', ['created_to' => '2026-08-10']))
            ->assertViewHas('organizations', fn ($rows) => $rows->total() === 2);
    }

    #[TestWith(['pharmacy', 'admin.pharmacies'])]
    #[TestWith(['wholesaler', 'admin.providers'])]
    public function test_all_sorts_work_in_both_directions_with_id_ties_and_newest_default(string $type, string $prefix): void
    {
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));
        $first = Organization::factory()->{$type}()->create(['city' => 'Sort city', 'name' => 'Zulu', 'status' => 'pending', 'created_at' => '2026-01-01', 'updated_at' => '2026-10-01']);
        $second = Organization::factory()->{$type}()->create(['city' => 'Sort city', 'name' => 'Alpha', 'status' => 'active', 'created_at' => '2026-02-01', 'updated_at' => '2026-09-01']);
        $third = Organization::factory()->{$type}()->create(['city' => 'Sort city', 'name' => 'Alpha', 'status' => 'active', 'created_at' => '2026-02-01', 'updated_at' => '2026-09-01']);

        foreach (['name', 'status', 'updated_at'] as $sort) {
            $this->get(route($prefix.'.index', ['city' => 'Sort city', 'sort' => $sort, 'direction' => 'asc']))
                ->assertViewHas('organizations', fn ($rows) => $rows->modelKeys() === [$third->id, $second->id, $first->id])
                ->assertSeeHtml('aria-sort="ascending"');
            $this->get(route($prefix.'.index', ['city' => 'Sort city', 'sort' => $sort, 'direction' => 'desc']))
                ->assertViewHas('organizations', fn ($rows) => $rows->modelKeys() === [$first->id, $third->id, $second->id]);
        }
        $this->get(route($prefix.'.index', ['city' => 'Sort city', 'sort' => 'created_at', 'direction' => 'asc']))
            ->assertViewHas('organizations', fn ($rows) => $rows->modelKeys() === [$first->id, $third->id, $second->id]);
        foreach ([[], ['sort' => 'created_at', 'direction' => 'desc']] as $filters) {
            $this->get(route($prefix.'.index', $filters + ['city' => 'Sort city']))
                ->assertViewHas('organizations', fn ($rows) => $rows->modelKeys() === [$third->id, $second->id, $first->id]);
        }
    }

    #[TestWith(['pharmacy', 'admin.pharmacies'])]
    #[TestWith(['wholesaler', 'admin.providers'])]
    public function test_pagination_keeps_every_filter_and_sort_and_reset_clears_controls(string $type, string $prefix): void
    {
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));
        Organization::factory()->count(21)->{$type}()->create([
            'name' => 'Matching organization', 'contact_email' => 'contact@example.test', 'phone' => '+992900123456', 'city' => 'Dushanbe', 'created_at' => '2026-08-10',
        ]);
        $filters = ['name' => 'Matching', 'email' => 'contact', 'phone' => '123456', 'city' => 'Dushanbe', 'status' => 'active', 'created_from' => '2026-08-10', 'created_to' => '2026-08-10', 'sort' => 'updated_at', 'direction' => 'asc'];

        $response = $this->get(route($prefix.'.index', $filters));

        $response->assertViewHas('organizations', fn ($rows) => $rows->total() === 21)
            ->assertSeeHtml('href="'.e(route($prefix.'.index', $filters + ['page' => 2])).'"')
            ->assertSeeHtml('href="'.route($prefix.'.index').'"');
        $this->get(route($prefix.'.index', $filters + ['page' => 2]))
            ->assertViewHas('organizations', fn ($rows) => $rows->count() === 1);
        $this->get(route($prefix.'.index'))->assertViewHas('sort', 'created_at')->assertViewHas('direction', 'desc')
            ->assertSeeHtml('name="city" type="text" value=""');
    }

    #[TestWith(['admin.pharmacies'])]
    #[TestWith(['admin.providers'])]
    public function test_invalid_filters_are_rejected_before_querying(string $prefix): void
    {
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));

        foreach ([
            ['created_from' => '2026-02-30'], ['created_to' => 'not-a-date'],
            ['created_from' => '2026-10-02', 'created_to' => '2026-10-01'],
            ['sort' => 'id desc; drop table users'], ['direction' => 'sideways'], ['status' => 'deleted'],
            ['name' => ['array']], ['email' => str_repeat('a', 256)], ['phone' => str_repeat('1', 33)], ['city' => ['array']],
        ] as $filters) {
            $field = array_key_last($filters);
            $this->getJson(route($prefix.'.index', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    #[TestWith(['pharmacy', 'admin.pharmacies'])]
    #[TestWith(['wholesaler', 'admin.providers'])]
    public function test_linked_account_controls_preserve_block_and_phone_recovery_behavior(string $type, string $prefix): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->{$type}()->create(['phone' => '+992900111111', 'is_blocked' => true]);
        $page = route($prefix.'.show', $account->organization);

        $response = $this->actingAs($admin)->get($page);

        $response->assertSeeHtml('action="'.route('admin.block', $account).'"')
            ->assertSeeHtml('action="'.route('admin.phone.remediate', $account).'"')
            ->assertSee('Разблокировать');
        $this->from($page)->post(route('admin.phone.remediate', $account), ['phone' => '900222222'])->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $account->id, 'phone' => '+992900222222', 'is_blocked' => false, 'password' => null]);
        $this->assertDatabaseHas('organizations', ['id' => $account->organization_id, 'phone' => '+992900222222']);
        $this->assertDatabaseHas('audit_events', ['event' => 'user.phone_remediated', 'subject_id' => $account->id]);
        $this->get($page)->assertDontSeeHtml('action="'.route('admin.phone.remediate', $account).'"')->assertSee('Заблокировать');
        $this->from($page)->post(route('admin.block', $account))->assertRedirect($page);
        $this->assertTrue($account->fresh()->is_blocked);
        $this->assertDatabaseHas('audit_events', ['event' => 'user.block_toggled', 'subject_id' => $account->id]);
        $this->post(route('admin.block', $account))->assertRedirect($page);
        $this->assertFalse($account->fresh()->is_blocked);
        $this->actingAs(User::factory()->pharmacy()->create())->post(route('admin.block', $account))->assertForbidden();
        $this->post(route('admin.phone.remediate', $account), ['phone' => '900333333'])->assertForbidden();
        $this->assertSame('+992900222222', $account->fresh()->phone);
    }

    public function test_secondary_and_admin_accounts_remain_read_only_in_linked_account_sections(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $supplier = User::factory()->wholesaler()->create(['is_blocked' => true]);
        $pharmacy = $supplier->organization->linkedPharmacyOrganization;
        $admin->roleAssignments()->where('role', UserRole::Pharmacy)->update(['organization_id' => $pharmacy->id]);
        $this->actingAs($admin);

        foreach (['show', 'edit'] as $page) {
            $response = $this->get(route('admin.pharmacies.'.$page, $pharmacy));
            foreach ([$admin, $supplier] as $account) {
                $response->assertSee($account->name)->assertDontSeeHtml('action="'.route('admin.block', $account).'"')
                    ->assertDontSeeHtml('action="'.route('admin.phone.remediate', $account).'"');
            }
        }
        foreach ([$admin, $supplier] as $account) {
            $this->patch(route('admin.pharmacies.accounts.update', [$pharmacy, $account]), ['name' => 'Changed', 'email' => $account->email, 'is_blocked' => false])->assertForbidden();
            $this->assertSame($account->name, $account->fresh()->name);
        }
        $this->post(route('admin.block', $admin))->assertUnprocessable();
        $this->post(route('admin.phone.remediate', $admin), ['phone' => '900333333'])->assertUnprocessable();
    }

    public function test_users_excludes_all_business_memberships_and_roles_without_changing_records(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $eligible = User::factory()->admin()->count(30)->create();
        foreach ($eligible->prepend($admin) as $account) {
            $account->roleAssignments()->whereIn('role', ['pharmacy', 'wholesaler'])->delete();
        }
        User::factory()->create(['organization_id' => null]);
        User::factory()->wholesaler()->create(['is_blocked' => true]);
        $primaryLinked = User::factory()->admin()->create();
        $primaryLinked->roleAssignments()->whereIn('role', ['pharmacy', 'wholesaler'])->delete();
        User::query()->whereKey($primaryLinked->id)->update(['organization_id' => Organization::factory()->pharmacy()->create()->id]);
        $roleOnly = User::factory()->admin()->create();
        $roleOnly->roleAssignments()->where('role', UserRole::Pharmacy)->delete();
        $roleOnly->roleAssignments()->where('role', UserRole::Wholesaler)->update(['organization_id' => null]);
        $membershipOnly = User::factory()->admin()->create(['is_blocked' => true]);
        $membershipOnly->roleAssignments()->whereIn('role', ['pharmacy', 'wholesaler'])->delete();
        $membershipOnly->roleAssignments()->where('role', UserRole::Admin)->update(['organization_id' => Organization::factory()->wholesaler()->create()->id]);
        $multiple = User::factory()->admin()->create();
        $multiple->forceFill(['is_blocked' => true])->save();
        $before = User::query()->orderBy('id')->get()->toArray();

        $this->actingAs($admin)->get(route('admin.users'))->assertViewHas('users', fn ($users) => $users->total() === 31 && $users->count() === 30);
        $this->get(route('admin.users', ['page' => 2]))->assertViewHas('users', fn ($users) => $users->modelKeys() === [$eligible->last()->id]);

        $this->assertSame($before, User::query()->orderBy('id')->get()->toArray());
    }
}
