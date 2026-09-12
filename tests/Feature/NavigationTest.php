<?php

namespace Tests\Feature;

use App\Enums\TradeMode;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pharmacy_navigation_renders_buying_destinations_and_account_actions(): void
    {
        $user = User::factory()->pharmacy(Organization::factory()->pharmacy()->create())->create(['name' => 'Аптека Навигация']);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response
            ->assertSeeText(['Обзор', 'Каталог', 'Корзина', 'Заказы', 'Профиль', 'Выйти', 'Аптека Навигация'])
            ->assertDontSeeText(['Операционный центр', 'Пользователи', 'Подписки', 'Модули'])
            ->assertSeeHtml(['href="'.route('dashboard').'"', 'href="'.route('catalog').'"', 'href="'.route('cart').'"', 'href="'.route('orders.index').'"'])
            ->assertDontSeeHtml(['href="'.route('admin.index').'"', 'href="'.route('admin.users').'"', 'href="'.route('admin.subscriptions.index').'"']);
    }

    public function test_supplier_mode_provider_navigation_excludes_cart(): void
    {
        $user = User::factory()->wholesaler(Organization::factory()->wholesaler()->create())->create(['active_trade_mode' => TradeMode::Supplier]);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response
            ->assertSeeText(['Обзор', 'Каталог', 'Заказы'])
            ->assertDontSeeText(['Корзина', 'Операционный центр', 'Пользователи', 'Подписки', 'Модули'])
            ->assertSeeHtml(['href="'.route('dashboard').'"', 'href="'.route('catalog').'"', 'href="'.route('orders.index').'"'])
            ->assertDontSeeHtml('href="'.route('cart').'"');
    }

    public function test_buyer_mode_provider_navigation_includes_cart(): void
    {
        $user = User::factory()->wholesaler(Organization::factory()->wholesaler()->create())->create(['active_trade_mode' => TradeMode::Buyer]);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response
            ->assertSeeText(['Обзор', 'Каталог', 'Корзина', 'Заказы'])
            ->assertDontSeeText(['Операционный центр', 'Пользователи', 'Подписки', 'Модули'])
            ->assertSeeHtml('href="'.route('cart').'"');
    }

    public function test_admin_navigation_renders_administration_destinations_only(): void
    {
        $user = User::factory()->admin()->create(['name' => 'Администратор Навигация']);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response
            ->assertSeeText(['Операционный центр', 'Пользователи', 'Подписки', 'Профиль', 'Выйти', 'Администратор Навигация'])
            ->assertDontSeeText('Модули')
            ->assertDontSeeText(['Обзор', 'Каталог', 'Корзина', 'Заказы'])
            ->assertSeeHtml(['href="'.route('admin.index').'"', 'href="'.route('admin.users').'"', 'href="'.route('admin.subscriptions.index').'"'])
            ->assertDontSeeHtml(['href="'.route('catalog').'"', 'href="'.route('cart').'"', 'href="'.route('orders.index').'"']);
    }

    public function test_login_navigation_excludes_authorized_destinations(): void
    {
        $this->get(route('login'))
            ->assertSeeHtml(['href="'.route('login').'"', 'href="'.route('register').'"'])
            ->assertDontSeeHtml('href="'.route('catalog').'"');
    }

    public function test_registration_navigation_excludes_authorized_destinations(): void
    {
        $this->get(route('register'))
            ->assertSeeHtml(['href="'.route('login').'"', 'href="'.route('register').'"'])
            ->assertDontSeeHtml('href="'.route('catalog').'"');
    }
}
