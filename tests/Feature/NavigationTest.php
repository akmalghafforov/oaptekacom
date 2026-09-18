<?php

namespace Tests\Feature;

use App\Enums\TradeMode;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pharmacy_navigation_keeps_buying_destinations_primary_and_moves_account_actions_to_drawer(): void
    {
        $user = User::factory()->pharmacy(Organization::factory()->pharmacy()->create())->create(['name' => 'Аптека Навигация']);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response
            ->assertSeeText(['Поиск', 'Корзина', 'Партнёры', 'Вопросы', 'Меню аккаунта', 'Обзор', 'Заказы', 'Профиль', 'Мой тариф', 'Выйти', 'Аптека Навигация'])
            ->assertDontSeeText(['Операционный центр', 'Пользователи', 'Подписки', 'Модули'])
            ->assertSeeHtml(['data-primary-nav', 'data-primary-link="catalog"', 'data-primary-link="cart"', 'data-primary-link="partners"', 'data-primary-placeholder="questions"', 'aria-controls="account-drawer"', 'data-account-drawer="data-account-drawer"'])
            ->assertSeeHtml(['data-customer-mobile-nav', 'data-mobile-nav-link="catalog"', 'data-mobile-nav-link="cart"', 'data-mobile-nav-link="orders"', 'data-mobile-nav-link="account"'])
            ->assertDontSeeHtml(['data-primary-link="dashboard"', 'data-primary-link="orders.index"', 'data-primary-link="profile.edit"', 'data-primary-link="subscription.create"'])
            ->assertSeeHtml(['data-account-link="dashboard"', 'data-account-link="orders.index"', 'data-account-link="profile.edit"', 'data-account-link="subscription.create"', 'data-account-logout="data-account-logout"'])
            ->assertSeeHtml(['href="'.route('dashboard').'"', 'href="'.route('catalog').'"', 'href="'.route('cart').'"', 'href="'.route('orders.index').'"'])
            ->assertDontSeeHtml(['href="'.route('admin.index').'"', 'href="'.route('admin.users').'"', 'href="'.route('admin.subscriptions.index').'"']);
    }

    public function test_customer_catalog_renders_mobile_fixed_header_hook(): void
    {
        $user = User::factory()->pharmacy(Organization::factory()->pharmacy()->create())->create();
        Subscription::factory()->for($user)->create();

        $this->actingAs($user)->get(route('catalog'))
            ->assertSee('data-app-header', false)
            ->assertSee('data-search-panel', false)
            ->assertSee('data-customer-mobile-nav', false)
            ->assertSee('data-mobile-nav-link="partners"', false)
            ->assertSee('data-mobile-nav-placeholder="questions"', false)
            ->assertDontSee('data-mobile-nav-link="cart"', false)
            ->assertDontSee('data-mobile-nav-link="orders"', false)
            ->assertDontSee('data-mobile-nav-link="account"', false);
    }

    public function test_pharmacy_account_drawer_escapes_account_names(): void
    {
        $organization = Organization::factory()->pharmacy()->create(['name' => '<script>organization</script>']);
        $user = User::factory()->pharmacy($organization)->create(['name' => '<script>account</script>']);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response
            ->assertSee('&lt;script&gt;organization&lt;/script&gt;', false)
            ->assertSee('&lt;script&gt;account&lt;/script&gt;', false)
            ->assertDontSee('<script>organization</script>', false)
            ->assertDontSee('<script>account</script>', false);
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
        $response->assertDontSeeHtml('data-customer-mobile-nav');
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
