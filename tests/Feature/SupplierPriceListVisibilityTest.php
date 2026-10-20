<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SupplierPriceListVisibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_hide_and_restore_with_audit_only_for_actual_changes(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $supplier = Organization::factory()->wholesaler()->create();
        $this->assertFalse($supplier->fresh()->price_list_hidden);
        $url = route('admin.providers.price-list-visibility.update', $supplier);

        foreach ([true, true, false] as $hidden) {
            $this->actingAs($admin)->patch($url, ['price_list_hidden' => $hidden])->assertRedirect(route('admin.providers.show', $supplier));
            $this->assertSame($hidden, $supplier->fresh()->price_list_hidden);
        }

        $events = AuditEvent::query()->where('event', 'organization.price_list_visibility_updated')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['price_list_hidden' => false], $events[0]->before);
        $this->assertSame(['price_list_hidden' => true], $events[0]->after);
        $this->assertSame(['price_list_hidden' => false], $events[1]->after);
        $this->actingAs($admin)->get(route('admin.providers.show', $supplier))->assertSeeText('Скрыть прайс-лист');
        $this->actingAs($admin)->patch($url, ['price_list_hidden' => true])->assertRedirect();
        $this->actingAs($admin)->get(route('admin.providers.show', $supplier))->assertSeeText('Восстановить прайс-лист');
        $this->actingAs($admin)->get(route('admin.providers.index'))->assertSeeText('Прайс-лист временно скрыт');
    }

    public function test_visibility_requires_admin_and_boolean_input_and_rejects_pharmacies(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $url = route('admin.providers.price-list-visibility.update', $supplier);
        $this->patchJson($url, ['price_list_hidden' => true])->assertUnauthorized();
        $user = User::factory()->wholesaler($supplier)->create();
        $this->actingAs($user)->patchJson($url, ['price_list_hidden' => true])->assertForbidden();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        foreach ([[], ['price_list_hidden' => 'yes'], ['price_list_hidden' => null]] as $data) {
            $this->actingAs($admin)->patchJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('price_list_hidden');
        }
        $pharmacy = Organization::factory()->pharmacy()->create();
        $this->actingAs($admin)->patchJson(route('admin.providers.price-list-visibility.update', $pharmacy), ['price_list_hidden' => true])->assertNotFound();
        $this->assertFalse($supplier->fresh()->price_list_hidden);
        $this->assertFalse($pharmacy->fresh()->price_list_hidden);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
