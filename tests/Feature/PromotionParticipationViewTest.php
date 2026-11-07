<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionContribution;
use App\Models\PromotionParticipant;
use App\Models\Subscription;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PromotionParticipationViewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function document(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }

    private function capture(TestResponse $response, string $name): void
    {
        $directory = getenv('PROMOTION_UI_REVIEW_DIR');
        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }
    }

    public function test_failed_participation_fields_restore_only_their_form_and_connect_prize_errors_to_controls(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->withParticipation()->for($supplier->organization)->create(['description' => 'Сохранённое описание']);
        $url = route('promotions.edit', $promotion);
        $this->actingAs($supplier)->from($url)->patch(route('promotions.participation-settings', $promotion), [
            '_promotion_form' => 'participation', 'participation_enabled' => '1', 'purchase_scope' => 'promotion', 'condition_match' => 'any',
            'minimum_total' => '0', 'description' => 'Чужое описание', 'prizes' => [['name' => '<script>alert(1)</script>', 'quantity' => 0]],
        ])->assertRedirect($url);
        $response = $this->get($url)->assertOk()->assertSee('Сохранённое описание')->assertDontSee('Чужое описание')->assertSee('Значение поля «Количество призов» должно быть не меньше 1.')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->capture($response, 'editor-errors');
        $document = $this->document($response);
        $this->assertSame('prize-0-quantity-error', $document->evaluate('string(//input[@id="prize-0-quantity"]/@aria-describedby)'));
        $this->assertSame('true', $document->evaluate('string(//input[@id="prize-0-quantity"]/@aria-invalid)'));
        $this->assertSame('0', $document->evaluate('string(//input[@id="minimum_total"]/@value)'));
        $this->assertSame('promotion', $document->evaluate('string(//select[@id="purchase_scope"]/option[@selected]/@value)'));
        $this->assertSame('any', $document->evaluate('string(//select[@id="condition_match"]/option[@selected]/@value)'));
        $this->assertSame(1, $document->query('//form[@data-promotion-form]//input[@name="_promotion_form"][@value="participation"]')->length);
    }

    #[TestWith(['light'])]
    #[TestWith(['dark'])]
    public function test_conditions_prizes_and_private_progress_render_in_both_themes(string $theme): void
    {
        $supplier = User::factory()->wholesaler()->create(['theme' => $theme]);
        $buyer = User::factory()->pharmacy()->create(['theme' => $theme]);
        Subscription::factory()->for($buyer)->create();
        $promotion = Promotion::factory()->withParticipation()->published()->for($supplier->organization)->create([
            'title' => 'Призы за покупки', 'minimum_order_value' => '50.00', 'minimum_order_count' => 1,
        ]);
        $order = Order::factory()->create(['buyer_organization_id' => $buyer->organization_id, 'supplier_organization_id' => $supplier->organization_id, 'status' => 'confirmed', 'total' => '123.45']);
        PromotionContribution::factory()->for($promotion)->create(['order_id' => $order->id, 'pharmacy_organization_id' => $buyer->organization_id, 'amount' => '123.45']);
        PromotionParticipant::factory()->for($promotion)->create(['pharmacy_organization_id' => $buyer->organization_id, 'qualifying_total' => '123.45', 'largest_order_value' => '123.45']);
        $show = $this->actingAs($buyer)->get(route('promotions.show', $promotion))->assertOk()->assertSee('data-theme="'.$theme.'"', false)->assertSee('Подарочный набор')->assertSee('Ваша аптека участвует')->assertSee('Общая сумма: 123.45');
        $this->capture($show, 'promotion-'.$theme);
        $mine = $this->get(route('promotions.participations'))->assertOk()->assertSee('Призы за покупки')->assertSee('Первая квалификация');
        $this->capture($mine, 'participations-'.$theme);
        $participants = $this->actingAs($supplier)->get(route('promotions.participants', $promotion))->assertOk()->assertSee('Заказ №'.$order->id)->assertSee('Условия выполнены');
        $this->capture($participants, 'participants-'.$theme);
        $editor = $this->get(route('promotions.edit', $promotion))->assertOk()->assertSee('Сроки зафиксированы');
        $this->capture($editor, 'editor-locked-'.$theme);
        $document = $this->document($editor);
        $this->assertSame(1, $document->query('//form[contains(@action,"participation-settings")]/fieldset[@disabled]')->length);
        $this->assertSame(0, $document->query('//form[contains(@action,"participation-settings")][@data-promotion-form]')->length);
    }

    public function test_participants_are_paginated_and_supporting_orders_stay_on_their_pharmacy_row(): void
    {
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->withParticipation()->published()->for($supplier->organization)->create();
        PromotionParticipant::factory()->for($promotion)->count(31)->create();
        $this->actingAs($supplier)->get(route('promotions.participants', $promotion))->assertOk()->assertViewHas('participants', fn ($participants): bool => $participants->total() === 31 && $participants->count() === 30);
        $this->get(route('promotions.participants', [$promotion, 'page' => 2]))->assertOk()->assertViewHas('participants', fn ($participants): bool => $participants->total() === 31 && $participants->count() === 1);
    }
}
