<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSubscriptionMonitoringTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('phoneSearches')]
    public function test_searches_account_phones_across_formatting_and_prefixes(string $storedPhone, string $search): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create(['phone' => $storedPhone]);
        $match = Subscription::factory()->for($account)->create();
        $otherOrganization = Organization::factory()->pharmacy()->create(['phone' => '+992901234567']);
        Subscription::factory()->for(User::factory()->pharmacy($otherOrganization)->create(['phone' => '+992888888888']))->create();
        Subscription::factory()->for(User::factory()->pharmacy()->create(['phone' => null]))->create();

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', ['phone' => $search]));

        $this->assertSubscriptionIds($response, [$match->id]);
        $response->assertSee($storedPhone);
    }

    /** @return array<string, array{string, string}> */
    public static function phoneSearches(): array
    {
        return [
            'international stored' => ['+992901234567', '901234567'],
            'national stored' => ['901234567', '+992 (90) 123-45-67'],
            'international access prefix stored' => ['00992 (90) 123.45.67', '992901234567'],
            'international access prefix searched' => ['+992 (90) 123-45-67', '00992901234567'],
            'common whitespace' => ["+992\t(90)\u{00a0}123\u{202f}45-67", '90 123 45 67'],
            'partial explicit country prefix' => ['901234567', '+992 (90)'],
            'partial country prefix' => ['901234567', '99290'],
            'partial international access prefix' => ['901234567', '00992 (90)'],
            'partial national digits' => ['+992 (90) 123-45-67', '123.45'],
            'partial digits matching a national prefix' => ['+992992123456', '992'],
            'local number begins with country digits' => ['+992992123456', '992123456'],
        ];
    }

    public function test_combines_phone_status_inclusive_dates_and_expiration_window_with_and(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create(['phone' => '+992901234567']);
        $factory = Subscription::factory()->for($account)->state(['starts_on' => '2026-09-01']);
        $first = (clone $factory)->create(['ends_on' => '2026-09-12']);
        $last = (clone $factory)->create(['ends_on' => '2026-09-19']);
        (clone $factory)->create(['ends_on' => '2026-09-20']);
        (clone $factory)->create(['ends_on' => '2026-09-18', 'status' => SubscriptionStatus::Cancelled]);
        Subscription::factory()->for(User::factory()->pharmacy()->create(['phone' => '+992888888888']))->create(['ends_on' => '2026-09-18']);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', [
            'phone' => '90123', 'status' => 'active', 'ends_from' => '2026-09-12',
            'ends_to' => '2026-09-19', 'expires_in' => '7', 'sort' => 'ends_on', 'direction' => 'asc',
        ]));

        $this->assertSubscriptionIds($response, [$first->id, $last->id]);
        $response->assertSee('Истекает сегодня')->assertSee('Истекает в течение 7 дней');
    }

    public function test_counts_overall_records_with_overlapping_inclusive_dushanbe_windows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-11 19:01:00', 'UTC'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create(['phone' => '+992901234567']);
        $factory = Subscription::factory()->for($account)->state(['starts_on' => '2026-09-12']);
        foreach (['2026-09-12', '2026-09-19', '2026-09-20', '2026-10-12', '2026-10-13'] as $endsOn) {
            (clone $factory)->create(['ends_on' => $endsOn]);
        }
        $overdue = (clone $factory)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-11']);
        (clone $factory)->create(['status' => SubscriptionStatus::Expired, 'ends_on' => '2026-10-01']);
        (clone $factory)->create(['starts_on' => '2026-09-13', 'ends_on' => '2026-09-19']);
        (clone $factory)->create(['plan' => SubscriptionPlan::Free, 'ends_on' => '2026-09-19']);
        (clone $factory)->create(['starts_on' => null, 'ends_on' => null]);
        foreach ([SubscriptionStatus::Cancelled, SubscriptionStatus::Rejected, SubscriptionStatus::Superseded] as $status) {
            (clone $factory)->create(['status' => $status, 'ends_on' => '2026-09-11']);
        }

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', ['phone' => '000000']));

        $response->assertViewHas('statistics', ['active' => 5, 'expired' => 2, 7 => 2, 30 => 4])
            ->assertSee('Общие итоги по всем подпискам')
            ->assertSee('12.09.2026');
        $this->assertSubscriptionIds($response, []);
        $this->assertSame(SubscriptionStatus::Active, $overdue->fresh()->status);
        $this->assertSame('2026-09-12', $response->viewData('today')->toDateString());
        $this->assertTrue($account->hasActiveSubscription());

        $unfiltered = $this->get(route('admin.subscriptions.index'));
        $unfiltered->assertViewHas('statistics', ['active' => 5, 'expired' => 2, 7 => 2, 30 => 4]);
        foreach ([['status' => 'active'], ['status' => 'expired'], ['expires_in' => '7'], ['expires_in' => '30']] as $parameters) {
            $unfiltered->assertSeeHtml('href="'.e(route('admin.subscriptions.index', $parameters)).'"');
            $filtered = $this->get(route('admin.subscriptions.index', $parameters));
            $this->assertSame($unfiltered->viewData('statistics')[array_values($parameters)[0]], $filtered->viewData('subscriptions')->total());
        }
    }

    public function test_dushanbe_midnight_changes_current_access_and_effective_expiration_without_mutation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-11 18:59:00', 'UTC'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create();
        $subscription = Subscription::factory()->for($account)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-11']);

        $before = $this->actingAs($admin)->get(route('admin.subscriptions.index', ['status' => 'active']));

        $this->assertSubscriptionIds($before, [$subscription->id]);
        $before->assertSee('Истекает сегодня');
        $this->assertTrue($account->hasActiveSubscription());

        $this->travelTo(CarbonImmutable::parse('2026-09-11 19:00:00', 'UTC'));
        $after = $this->get(route('admin.subscriptions.index', ['status' => 'expired']));

        $this->assertSubscriptionIds($after, [$subscription->id]);
        $after->assertSee('Истекла')->assertDontSee('Истекает сегодня');
        $this->assertFalse($account->hasActiveSubscription());
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertSubscriptionIds($this->get(route('admin.subscriptions.index', ['status' => 'active'])), []);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_visible_errors_and_preserve_input(array $filters, string $field, string $message): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', $filters));

        $response->assertRedirect(route('admin.subscriptions.index'))->assertSessionHasErrors([$field => $message]);
        foreach ($filters as $key => $value) {
            if (is_string($value)) {
                $response->assertSessionHasInput($key, $value);
            }
        }
        $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('admin.subscriptions.index'))->assertOk()->assertSee($message);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidFilters(): array
    {
        return [
            'phone without digits' => [['phone' => 'abc'], 'phone', 'Введите хотя бы одну цифру телефона.'],
            'phone punctuation' => [['phone' => '+()-'], 'phone', 'Введите хотя бы одну цифру телефона.'],
            'phone array' => [['phone' => ['90123']], 'phone', 'Введите телефон текстом.'],
            'impossible date' => [['ends_from' => '2026-02-30'], 'ends_from', 'Укажите начальную дату в формате ГГГГ-ММ-ДД.'],
            'invalid end date' => [['ends_to' => 'yesterday'], 'ends_to', 'Укажите конечную дату в формате ГГГГ-ММ-ДД.'],
            'reversed range' => [['ends_from' => '2026-09-13', 'ends_to' => '2026-09-12'], 'ends_to', 'Конечная дата должна быть не раньше начальной.'],
            'invalid window' => [['expires_in' => '15'], 'expires_in', 'Выберите период 7 или 30 дней.'],
            'invalid status' => [['status' => 'deleted'], 'status', 'Выберите статус из списка.'],
            'unsafe sort' => [['sort' => 'id desc; drop table users'], 'sort', 'Выберите доступный столбец сортировки.'],
            'unsafe direction' => [['direction' => 'desc; drop table users'], 'direction', 'Выберите направление сортировки.'],
        ];
    }

    #[DataProvider('sortColumns')]
    public function test_sorts_both_directions_with_missing_values_last(string $sort): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $firstAccount = User::factory()->pharmacy(Organization::factory()->pharmacy()->create(['name' => 'Аптека А']))->create(['name' => 'Анна', 'phone' => '+992901234567']);
        $lastAccount = User::factory()->pharmacy(Organization::factory()->pharmacy()->create(['name' => 'Аптека Я']))->create(['name' => 'Яна', 'phone' => '00992991234567']);
        $first = Subscription::factory()->for($firstAccount)->create(['plan' => SubscriptionPlan::Base, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'total_price' => '9.00']);
        $last = Subscription::factory()->for($lastAccount)->create(['plan' => SubscriptionPlan::Premium, 'starts_on' => '2026-09-02', 'ends_on' => '2026-10-01', 'total_price' => '100.00', 'status' => SubscriptionStatus::Pending]);
        $missing = Subscription::factory()->for(User::factory()->create(['name' => '', 'phone' => null, 'organization_id' => null]))->create(['starts_on' => null, 'ends_on' => null]);
        $hasMissing = in_array($sort, ['pharmacy', 'account', 'phone', 'starts_on', 'ends_on'], true);
        if (! $hasMissing) {
            $missing->delete();
        }

        $ascending = $this->actingAs($admin)->get(route('admin.subscriptions.index', ['sort' => $sort, 'direction' => 'asc']));
        $descending = $this->get(route('admin.subscriptions.index', ['sort' => $sort, 'direction' => 'desc']));

        $this->assertSubscriptionIds($ascending, $hasMissing ? [$first->id, $last->id, $missing->id] : [$first->id, $last->id]);
        $this->assertSubscriptionIds($descending, $hasMissing ? [$last->id, $first->id, $missing->id] : [$last->id, $first->id]);
        $ascending->assertSeeHtml('aria-sort="ascending"')->assertSee('по возрастанию ↑');
        $descending->assertSeeHtml('aria-sort="descending"')->assertSee('по убыванию ↓');
    }

    /** @return array<string, array{string}> */
    public static function sortColumns(): array
    {
        return [
            'pharmacy' => ['pharmacy'], 'account' => ['account'], 'phone' => ['phone'], 'plan' => ['plan'],
            'status' => ['status'], 'start date' => ['starts_on'], 'end date' => ['ends_on'], 'numeric price' => ['total_price'],
        ];
    }

    public function test_sorts_effective_statuses_by_russian_labels_and_id_ties(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $factory = Subscription::factory()->for(User::factory()->pharmacy()->create());
        $pending = (clone $factory)->create(['status' => SubscriptionStatus::Pending]);
        $rejected = (clone $factory)->create(['status' => SubscriptionStatus::Rejected]);
        $cancelled = (clone $factory)->create(['status' => SubscriptionStatus::Cancelled]);
        $expired = (clone $factory)->create(['status' => SubscriptionStatus::Expired]);
        $overdue = (clone $factory)->create(['ends_on' => '2026-09-11']);
        $superseded = (clone $factory)->create(['status' => SubscriptionStatus::Superseded]);
        $active = (clone $factory)->create();

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', ['sort' => 'status', 'direction' => 'asc']));

        $this->assertSubscriptionIds($response, [$active->id, $superseded->id, $overdue->id, $expired->id, $pending->id, $rejected->id, $cancelled->id]);
        $this->assertSubscriptionIds($this->get(route('admin.subscriptions.index', ['sort' => 'status', 'direction' => 'desc'])), [$cancelled->id, $rejected->id, $pending->id, $overdue->id, $expired->id, $superseded->id, $active->id]);
    }

    public function test_pagination_preserves_filters_and_sorting_while_filter_and_sort_links_restart(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create(['phone' => '+992901234567']);
        $records = Subscription::factory()->count(31)->for($account)->create(['ends_on' => '2026-09-19', 'created_at' => '2026-09-12 00:00:00']);
        $filters = ['phone' => '90123', 'status' => 'active', 'ends_from' => '2026-09-12', 'ends_to' => '2026-09-19', 'expires_in' => '7', 'sort' => 'ends_on', 'direction' => 'asc'];

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index', $filters + ['page' => 2]));

        $this->assertSubscriptionIds($response, [$records->first()->id]);
        $this->assertSame(30, $response->viewData('subscriptions')->perPage());
        parse_str(parse_url($response->viewData('subscriptions')->previousPageUrl(), PHP_URL_QUERY), $previousQuery);
        $this->assertSame($filters + ['page' => '1'], $previousQuery);
        $response->assertSeeHtml('href="'.e(route('admin.subscriptions.index', array_merge($filters, ['sort' => 'phone', 'direction' => 'asc']))).'"');
        $response->assertSeeHtml('action="'.route('admin.subscriptions.index').'"');
        $response->assertDontSeeHtml('name="page"');
        $response->assertSeeHtml('href="'.route('admin.subscriptions.index').'"');

        $reset = $this->get(route('admin.subscriptions.index'));

        $this->assertSame(31, $reset->viewData('subscriptions')->total());
        $this->assertSame($records->last()->id, $reset->viewData('subscriptions')->first()->id);
        $reset->assertSee('сначала новые (по умолчанию)')->assertSeeHtml('name="phone" type="text" value=""');
    }

    public function test_pending_undated_records_keep_payment_and_cancellation_actions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create(['name' => '<script>alert(1)</script>', 'phone' => null]);
        Subscription::factory()->for($account)->create(['status' => SubscriptionStatus::Pending, 'starts_on' => null, 'ends_on' => null]);
        $active = Subscription::factory()->for($account)->create();

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertSee('После проверки оплаты')->assertSee('Проверить оплату')->assertSee('—')
            ->assertSeeHtml('action="'.route('admin.subscriptions.cancel', $active).'"')
            ->assertSee('<script>alert(1)</script>')->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSeeHtml('name="user_id"');
    }

    /** @param list<int> $ids */
    private function assertSubscriptionIds(TestResponse $response, array $ids): void
    {
        $response->assertOk();
        $this->assertSame($ids, $response->viewData('subscriptions')->getCollection()->modelKeys());
    }
}
