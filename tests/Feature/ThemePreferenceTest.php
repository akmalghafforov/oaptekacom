<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['pharmacy', 'light'])]
    #[TestWith(['pharmacy', 'dark'])]
    #[TestWith(['wholesaler', 'light'])]
    #[TestWith(['wholesaler', 'dark'])]
    #[TestWith(['admin', 'light'])]
    #[TestWith(['admin', 'dark'])]
    public function test_profile_renders_the_saved_theme_for_each_role(string $role, string $theme): void
    {
        $user = User::factory()->{$role}()->create(['theme' => $theme]);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertSeeHtml('<html lang="ru" data-theme="'.$theme.'">');
        $response->assertSeeHtml('<option value="'.$theme.'" selected>');
        $response->assertDontSeeHtml('<option value="'.($theme === 'dark' ? 'light' : 'dark').'" selected>');
    }

    #[TestWith(['pharmacy', 'catalog', 'light'])]
    #[TestWith(['pharmacy', 'catalog', 'dark'])]
    #[TestWith(['wholesaler', 'price-list-imports.index', 'light'])]
    #[TestWith(['wholesaler', 'price-list-imports.index', 'dark'])]
    #[TestWith(['admin', 'admin.users', 'light'])]
    #[TestWith(['admin', 'admin.users', 'dark'])]
    public function test_application_screens_render_the_account_theme(string $role, string $route, string $theme): void
    {
        $user = User::factory()->{$role}()->create(['theme' => $theme, 'two_factor_confirmed_at' => now()]);
        Subscription::factory()->for($user)->create();

        $this->actingAs($user)->get(route($route))
            ->assertSeeHtml('<html lang="ru" data-theme="'.$theme.'">');
    }

    public function test_dark_administration_pagination_keeps_page_links_and_current_page(): void
    {
        $admin = User::factory()->admin()->create(['theme' => 'dark', 'two_factor_confirmed_at' => now()]);
        User::factory()->admin()->count(30)->create();

        $this->actingAs($admin)->get(route('admin.users', ['page' => 2]))
            ->assertSeeHtml('<html lang="ru" data-theme="dark">')
            ->assertSeeHtml('aria-current="page"')
            ->assertSeeHtml('href="'.route('admin.users', ['page' => 1]).'"')
            ->assertSeeHtml('aria-label="'.__('Pagination Navigation').'"');
    }

    public function test_new_accounts_default_to_light(): void
    {
        $user = User::factory()->pharmacy()->create();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertSeeHtml('<html lang="ru" data-theme="light">');
    }

    #[TestWith(['pharmacy', 'dark', 'light'])]
    #[TestWith(['pharmacy', 'light', 'dark'])]
    #[TestWith(['wholesaler', 'dark', 'light'])]
    #[TestWith(['wholesaler', 'light', 'dark'])]
    #[TestWith(['admin', 'dark', 'light'])]
    #[TestWith(['admin', 'light', 'dark'])]
    public function test_saving_a_theme_applies_it_after_redirect_and_on_later_visits(string $role, string $previousTheme, string $theme): void
    {
        $user = User::factory()->{$role}()->create(['theme' => $previousTheme]);

        $this->actingAs($user)->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'theme' => $theme])
            ->assertRedirectToRoute('profile.edit')
            ->assertSessionHas('success', 'Профиль сохранён.');

        $this->assertSame($theme, $user->fresh()->theme);
        $audit = AuditEvent::query()->where('event', 'profile.updated')->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame($previousTheme, $audit->before['theme']);
        $this->assertSame($theme, $audit->after['theme']);
        $this->get(route('profile.edit'))->assertSeeHtml('<html lang="ru" data-theme="'.$theme.'">');
        $this->actingAs($user->fresh())->get(route('subscription.create'))
            ->assertSeeHtml('<html lang="ru" data-theme="'.$theme.'">');
    }

    #[TestWith(['pharmacy'])]
    #[TestWith(['wholesaler'])]
    #[TestWith(['admin'])]
    public function test_invalid_theme_is_rejected_without_changing_the_saved_preference(string $role): void
    {
        $user = User::factory()->{$role}()->create(['theme' => 'dark']);

        $this->actingAs($user)->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'theme' => 'system'])
            ->assertRedirectToRoute('profile.edit')
            ->assertSessionHasErrors('theme');

        $this->assertSame('dark', $user->fresh()->theme);
        $this->assertDatabaseCount('audit_events', 0);
        $this->get(route('profile.edit'))->assertSeeHtml('<html lang="ru" data-theme="dark">');
    }

    public function test_profile_preserves_the_submitted_theme_after_other_validation_errors(): void
    {
        $user = User::factory()->pharmacy()->create(['theme' => 'light']);

        $this->actingAs($user)->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => '', 'theme' => 'dark'])
            ->assertRedirectToRoute('profile.edit')
            ->assertSessionHasErrors('name');

        $this->get(route('profile.edit'))
            ->assertSeeHtml('<html lang="ru" data-theme="light">')
            ->assertSeeHtml('<option value="dark" selected>')
            ->assertDontSeeHtml('<option value="light" selected>');
        $this->assertSame('light', $user->fresh()->theme);
    }

    public function test_accounts_in_the_same_organization_use_their_own_preferences(): void
    {
        $darkUser = User::factory()->pharmacy()->create(['theme' => 'dark']);
        $lightUser = User::factory()->pharmacy($darkUser->organization)->create(['theme' => 'light']);

        $this->actingAs($darkUser)->get(route('profile.edit'))->assertSeeHtml('data-theme="dark"');
        $this->actingAs($lightUser)->get(route('profile.edit'))->assertSeeHtml('data-theme="light"');
        $this->actingAs($darkUser->fresh())->get(route('profile.edit'))->assertSeeHtml('data-theme="dark"');
    }

    #[TestWith(['pharmacy', 'light', 'dark'])]
    #[TestWith(['wholesaler', 'dark', 'light'])]
    #[TestWith(['admin', 'light', 'dark'])]
    public function test_account_menu_saves_only_the_theme_and_returns_to_the_current_page(string $role, string $previousTheme, string $theme): void
    {
        $user = User::factory()->{$role}()->create(['theme' => $previousTheme]);
        $otherUser = User::factory()->pharmacy()->create(['theme' => $previousTheme]);

        $this->actingAs($user)->from(route('subscription.create'))
            ->patch(route('profile.theme.update'), ['theme' => $theme, 'name' => 'Changed name', 'email' => 'changed@example.test', 'user_id' => $otherUser->id])
            ->assertRedirectToRoute('subscription.create')
            ->assertSessionHas('success', 'Тема сохранена.');

        $this->assertSame($theme, $user->fresh()->theme);
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame($previousTheme, $otherUser->fresh()->theme);
        $audit = AuditEvent::query()->where('event', 'profile.updated')->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame($previousTheme, $audit->before['theme']);
        $this->assertSame($theme, $audit->after['theme']);
        $this->actingAs($user->fresh())->get(route('subscription.create'))
            ->assertSeeHtml('<html lang="ru" data-theme="'.$theme.'">')
            ->assertSeeInOrder(['id="current-account-title"', 'name="theme"', 'id="account-links-title"'], false);
    }

    #[TestWith(['system'])]
    #[TestWith([null])]
    public function test_account_menu_rejects_invalid_or_missing_themes(?string $theme): void
    {
        $user = User::factory()->pharmacy()->create(['theme' => 'dark']);

        $this->actingAs($user)->from(route('profile.edit'))
            ->patch(route('profile.theme.update'), $theme === null ? [] : ['theme' => $theme])
            ->assertRedirectToRoute('profile.edit')
            ->assertSessionHasErrors('theme');

        $this->assertSame('dark', $user->fresh()->theme);
        $this->assertDatabaseCount('audit_events', 0);
    }

    #[TestWith(['pharmacy'])]
    #[TestWith(['wholesaler'])]
    #[TestWith(['admin'])]
    public function test_profile_can_be_saved_without_changing_the_theme(string $role): void
    {
        $user = User::factory()->{$role}()->create(['theme' => 'dark']);

        $this->actingAs($user)->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => 'Updated name', 'email' => $user->email])
            ->assertRedirectToRoute('profile.edit')
            ->assertSessionHasNoErrors();

        $this->assertSame('Updated name', $user->fresh()->name);
        $this->assertSame('dark', $user->fresh()->theme);
    }

    public function test_guests_cannot_save_a_theme_from_the_account_menu(): void
    {
        $this->patch(route('profile.theme.update'), ['theme' => 'dark'])
            ->assertRedirectToRoute('login');
    }

    #[TestWith(['login'])]
    #[TestWith(['register'])]
    #[TestWith(['admin.login'])]
    #[TestWith(['provider.login'])]
    #[TestWith(['provider.register'])]
    public function test_guest_authentication_pages_render_light(string $route): void
    {
        $this->get(route($route))
            ->assertSeeHtml('<html lang="ru" data-theme="light">')
            ->assertDontSeeHtml('data-theme="dark"');
    }

    public function test_guests_cannot_save_a_theme(): void
    {
        $this->patch(route('profile.update'), ['name' => 'Guest', 'theme' => 'dark'])
            ->assertRedirectToRoute('login');
    }
}
