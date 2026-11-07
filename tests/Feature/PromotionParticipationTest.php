<?php

namespace Tests\Feature;

use App\Enums\PromotionPurchaseScope;
use App\Enums\PromotionStatus;
use App\Enums\TradeMode;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\Promotion;
use App\Models\PromotionContribution;
use App\Models\PromotionItem;
use App\Models\PromotionParticipant;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PromotionQualification;
use App\Services\PromotionWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\PromotionParticipantSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class PromotionParticipationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orders.placement_enabled' => true]);
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(10, 0));
    }

    private function buyer(?Organization $pharmacy = null): User
    {
        $buyer = User::factory()->pharmacy()->create($pharmacy ? ['organization_id' => $pharmacy->id] : []);
        Subscription::factory()->for($buyer)->create();

        return $buyer;
    }

    private function promotion(User $supplier, array $settings = []): Promotion
    {
        return Promotion::factory()->for($supplier->organization)->withParticipation()->published()->create($settings);
    }

    private function place(User $buyer, Promotion $promotion, string $price = '100.00', string $path = 'all', int $quantity = 1): Order
    {
        $item = PromotionItem::factory()->for($promotion)->create(['price' => $price]);
        $this->actingAs($buyer)->post(route('cart.promotions.add', $promotion), ['items' => [['id' => $item->id, 'quantity' => $quantity]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($path === 'all' ? route('cart.checkout') : route('cart.suppliers.checkout', $promotion->organization))->assertRedirect(route('orders.index'))->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }

    private function updateOrderStatus(User $supplier, Order $order, string $status): void
    {
        $this->actingAs($supplier)->patch(route('orders.status', $order), ['status' => $status])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function settings(array $overrides = []): array
    {
        return array_replace(['_promotion_form' => 'participation', 'participation_enabled' => '1', 'purchase_scope' => 'company', 'condition_match' => 'all', 'minimum_total' => '432.10', 'prizes' => [['name' => 'Ноутбук'], ['name' => 'Набор', 'description' => 'Для аптеки', 'quantity' => 3]]], $overrides);
    }

    #[TestWith(['all'])]
    #[TestWith(['supplier'])]
    public function test_both_checkout_paths_capture_discounted_mixed_lines_and_each_overlapping_promotion_independently(string $path): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $supplier->organization->update(['default_discount_percent' => '10.00']);
        $buyer = $this->buyer();
        $company = $this->promotion($supplier, ['minimum_total' => '37.07']);
        $specific = $this->promotion($supplier, ['purchase_scope' => 'promotion', 'minimum_total' => null, 'minimum_order_value' => '20.00']);
        $unrelated = $this->promotion($supplier, ['purchase_scope' => 'promotion']);
        $otherSupplier = $this->promotion(User::factory()->wholesaler()->create());
        $ordinary = Promotion::factory()->published()->for($supplier->organization)->create();
        $import = PriceListImport::factory()->for($supplier->organization, 'supplier')->create();
        $supplier->organization->update(['active_price_list_import_id' => $import->id]);
        $offer = Offer::factory()->for($supplier->organization)->create(['price_list_import_id' => $import->id, 'price' => '12.34', 'quantity' => 100]);
        $item = PromotionItem::factory()->for($specific)->create(['price' => '10.00']);
        $this->actingAs($buyer)->post(route('cart.add', $offer), ['quantity' => 3])->assertRedirect();
        $this->post(route('cart.promotions.add', $specific), ['items' => [['id' => $item->id, 'quantity' => 2]]])->assertRedirect();
        $this->post($path === 'all' ? route('cart.checkout') : route('cart.suppliers.checkout', $supplier->organization))->assertRedirect(route('orders.index'));
        $order = Order::query()->sole();
        $this->assertSame('53.30', $order->total);
        $this->assertDatabaseHas('promotion_contributions', ['promotion_id' => $company->id, 'order_id' => $order->id, 'amount' => '53.30']);
        $this->assertDatabaseHas('promotion_contributions', ['promotion_id' => $specific->id, 'order_id' => $order->id, 'amount' => '20.00']);
        foreach ([$unrelated, $otherSupplier, $ordinary] as $excluded) {
            $this->assertDatabaseMissing('promotion_contributions', ['promotion_id' => $excluded->id]);
        }
        $this->assertDatabaseCount('promotion_participants', 0);
        $this->updateOrderStatus($supplier, $order, 'confirmed');
        $this->assertDatabaseCount('promotion_participants', 2);
        $this->assertTrue($specific->participants()->sole()->is_eligible);
        $this->assertSame(1, $specific->participants()->sole()->qualifying_order_count);
        $this->get(route('promotions.participants', $specific))->assertOk()->assertSee($buyer->organization->name)->assertSee('Заказ №'.$order->id);
    }

    #[TestWith(['minimum_total'])]
    #[TestWith(['minimum_order_value'])]
    #[TestWith(['minimum_order_count'])]
    public function test_each_configurable_condition_has_an_inclusive_exact_boundary(string $condition): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $buyer = $this->buyer();
        $promotion = $this->promotion($supplier, ['minimum_total' => null, $condition => $condition === 'minimum_order_count' ? 3 : '123.45']);
        $first = $this->place($buyer, $promotion, '123.44');
        $this->updateOrderStatus($supplier, $first, 'confirmed');
        $this->assertDatabaseCount('promotion_participants', 0);
        $second = $this->place($buyer, $promotion, '0.01');
        $this->updateOrderStatus($supplier, $second, 'confirmed');
        if ($condition === 'minimum_total') {
            $this->assertTrue($promotion->participants()->sole()->is_eligible);
            $this->assertSame('123.45', $promotion->participants()->sole()->qualifying_total);
        } else {
            $this->assertDatabaseCount('promotion_participants', 0);
            $third = $this->place($buyer, $promotion, '123.45');
            $this->updateOrderStatus($supplier, $third, 'confirmed');
            $this->assertTrue($promotion->participants()->sole()->is_eligible);
            $this->assertSame(3, $promotion->participants()->sole()->qualifying_order_count);
            $this->assertSame('123.45', $promotion->participants()->sole()->largest_order_value);
        }
    }

    #[TestWith(['all', false])]
    #[TestWith(['any', true])]
    public function test_all_and_any_conditions_combine_company_purchases_by_multiple_pharmacy_staff(string $match, bool $firstEligible): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $buyer = $this->buyer();
        $colleague = $this->buyer($buyer->organization);
        $promotion = $this->promotion($supplier, ['minimum_total' => '100.00', 'minimum_order_value' => '75.00', 'minimum_order_count' => 2, 'condition_match' => $match]);
        $first = $this->place($buyer, $promotion);
        $this->updateOrderStatus($supplier, $first, 'confirmed');
        $this->assertSame($firstEligible, $promotion->participants()->exists());
        $second = $this->place($colleague, $promotion, '1.00');
        $this->updateOrderStatus($supplier, $second, 'confirmed');
        $participant = $promotion->participants()->sole();
        $this->assertTrue($participant->is_eligible);
        $this->assertSame('101.00', $participant->qualifying_total);
        $this->assertSame(2, $participant->qualifying_order_count);
        $this->actingAs($colleague)->get(route('promotions.participations'))->assertSee($promotion->title);
        $this->get(route('promotions.show', $promotion))->assertSee('101.00')->assertSee('Ваша аптека участвует');
    }

    #[TestWith(['received', false])]
    #[TestWith(['partially_confirmed', false])]
    #[TestWith(['cancelled', false])]
    #[TestWith(['confirmed', true])]
    #[TestWith(['preparing', true])]
    #[TestWith(['ready', true])]
    #[TestWith(['delivering', true])]
    #[TestWith(['received_by_customer', true])]
    public function test_only_supplier_confirmed_statuses_count(string $status, bool $eligible): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = $this->promotion($supplier);
        $order = $this->place($this->buyer(), $promotion);
        $this->updateOrderStatus($supplier, $order, $status);
        $this->assertSame($eligible, $promotion->participants()->exists());
        $this->assertSame($eligible ? 1 : 0, app(PromotionQualification::class)->progress($promotion, $order->buyer_organization_id)['qualifying_order_count']);
    }

    #[TestWith(['cancelled'])]
    #[TestWith(['received'])]
    #[TestWith(['partially_confirmed'])]
    public function test_late_confirmation_cancellation_and_requalification_keep_one_registration_and_its_original_date(string $reversal): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $buyer = $this->buyer();
        $promotion = $this->promotion($supplier, ['ends_on' => now('Asia/Dushanbe')->toDateString()]);
        $order = $this->place($buyer, $promotion);
        $this->travel(2)->days();
        $this->updateOrderStatus($supplier, $order, 'confirmed');
        $participant = $promotion->participants()->sole();
        $date = $participant->qualified_at->toDateTimeString();
        $this->assertTrue($participant->is_eligible);
        $this->updateOrderStatus($supplier, $order, $reversal);
        $participant->refresh();
        $this->assertFalse($participant->is_eligible);
        $this->assertSame('0.00', $participant->qualifying_total);
        $this->assertSame(0, $participant->qualifying_order_count);
        $this->travel(1)->days();
        $this->updateOrderStatus($supplier, $order, 'confirmed');
        $this->updateOrderStatus($supplier, $order, 'confirmed');
        $this->assertSame($participant->id, $promotion->participants()->sole()->id);
        $this->assertSame($date, $participant->fresh()->qualified_at->toDateTimeString());
        $this->assertTrue($participant->fresh()->is_eligible);
        $this->actingAs($buyer)->get(route('promotions.participations'))->assertSee($promotion->title)->assertSee('Условия выполнены');
        $this->get(route('promotions.show', $promotion))->assertOk()->assertSee('Акция завершена')->assertSee('Подарочный набор');
    }

    public function test_period_boundaries_first_publication_and_unpublished_intervals_are_frozen_at_placement(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $buyer = $this->buyer();
        $source = Promotion::factory()->published()->for($supplier->organization)->create(['ends_on' => '2026-11-15']);
        $prize = $this->promotion($supplier, ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-10']);
        $beforeStart = $this->place($buyer, $source);
        $this->assertDatabaseMissing('promotion_contributions', ['order_id' => $beforeStart->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 00:00:00', 'Asia/Dushanbe'));
        $atStart = $this->place($buyer, $source);
        $this->assertDatabaseHas('promotion_contributions', ['order_id' => $atStart->id]);
        $prize->update(['status' => PromotionStatus::Draft]);
        $unpublished = $this->place($buyer, $source);
        $this->assertDatabaseMissing('promotion_contributions', ['order_id' => $unpublished->id]);
        $prize->update(['status' => PromotionStatus::Published]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 23:59:59', 'Asia/Dushanbe'));
        $lastSecond = $this->place($buyer, $source);
        $this->assertDatabaseHas('promotion_contributions', ['order_id' => $lastSecond->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 00:00:00', 'Asia/Dushanbe'));
        $afterEnd = $this->place($buyer, $source);
        $this->assertDatabaseMissing('promotion_contributions', ['order_id' => $afterEnd->id]);
        foreach ([$beforeStart, $atStart, $unpublished, $lastSecond, $afterEnd] as $order) {
            $this->updateOrderStatus($supplier, $order, 'confirmed');
        }
        $this->assertSame(2, $prize->participants()->sole()->qualifying_order_count);
        $this->assertSame('200.00', $prize->participants()->sole()->qualifying_total);
        $laterPublication = $this->promotion($supplier, ['first_published_at' => now()->addHour(), 'starts_on' => '2026-11-08', 'ends_on' => '2026-11-15']);
        $beforePublication = $this->place($buyer, $source);
        $this->assertDatabaseMissing('promotion_contributions', ['promotion_id' => $laterPublication->id, 'order_id' => $beforePublication->id]);
        $this->travel(1)->hours();
        $atPublication = $this->place($buyer, $source);
        $this->assertDatabaseHas('promotion_contributions', ['promotion_id' => $laterPublication->id, 'order_id' => $atPublication->id]);
    }

    public function test_capture_and_reconciliation_are_idempotent_and_never_backfill_historical_orders(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $buyer = $this->buyer();
        $promotion = $this->promotion($supplier);
        $order = $this->place($buyer, $promotion);
        app(PromotionWorkflow::class)->locked($promotion, function () use ($buyer, $order): void {
            app(PromotionQualification::class)->capture($order, $buyer);
            app(PromotionQualification::class)->capture($order, $buyer);
        });
        $this->assertDatabaseCount('promotion_contributions', 1);
        $historical = Order::factory()->create(['buyer_organization_id' => $buyer->organization_id, 'supplier_organization_id' => $supplier->organization_id, 'status' => 'confirmed', 'total' => '1000.00']);
        $historical->items()->create(['quantity' => 10, 'unit_price' => '100.00', 'snapshot' => []]);
        $order->update(['status' => 'confirmed']);
        $this->artisan('promotions:reconcile-participation')->assertSuccessful();
        $participant = $promotion->participants()->sole();
        $this->assertSame('100.00', $participant->qualifying_total);
        $this->artisan('promotions:reconcile-participation', ['--promotion' => $promotion->id])->assertSuccessful();
        $this->assertEquals($participant->toArray(), $participant->fresh()->toArray());
        $order->update(['status' => 'cancelled']);
        $this->artisan('promotions:reconcile-participation')->assertSuccessful();
        $this->assertFalse($participant->fresh()->is_eligible);
        $this->assertDatabaseCount('promotion_contributions', 1);
        $this->assertDatabaseCount('promotion_participants', 1);
    }

    public function test_settings_and_prizes_are_saved_together_and_optional_for_ordinary_promotions(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $this->assertNull($promotion->purchase_scope);
        $this->actingAs($supplier)->patch(route('promotions.participation-settings', $promotion), $this->settings())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(PromotionPurchaseScope::Company, $promotion->fresh()->purchase_scope);
        $this->assertSame('432.10', $promotion->fresh()->minimum_total);
        $this->assertSame([1, 3], $promotion->prizes()->pluck('quantity')->all());
        $this->get(route('promotions.edit', $promotion))->assertOk()->assertSee('Условия участия и призы')->assertSee('Ноутбук');
        $this->patch(route('promotions.participation-settings', $promotion), ['participation_enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertNull($promotion->fresh()->purchase_scope);
        $this->assertNull($promotion->fresh()->minimum_total);
        $this->assertSame(0, $promotion->prizes()->count());
    }

    #[TestWith(['minimum_total', '0'])]
    #[TestWith(['minimum_total', '-1'])]
    #[TestWith(['minimum_total', '12.345'])]
    #[TestWith(['minimum_total', '100000000000000'])]
    #[TestWith(['minimum_order_value', '-50'])]
    #[TestWith(['minimum_order_count', '0'])]
    #[TestWith(['minimum_order_count', '1.5'])]
    #[TestWith(['purchase_scope', 'foreign'])]
    #[TestWith(['condition_match', 'foreign'])]
    #[TestWith(['prizes', []])]
    #[TestWith(['prizes', [['description' => 'Без названия']]])]
    #[TestWith(['prizes', [['name' => 'Приз', 'quantity' => 0]]])]
    public function test_invalid_settings_leave_existing_settings_and_prizes_untouched(string $field, mixed $value): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->withParticipation()->for($supplier->organization)->create();
        $this->actingAs($supplier)->patch(route('promotions.participation-settings', $promotion), $this->settings([$field => $value]))->assertSessionHasErrors();
        $this->assertSame('100.00', $promotion->fresh()->minimum_total);
        $this->assertSame('Подарочный набор', $promotion->prizes()->sole()->name);
    }

    public function test_enabling_requires_a_condition_and_failed_input_stays_scoped_and_works_without_javascript(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->create();
        $this->actingAs($supplier)->from(route('promotions.edit', $promotion))->patch(route('promotions.participation-settings', $promotion), $this->settings(['minimum_total' => null]))
            ->assertSessionHasErrors('minimum_total')->assertSessionHasInput('_promotion_form', 'participation');
        $this->get(route('promotions.edit', $promotion))->assertSee('Укажите хотя бы одно условие')->assertSee('Ноутбук')->assertSee('data-restored-form="participation"', false);
        $this->from(route('promotions.edit', $promotion))->patch(route('promotions.participation-settings', $promotion), $this->settings(['add_prize' => '1', 'prize_rows' => 3]))->assertRedirect(route('promotions.edit', $promotion).'#participation')->assertSessionHasNoErrors()->assertSessionHasInput('_prize_rows', 4);
        $this->get(route('promotions.edit', $promotion))->assertSee('Приз 4')->assertSee('Ноутбук');
        $this->assertNull($promotion->fresh()->purchase_scope);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_first_publication_locks_participation_prizes_and_dates_for_suppliers_and_administrators(bool $admin): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = $this->promotion($supplier);
        $promotion->update(['status' => PromotionStatus::Draft]);
        $actor = $admin ? User::factory()->admin()->create() : $supplier;
        $this->actingAs($actor)->patch(route('promotions.participation-settings', $promotion), $this->settings())->assertSessionHasErrors('participation_enabled');
        $this->assertSame('100.00', $promotion->fresh()->minimum_total);
        $this->assertSame('Подарочный набор', $promotion->prizes()->sole()->name);
        $data = $promotion->only('organization_id', 'title', 'description', 'conditions', 'city');
        $data['starts_on'] = $promotion->starts_on->toDateString();
        $data['ends_on'] = $promotion->ends_on->addDay()->toDateString();
        $this->patch(route('promotions.update', $promotion), $data)->assertSessionHasErrors('ends_on');
        $data['ends_on'] = $promotion->fresh()->ends_on->toDateString();
        $data['title'] = 'Изменённое описание акции';
        $this->patch(route('promotions.update', $promotion), $data)->assertSessionHasNoErrors();
        $this->assertSame($data['title'], $promotion->fresh()->title);
        $ordinary = Promotion::factory()->published()->for($supplier->organization)->create();
        $this->patch(route('promotions.participation-settings', $ordinary), $this->settings())->assertSessionHasErrors('participation_enabled');
    }

    public function test_participation_and_order_status_access_are_isolated_by_company_and_pharmacy(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $otherSupplier = User::factory()->wholesaler()->create();
        $buyer = $this->buyer();
        $stranger = $this->buyer();
        $promotion = $this->promotion($supplier);
        $order = $this->place($buyer, $promotion);
        $this->actingAs($buyer)->patch(route('orders.status', $order), ['status' => 'confirmed'])->assertForbidden();
        $this->actingAs($otherSupplier)->patch(route('orders.status', $order), ['status' => 'confirmed'])->assertForbidden();
        $this->get(route('promotions.participants', $promotion))->assertForbidden();
        $this->patch(route('promotions.participation-settings', $promotion), $this->settings())->assertForbidden();
        $this->updateOrderStatus($supplier, $order, 'confirmed');
        $this->actingAs($buyer)->get(route('promotions.participants', $promotion))->assertForbidden();
        $this->actingAs($stranger)->get(route('promotions.participations'))->assertDontSee($promotion->title);
        $this->get(route('promotions.show', $promotion))->assertSee('Общая сумма: 0.00')->assertDontSee('Ваша аптека участвует');
        $this->actingAs($otherSupplier)->get(route('promotions.participations'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('promotions.participants', $promotion))->assertOk()->assertSee($buyer->organization->name);
        $this->actingAs($buyer);
        $buyer->subscriptions()->delete();
        $this->get(route('promotions.participations'))->assertRedirect(route('subscription.create'));
    }

    public function test_capture_failure_rolls_back_the_whole_checkout_and_preserves_the_cart(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $buyer = $this->buyer();
        $promotion = $this->promotion($supplier);
        $second = $this->promotion($supplier);
        $item = PromotionItem::factory()->for($promotion)->create();
        $this->actingAs($buyer)->post(route('cart.promotions.add', $promotion), ['items' => [['id' => $item->id, 'quantity' => 1]]])->assertRedirect();
        PromotionContribution::creating(function ($contribution) use ($second): void {
            if ($contribution->promotion_id === $second->id) {
                throw new RuntimeException('capture failed');
            }
        });
        Exceptions::fake();
        $this->post(route('cart.checkout'))->assertServerError();
        Exceptions::assertReported(RuntimeException::class);
        $this->assertDatabaseCount('checkouts', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('promotion_contributions', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_demo_participation_seeder_has_supporting_orders_and_survives_reconciliation(): void
    {
        $this->seed(PromotionParticipantSeeder::class);
        $participant = PromotionParticipant::query()->sole();
        $this->assertTrue($participant->is_eligible);
        $this->assertSame('100.00', $participant->qualifying_total);
        $this->assertSame(1, $participant->promotion->contributions()->sole()->order->items()->count());
        $this->artisan('promotions:reconcile-participation')->assertSuccessful();
        $this->assertTrue($participant->fresh()->is_eligible);
        $this->assertSame(1, $participant->fresh()->qualifying_order_count);
    }

    public function test_upgrade_keeps_existing_promotions_ordinary_and_does_not_enroll_historical_orders(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->published()->for($supplier->organization)->create();
        $buyer = $this->buyer();
        $historical = Order::factory()->create(['buyer_organization_id' => $buyer->organization_id, 'supplier_organization_id' => $supplier->organization_id, 'status' => 'confirmed']);
        $migration = require database_path('migrations/2026_11_08_034346_add_participation_to_promotions.php');
        $migration->down();
        $migration->up();
        $this->assertNull($promotion->fresh()->purchase_scope);
        $this->artisan('promotions:reconcile-participation')->assertSuccessful();
        $this->assertModelExists($historical);
        $this->assertDatabaseCount('promotion_contributions', 0);
        $this->assertDatabaseCount('promotion_participants', 0);
    }

    public function test_legacy_supplier_buyers_do_not_capture_pharmacy_participation(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = $this->promotion($supplier);
        $buyer = User::factory()->wholesaler()->create(['active_trade_mode' => TradeMode::Buyer]);
        $order = $this->place($buyer, $promotion);
        $this->updateOrderStatus($supplier, $order, 'confirmed');
        $this->assertDatabaseCount('promotion_contributions', 0);
        $this->assertDatabaseCount('promotion_participants', 0);
    }

    public function test_admin_business_modes_use_the_selected_pharmacy_and_cannot_read_other_participants(): void
    {
        config(['account-modes.enabled' => true]);
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = $admin->roleAssignments()->where('role', 'pharmacy')->firstOrFail()->organization;
        $pharmacy->update(['status' => 'active', 'setup_required' => false]);
        $supplier = User::factory()->wholesaler()->create();
        $promotion = $this->promotion($supplier);
        $item = PromotionItem::factory()->for($promotion)->create();
        $this->actingAs($admin)->get(route('promotions.manage'))->assertOk();
        $version = session('account_mode.version');
        $this->patch(route('mode.update'), ['mode' => 'pharmacy', '_mode_version' => $version])->assertRedirect();
        $version = session('account_mode.version');
        $this->post(route('cart.promotions.add', $promotion), ['_mode_version' => $version, 'items' => [['id' => $item->id, 'quantity' => 1]]])->assertRedirect();
        $this->post(route('cart.checkout'), ['_mode_version' => $version])->assertRedirect(route('orders.index'));
        $order = Order::query()->sole();
        $this->assertDatabaseHas('promotion_contributions', ['pharmacy_organization_id' => $pharmacy->id, 'order_id' => $order->id]);
        $this->get(route('promotions.participants', $promotion))->assertForbidden();
        $this->patch(route('orders.status', $order), ['_mode_version' => $version, 'status' => 'confirmed'])->assertForbidden();
        $this->patch(route('promotions.participation-settings', $promotion), $this->settings(['_mode_version' => $version]))->assertForbidden();
        $this->patch(route('mode.update'), ['mode' => 'supplier', '_mode_version' => $version])->assertRedirect();
        $this->get(route('promotions.participants', $promotion))->assertForbidden();
        $this->get(route('promotions.participations'))->assertForbidden();
        $this->post(route('cart.checkout'), ['_mode_version' => $version])->assertStatus(409);
    }

    public function test_status_eligibility_and_audit_roll_back_together_on_failure(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = $this->promotion($supplier);
        $order = $this->place($this->buyer(), $promotion);
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->event === 'order.status_changed') {
                throw new RuntimeException('audit failed');
            }
        });
        Exceptions::fake();
        $this->actingAs($supplier)->patch(route('orders.status', $order), ['status' => 'confirmed'])->assertServerError();
        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertDatabaseCount('promotion_participants', 0);
        $this->assertSame(0, AuditEvent::where('event', 'order.status_changed')->count());
    }
}
