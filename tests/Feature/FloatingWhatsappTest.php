<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FloatingWhatsappTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_pages_render_one_accessible_whatsapp_button(): void
    {
        config(['contact.whatsapp_phone' => ' +992 (901) 23-45-67 ']);

        foreach (['login', 'register', 'legal.privacy', 'legal.terms'] as $route) {
            $response = $this->get(route($route));

            $response->assertSeeHtml('href="https://wa.me/992901234567"')
                ->assertSeeHtml('target="_blank"')
                ->assertSeeHtml('rel="noopener noreferrer"')
                ->assertSeeHtml('aria-label="Вопросы — связаться в WhatsApp"');
            $this->assertSame(1, substr_count($response->getContent(), 'data-floating-whatsapp='));
        }
    }

    public static function roles(): array
    {
        return ['customer' => ['pharmacy'], 'supplier' => ['wholesaler'], 'admin' => ['admin']];
    }

    #[DataProvider('roles')]
    public function test_authenticated_pages_render_one_whatsapp_button(string $role): void
    {
        config(['contact.whatsapp_phone' => '+992901234567']);
        $user = User::factory()->{$role}()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertSeeHtml('href="https://wa.me/992901234567"');
        $this->assertSame(1, substr_count($response->getContent(), 'data-floating-whatsapp='));
    }

    public static function invalidPhones(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'letters' => ['invalid'],
            'url' => ['https://wa.me/992901234567'],
            'short' => ['123456'],
            'long' => ['1234567890123456'],
            'leading zero' => ['0123456789'],
            'line break' => ["992901234567\n123"],
        ];
    }

    #[DataProvider('invalidPhones')]
    public function test_hides_button_for_missing_or_invalid_numbers(?string $phone): void
    {
        config(['contact.whatsapp_phone' => $phone]);

        $this->get(route('login'))->assertDontSeeHtml('data-floating-whatsapp');
    }
}
