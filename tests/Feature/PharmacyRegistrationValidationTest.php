<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PharmacyRegistrationValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith([null, 'Выберите город аптеки.'])]
    #[TestWith(['   ', 'Выберите город аптеки.'])]
    #[TestWith(['Москва', 'Выберите город аптеки из списка.'])]
    public function test_invalid_city_does_not_create_an_application(?string $city, string $message): void
    {
        Http::fake();
        $data = ['pharmacy_name' => 'Аптека Тест'];
        if ($city !== null) {
            $data['city'] = $city;
        }
        $this->withSession(['phone_otp.registration' => ['phone' => '+992901234567']])
            ->from(route('register.details.form'))->post(route('register.details.store'), $data)
            ->assertSessionHasErrors(['city' => $message])->assertSessionHasInput('pharmacy_name', 'Аптека Тест');
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('one_time_passwords', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['123', 'Название аптеки должно содержать хотя бы одну букву.'])]
    #[TestWith(['   ', 'Укажите название аптеки.'])]
    #[TestWith(['Аптека!', 'Название аптеки может содержать только буквы, цифры и пробелы.'])]
    #[TestWith(['Аптека +', 'Название аптеки может содержать только буквы, цифры и пробелы.'])]
    #[TestWith(['Аптека 💊', 'Название аптеки может содержать только буквы, цифры и пробелы.'])]
    #[TestWith(["Аптека\tТест", 'Название аптеки может содержать только буквы, цифры и пробелы.'])]
    #[TestWith(["Аптека\nТест", 'Название аптеки может содержать только буквы, цифры и пробелы.'])]
    public function test_invalid_name_preserves_city_and_creates_nothing(string $name, string $message): void
    {
        Http::fake();
        $this->withSession(['phone_otp.registration' => ['phone' => '+992901234567']])
            ->post(route('register.details.store'), ['pharmacy_name' => $name, 'city' => 'Худжанд'])
            ->assertSessionHasErrors(['pharmacy_name' => $message])->assertSessionHasInput('city', 'Худжанд');
        $this->get(route('register.details.form'))->assertSee('value="Худжанд" selected', false);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['Pharmacy'])]
    #[TestWith(['Аптека'])]
    #[TestWith(['Дорухонаи Ғ Ҷ Қ Ӯ Ҳ Ӣ'])]
    #[TestWith(['Аптека 123'])]
    public function test_valid_name_is_trimmed_without_changing_internal_spaces_or_case(string $name): void
    {
        config(['auth.development_otp_code' => '000000']);
        Http::fake();
        $this->withSession(['phone_otp.registration' => ['phone' => '+992901234567']])
            ->post(route('register.details.store'), ['pharmacy_name' => "\u{00a0}$name\u{00a0}", 'city' => ' Душанбе '])
            ->assertRedirectToRoute('register.otp.form');
        $this->assertDatabaseHas('organizations', ['name' => $name, 'city' => 'Душанбе']);
        Http::assertNothingSent();
    }

    #[TestWith(['pending'])]
    #[TestWith(['active'])]
    #[TestWith(['blocked'])]
    public function test_duplicate_cyrillic_names_are_rejected_across_statuses(string $status): void
    {
        Http::fake();
        Organization::factory()->pharmacy()->create(['name' => "\u{00a0}АПТЕКА ТЕСТ ", 'city' => ' ДУШАНБЕ ', 'status' => $status]);
        $this->withSession(['phone_otp.registration' => ['phone' => '+992901234567']])
            ->post(route('register.details.store'), ['pharmacy_name' => ' аптека тест ', 'city' => 'Душанбе'])
            ->assertSessionHasErrors(['pharmacy_name' => 'Аптека с таким названием уже зарегистрирована в выбранном городе.'])
            ->assertSessionHasInput('pharmacy_name', 'аптека тест');
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_other_cities_and_supplier_names_do_not_conflict_and_same_phone_can_retry(): void
    {
        config(['auth.development_otp_code' => '000000']);
        Organization::factory()->pharmacy()->create(['name' => 'Аптека  1', 'city' => 'Худжанд']);
        Organization::factory()->wholesaler()->create(['name' => 'Аптека  1', 'city' => 'Душанбе']);
        $this->withSession(['phone_otp.registration' => ['phone' => '+992901234567']]);
        foreach ([1, 2] as $attempt) {
            $this->post(route('register.details.store'), ['pharmacy_name' => 'Аптека  1', 'city' => 'Душанбе'])
                ->assertRedirectToRoute('register.otp.form');
        }
        $this->assertDatabaseCount('organizations', 3);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('organizations', ['name' => 'Аптека  1', 'city' => 'Душанбе', 'type' => 'pharmacy']);
    }

    #[TestWith([['wrong'], 'Название аптеки должно быть строкой.'])]
    #[TestWith([null, 'Укажите название аптеки.'])]
    public function test_non_string_name_is_rejected(mixed $name, string $message): void
    {
        Http::fake();
        $this->withSession(['phone_otp.registration' => ['phone' => '+992901234567']])
            ->post(route('register.details.store'), ['pharmacy_name' => $name, 'city' => 'Душанбе'])
            ->assertSessionHasErrors(['pharmacy_name' => $message]);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_name_longer_than_255_characters_is_rejected(): void
    {
        Http::fake();
        $this->withSession(['phone_otp.registration' => ['phone' => '+992901234567']])
            ->post(route('register.details.store'), ['pharmacy_name' => str_repeat('А', 256), 'city' => 'Душанбе'])
            ->assertSessionHasErrors(['pharmacy_name' => 'Название аптеки не должно превышать 255 символов.']);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_missing_registration_session_returns_not_found(): void
    {
        $this->post(route('register.details.store'), ['pharmacy_name' => 'Аптека', 'city' => 'Душанбе'])->assertNotFound();
    }

    public function test_lock_timeout_returns_retry_feedback_without_creation(): void
    {
        Http::fake();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(5)->andThrow(new LockTimeoutException);
        $lock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->andReturn($lock);
        $this->withSession(['phone_otp.registration' => ['phone' => '+992901234567']])
            ->post(route('register.details.store'), ['pharmacy_name' => 'Аптека', 'city' => 'Душанбе'])
            ->assertSessionHasErrors(['pharmacy_name' => 'Не удалось отправить заявку. Попробуйте ещё раз.']);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }
}
