<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\Subscription;
use App\Models\SupplierSenderAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PartnersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_directory_filters_active_suppliers_and_shows_unknown_contacts(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'Первый поставщик', 'city' => 'Душанбе', 'phone' => '+992901234567']);
        Organization::factory()->wholesaler()->create(['name' => 'Другой поставщик', 'city' => 'Худжанд']);
        Organization::factory()->wholesaler()->blocked()->create(['name' => 'Скрытый поставщик', 'city' => 'Душанбе']);

        $this->actingAs($user)->get(route('partners.index', ['name' => 'Первый', 'city' => 'Душанбе']))
            ->assertOk()->assertSeeText('Первый поставщик')->assertSeeText('Город')->assertSeeText('Срок доступа')->assertSeeText('Без ограничения по сроку')
            ->assertSee('id="partner-'.$supplier->id.'-contacts"', false)->assertSee('data-contact-details hidden', false)
            ->assertDontSeeText('Другой поставщик')->assertDontSeeText('Скрытый поставщик');
    }

    public function test_directory_cards_open_supplier_dialogs_without_a_details_button(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create();

        $this->actingAs($user)->get(route('partners.index'))
            ->assertOk()
            ->assertSee('aria-controls="partner-'.$supplier->id.'"', false)
            ->assertSee('data-dialog-open', false)
            ->assertDontSeeText('Подробнее');
    }

    public function test_combined_filters_are_preserved_by_city_links_search_and_pagination(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        Organization::factory()->wholesaler()->count(21)->create(['name' => 'Фарма партнёр', 'city' => 'Душанбе']);
        Organization::factory()->wholesaler()->create(['name' => 'Фарма партнёр Худжанд', 'city' => 'Худжанд']);

        $response = $this->actingAs($user)->get(route('partners.index', ['name' => 'Фарма', 'city' => 'Душанбе']));

        $response->assertOk()
            ->assertSeeText('Найдено: 21')
            ->assertSee('name="city" value="Душанбе"', false)
            ->assertSee(route('partners.index', ['name' => 'Фарма', 'city' => 'Худжанд']))
            ->assertSee('page=2', false)
            ->assertSee('name=%D0%A4%D0%B0%D1%80%D0%BC%D0%B0', false)
            ->assertDontSeeText('Фарма партнёр Худжанд');
    }

    public function test_directory_distinguishes_available_catalogs_from_missing_or_empty_catalogs(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $availableSupplier = Organization::factory()->wholesaler()->create(['name' => 'А Доступный']);
        $availableImport = PriceListImport::factory()->for($availableSupplier, 'supplier')->create([
            'status' => PriceListImportStatus::Completed,
            'received_at' => '2026-09-20 06:15:00',
        ]);
        $availableSupplier->update(['active_price_list_import_id' => $availableImport->id]);
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $availableSupplier->id]);
        Offer::factory()->for($availableSupplier, 'organization')->for($medicine)->create([
            'price_list_import_id' => $availableImport->id,
            'quantity' => 5,
            'source_row' => 2,
        ]);
        Organization::factory()->wholesaler()->create(['name' => 'Б Недоступный']);

        $this->actingAs($user)->get(route('partners.index'))
            ->assertOk()
            ->assertSeeTextInOrder(['А Доступный', 'Прайс обновлён 20.09.2026 11:15', 'Прайс доступен', 'Прайс открыт', '20.09.2026 11:15', 'Б Недоступный', 'Прайс недоступен', 'Прайс закрыт']);
    }

    public function test_directory_only_creates_links_for_valid_contact_values(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        Organization::factory()->wholesaler()->create([
            'name' => 'Контактный поставщик',
            'phone' => '+992901234567',
            'additional_phones' => ['invalid-number', '918765432'],
            'whatsapp_phone' => '+992 92 111 22 33',
            'contact_email' => 'sales@example.com',
        ]);
        Organization::factory()->wholesaler()->create([
            'name' => 'Некорректные контакты',
            'phone' => 'broken',
            'whatsapp_phone' => 'also-broken',
            'contact_email' => 'not-an-email',
        ]);

        $this->actingAs($user)->get(route('partners.index'))
            ->assertOk()
            ->assertSee('href="tel:+992901234567"', false)
            ->assertSee('href="tel:+992918765432"', false)
            ->assertSee('href="https://wa.me/992921112233"', false)
            ->assertSee('href="mailto:sales@example.com"', false)
            ->assertDontSee('href="tel:broken"', false)
            ->assertDontSee('href="https://wa.me/also-broken"', false)
            ->assertDontSee('href="mailto:not-an-email"', false);
    }

    public function test_pharmacies_can_save_their_own_discounts_without_linking(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $other = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        $otherUser = User::factory()->pharmacy($other)->create();
        Subscription::factory()->for($user)->create();
        Subscription::factory()->for($otherUser)->create();
        $supplier = Organization::factory()->wholesaler()->create();

        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => '0.00'])->assertRedirect();
        $this->actingAs($otherUser)->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => '40.00'])->assertRedirect();

        $this->assertDatabaseHas('pharmacy_supplier_discounts', ['pharmacy_organization_id' => $pharmacy->id, 'supplier_organization_id' => $supplier->id, 'supplier_discount_percent' => 0]);
        $this->assertDatabaseHas('pharmacy_supplier_discounts', ['pharmacy_organization_id' => $other->id, 'supplier_organization_id' => $supplier->id, 'supplier_discount_percent' => 40]);
        $this->actingAs($user)->get(route('partners.index'))->assertSeeText('Ваша скидка от поставщика, %')->assertSeeText('Оставьте поле пустым, чтобы удалить договорённость.');
        $this->actingAs($otherUser)->get(route('partners.index'))
            ->assertSee('value="40.00"', false)
            ->assertSeeText('Ваша скидка 40.00%')
            ->assertDontSee('add-partner', false);
    }

    public function test_linking_and_invitation_routes_are_absent(): void
    {
        $this->assertFalse(Route::has('partners.phone'));
        $this->assertFalse(Route::has('partners.code'));
        $this->assertFalse(Route::has('supplier.invitations.store'));
        $this->assertFalse(Route::has('supplier.invitations.revoke'));
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
            ->assertOk()->assertSeeText('Альфа')->assertSeeText('sender@example.com')->assertSeeText('Прайс закрыт')->assertSeeText('Активный прайс-лист не загружен')
            ->assertSee('page=2', false);
    }

    public function test_discount_rejects_out_of_range_values_without_creating_an_agreement(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create();
        $this->actingAs($user)->from(route('partners.index'))->patch(route('partners.discount', $supplier), ['supplier_id' => $supplier->id, 'supplier_discount_percent' => '100.01'])->assertSessionHasErrors('supplier_discount_percent');
        $this->assertDatabaseCount('pharmacy_supplier_discounts', 0);
        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => '100.00'])->assertSessionHas('success');
        $this->assertDatabaseHas('pharmacy_supplier_discounts', ['pharmacy_organization_id' => $pharmacy->id, 'supplier_discount_percent' => 100]);
    }

    public function test_supplier_can_edit_directory_contacts_without_invitation_controls(): void
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

        $this->actingAs($user)->get(route('profile.edit'))->assertDontSeeText('Коды приглашения');
    }
}
