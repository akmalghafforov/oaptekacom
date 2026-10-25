<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function documents(): array
    {
        return [
            ['legal.privacy', 'ru', 'Политика конфиденциальности'],
            ['legal.privacy', 'tg', 'Сиёсати махфият'],
            ['legal.terms', 'ru', 'Условия использования'],
            ['legal.terms', 'tg', 'Шартҳои истифода'],
        ];
    }

    #[DataProvider('documents')]
    public function test_guests_can_read_each_document_and_switch_languages(string $route, string $locale, string $title): void
    {
        $applicationLocale = app()->getLocale();

        $this->get(route($route, ['locale' => $locale]))
            ->assertOk()
            ->assertSeeText($title)
            ->assertSeeHtml('lang="'.$locale.'"')
            ->assertSeeHtml('href="'.route($route, ['locale' => 'ru']).'"')
            ->assertSeeHtml('href="'.route($route, ['locale' => 'tg']).'"')
            ->assertSeeHtml('href="'.route($route === 'legal.privacy' ? 'legal.terms' : 'legal.privacy', ['locale' => $locale]).'"');
        $this->assertSame($applicationLocale, app()->getLocale());
    }

    public function test_documents_default_to_russian_and_reject_unsupported_locales(): void
    {
        foreach (['legal.privacy', 'legal.terms'] as $route) {
            $this->get(route($route))->assertOk()->assertSeeHtml('<html lang="ru"');
            $this->get(route($route, ['locale' => 'en']))->assertNotFound();
        }
    }

    public static function accountStates(): array
    {
        $cases = [];
        foreach ([false, true] as $modesEnabled) {
            foreach (['normal', 'blocked-user', 'blocked-organization', 'expired', 'admin-two-factor'] as $state) {
                $cases[] = [$modesEnabled, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('accountStates')]
    public function test_legal_documents_remain_accessible_for_restricted_accounts(bool $modesEnabled, string $state): void
    {
        config(['account-modes.enabled' => $modesEnabled, 'auth.admin_two_factor_enabled' => true]);
        $organization = Organization::factory()->pharmacy()->create(['status' => $state === 'blocked-organization' ? 'blocked' : 'active']);
        $user = $state === 'admin-two-factor'
            ? User::factory()->admin()->create(['two_factor_confirmed_at' => null])
            : User::factory()->pharmacy($organization)->create(['is_blocked' => $state === 'blocked-user']);
        if ($state === 'expired') {
            Subscription::factory()->for($user)->create(['starts_on' => now()->subMonth(), 'ends_on' => now()->subDay(), 'status' => 'expired']);
        }
        $this->actingAs($user);

        foreach (['legal.privacy', 'legal.terms'] as $route) {
            foreach (['ru', 'tg'] as $locale) {
                $this->get(route($route, ['locale' => $locale]))->assertOk()->assertSeeHtml('<footer');
            }
        }
    }

    public function test_documents_remain_drafts_until_publication_and_required_details_are_complete(): void
    {
        config(['legal.published' => true]);
        foreach (['legal.privacy', 'legal.terms'] as $route) {
            $this->get(route($route))->assertSeeText('Проект.')->assertSeeText('Ещё не вступил в силу');
        }
        config([
            'legal.legal_name' => 'Тестовый оператор',
            'legal.postal_address' => 'Тестовый адрес',
            'legal.privacy_email' => 'privacy@example.test',
            'legal.hosting_country' => 'Таджикистан',
            'legal.document_version' => '1.0',
            'legal.effective_date' => '2026-11-01',
        ]);
        foreach (['legal.privacy', 'legal.terms'] as $route) {
            $this->get(route($route))->assertDontSeeText('Проект.')->assertSeeText(['Тестовый оператор', '2026-11-01', 'privacy@example.test']);
            $this->get(route($route, ['locale' => 'tg']))->assertDontSeeText('Лоиҳа.')->assertSeeText('2026-11-01');
        }
        foreach (['legal_name', 'postal_address', 'privacy_email', 'hosting_country', 'document_version', 'effective_date'] as $field) {
            $original = config('legal.'.$field);
            config(['legal.'.$field => '  ']);
            $this->get(route('legal.privacy'))->assertSeeText('Проект.');
            config(['legal.'.$field => $original]);
        }
        config(['legal.published' => false]);
        $this->get(route('legal.terms'))->assertSeeText('Проект.');
        $this->get(route('legal.terms', ['locale' => 'tg']))->assertSeeText('Лоиҳа.');
    }

    public function test_configured_operator_details_are_escaped(): void
    {
        config(['legal.legal_name' => '<script>alert(1)</script>']);

        $this->get(route('legal.privacy'))->assertSeeText('<script>alert(1)</script>')->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_registration_and_shared_footer_offer_neutral_legal_links(): void
    {
        foreach (['register', 'provider.register', 'login'] as $route) {
            $response = $this->get(route($route));
            $response->assertSeeHtml('<footer')
                ->assertSeeHtml('href="'.route('legal.privacy').'"')
                ->assertSeeHtml('href="'.route('legal.terms').'"');
            if ($route !== 'login') {
                $response->assertSeeText('Правовые документы для ознакомления:')->assertDontSeeText('Я согласен');
            }
        }
    }

    public function test_footer_shows_configured_contacts_with_normalized_destinations(): void
    {
        config([
            'contact.email' => '  support@example.test  ',
            'contact.phone' => ' +992 (900) 12-34-56 ',
            'contact.whatsapp_phone' => '992 (901) 23-45-67',
        ]);

        $this->get(route('login'))
            ->assertSeeText('Контакты')
            ->assertSeeText('support@example.test')
            ->assertSeeText('Телефон: +992 (900) 12-34-56')
            ->assertSeeText('WhatsApp: 992 (901) 23-45-67')
            ->assertSeeHtml('href="mailto:support@example.test"')
            ->assertSeeHtml('href="tel:+992900123456"')
            ->assertSeeHtml('href="https://wa.me/992901234567"');
    }

    public static function partialContacts(): array
    {
        return [
            'email only' => ['email', 'support@example.test', 'mailto:support@example.test'],
            'phone only' => ['phone', '900 12-34-56', 'tel:900123456'],
            'WhatsApp only' => ['whatsapp_phone', '+992 901 23-45-67', 'https://wa.me/992901234567'],
        ];
    }

    #[DataProvider('partialContacts')]
    public function test_footer_shows_individually_configured_contacts(string $key, string $value, string $href): void
    {
        config(['contact' => ['email' => null, 'phone' => null, 'whatsapp_phone' => null]]);
        config(['contact.'.$key => $value]);

        $this->get(route('login'))
            ->assertSeeHtml('aria-label="Контакты"')
            ->assertSeeHtml('href="'.$href.'"');
    }

    public static function invalidContacts(): array
    {
        return [
            'missing' => [null, null, null],
            'blank' => ['  ', '  ', '  '],
            'unsafe schemes' => ['javascript:alert(1)', 'javascript:123456789', 'https://wa.me/992900123456'],
            'invalid formatting' => ['invalid-email', '+992900123456 ext 12', '++992900123456'],
            'invalid lengths' => ['support@example', '123', '1234567890123456'],
            'zero prefix' => ['@example.test', '000000000', '0123456789'],
            'markup' => ['<script>alert(1)</script>', '"><script>123456789</script>', '123456789?text=hello'],
        ];
    }

    #[DataProvider('invalidContacts')]
    public function test_footer_hides_contacts_group_for_missing_or_invalid_values(?string $email, ?string $phone, ?string $whatsapp): void
    {
        config(['contact.email' => $email, 'contact.phone' => $phone, 'contact.whatsapp_phone' => $whatsapp]);

        $this->get(route('login'))
            ->assertDontSeeHtml('aria-label="Контакты"')
            ->assertDontSeeHtml('href="mailto:')
            ->assertDontSeeHtml('href="tel:')
            ->assertDontSeeHtml('href="https://wa.me/')
            ->assertSeeHtml('href="'.route('legal.privacy').'"')
            ->assertSeeHtml('href="'.route('legal.terms').'"');
    }

    public function test_authenticated_pharmacy_footer_keeps_legal_contacts_and_navigation_clearance(): void
    {
        config(['contact.email' => str_repeat('a', 60).'@example.test', 'contact.phone' => 'invalid', 'contact.whatsapp_phone' => null]);
        $user = User::factory()->pharmacy()->create();

        $this->actingAs($user)->get(route('legal.privacy'))
            ->assertSeeHtml('href="'.route('legal.privacy').'"')
            ->assertSeeHtml('href="'.route('legal.terms').'"')
            ->assertSeeHtml('href="mailto:'.str_repeat('a', 60).'@example.test"')
            ->assertDontSeeHtml('href="tel:')
            ->assertSeeHtml('pb-24 md:pb-5');
    }

    public function test_legal_pages_follow_authenticated_theme_preferences(): void
    {
        $user = User::factory()->pharmacy()->create(['theme' => 'dark']);
        $this->actingAs($user)->get(route('legal.privacy'))->assertSeeHtml('data-theme="dark"');
        $user->update(['theme' => 'light']);
        $this->get(route('legal.terms'))->assertSeeHtml('data-theme="light"');
    }
}
