<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\Organization;
use App\Models\PharmacySupplier;
use App\Models\PriceListImport;
use App\Models\Subscription;
use App\Models\SupplierInvitation;
use App\Models\SupplierSenderAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PartnersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_directory_filters_active_suppliers_and_shows_unknown_contacts(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        Organization::factory()->wholesaler()->create(['name' => 'Первый поставщик', 'city' => 'Душанбе']);
        Organization::factory()->wholesaler()->create(['name' => 'Другой поставщик', 'city' => 'Худжанд']);
        Organization::factory()->wholesaler()->blocked()->create(['name' => 'Скрытый поставщик', 'city' => 'Душанбе']);

        $this->actingAs($user)->get(route('partners.index', ['name' => 'Первый', 'city' => 'Душанбе']))
            ->assertOk()->assertSeeText('Первый поставщик')->assertSeeText('Контактное лицо')->assertSeeText('Не указана')
            ->assertDontSeeText('Другой поставщик')->assertDontSeeText('Скрытый поставщик');
    }

    public function test_phone_link_discount_and_pharmacy_isolation(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $other = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        $otherUser = User::factory()->pharmacy($other)->create();
        Subscription::factory()->for($user)->create();
        Subscription::factory()->for($otherUser)->create();
        $supplier = Organization::factory()->wholesaler()->create(['phone' => '+992901234567']);

        $this->actingAs($user)->post(route('partners.phone'), ['phone' => '+992901234567'])->assertRedirect();
        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['discount_percent' => '0.00'])->assertRedirect();
        $this->assertDatabaseHas('pharmacy_suppliers', ['pharmacy_organization_id' => $pharmacy->id, 'supplier_organization_id' => $supplier->id, 'discount_percent' => 0]);
        $this->actingAs($otherUser)->patch(route('partners.discount', $supplier), ['discount_percent' => '40.00'])->assertNotFound();
        $this->actingAs($otherUser)->get(route('partners.index'))->assertSeeText('Не указана')->assertDontSeeText('0.00%');
    }

    public function test_invitation_is_single_use_and_expiry_is_enforced(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $supplierUser = User::factory()->wholesaler($supplier)->create();
        $pharmacy = Organization::factory()->pharmacy()->create();
        $pharmacyUser = User::factory()->pharmacy($pharmacy)->create();
        $other = Organization::factory()->pharmacy()->create();
        $otherUser = User::factory()->pharmacy($other)->create();
        Subscription::factory()->for($pharmacyUser)->create();
        Subscription::factory()->for($otherUser)->create();

        $this->actingAs($supplierUser)->post(route('supplier.invitations.store'))->assertSessionHas('invitation_code');
        $code = session('invitation_code');
        $this->assertDatabaseHas('supplier_invitations', ['supplier_organization_id' => $supplier->id, 'code_hash' => hash('sha256', $code)]);
        $this->actingAs($pharmacyUser)->post(route('partners.code'), ['code' => $code])->assertSessionHas('success');
        $this->actingAs($otherUser)->post(route('partners.code'), ['code' => $code])->assertSessionHasErrors('code');
        $expired = SupplierInvitation::create(['supplier_organization_id' => $supplier->id, 'code_hash' => hash('sha256', 'EXPIRED'), 'expires_at' => now()->subDay()]);
        $this->actingAs($otherUser)->post(route('partners.code'), ['code' => 'EXPIRED'])->assertSessionHasErrors('code');
        $this->assertNull($expired->fresh()->redeemed_at);
        $this->assertSame(1, PharmacySupplier::count());
    }

    public function test_directory_paginates_and_displays_import_and_contact_fallbacks(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'A Альфа', 'phone' => null]);
        SupplierSenderAddress::factory()->for($supplier, 'supplier')->create(['email' => 'sender@example.com']);
        PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed, 'received_at' => null, 'created_at' => '2026-09-15 08:30:00']);
        Organization::factory()->wholesaler()->count(20)->create(['city' => 'Душанбе']);

        $this->actingAs($user)->get(route('partners.index'))
            ->assertOk()->assertSeeText('Альфа')->assertSeeText('sender@example.com')->assertSeeText('15.09.2026 13:30')->assertSeeText('Активен')
            ->assertSee('page=2', false);
    }

    public function test_discount_rejects_out_of_range_values_and_invalid_phone(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create(['phone' => null]);
        $this->actingAs($user)->post(route('partners.phone'), ['phone' => 'bad'])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('pharmacy_suppliers', 0);
        PharmacySupplier::create(['pharmacy_organization_id' => $pharmacy->id, 'supplier_organization_id' => $supplier->id]);
        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['discount_percent' => '100.01'])->assertSessionHasErrors('discount_percent');
        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['discount_percent' => '100.00'])->assertSessionHas('success');
        $this->assertDatabaseHas('pharmacy_suppliers', ['pharmacy_organization_id' => $pharmacy->id, 'discount_percent' => 100]);
    }

    public function test_supplier_can_edit_directory_contacts_and_revoke_an_invitation(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $user = User::factory()->wholesaler($supplier)->create();

        $this->actingAs($user)->patch(route('profile.organization.update'), [
            'name' => $supplier->name,
            'city' => 'Душанбе',
            'contact_name' => 'Мадина',
            'contact_email' => 'contact@example.com',
            'whatsapp_phone' => '+992901234567',
            'additional_phones' => "+992901111111\n+992902222222",
        ])->assertSessionHas('success');
        $this->assertSame(['+992901111111', '+992902222222'], $supplier->fresh()->additional_phones);

        $this->actingAs($user)->post(route('supplier.invitations.store'))->assertSessionHas('invitation_code');
        $invitation = SupplierInvitation::query()->firstOrFail();
        $this->actingAs($user)->post(route('supplier.invitations.revoke', $invitation))->assertSessionHas('success');
        $this->assertNotNull($invitation->fresh()->revoked_at);
    }
}
