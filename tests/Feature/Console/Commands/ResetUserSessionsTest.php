<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ResetUserSessionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    }

    public function test_reset_removes_all_devices_and_guest_flows_and_only_changes_remember_tokens(): void
    {
        $users = collect(['admin', 'pharmacy', 'wholesaler'])->map(fn (string $role) => User::factory()->{$role}()->create([
            'totp_secret' => 'secret', 'recovery_codes' => ['recovery-code'], 'two_factor_confirmed_at' => now(),
        ]));
        $original = $users->map(fn (User $user) => $user->fresh()->getRawOriginal());
        foreach ($users as $user) {
            $this->storeSession($user);
            $this->storeSession($user, true);
        }
        $this->storeSession();
        $this->storeSession(null, true);
        $assignments = DB::table('user_role_assignments')->get();
        $organizations = DB::table('organizations')->get();

        $this->artisan('oapteka:reset-sessions')->expectsOutput('Reset complete: sessions=8, remembered_logins=3')->assertSuccessful();

        $this->assertDatabaseCount('sessions', 0);
        foreach ($users as $index => $user) {
            $this->assertSame(array_replace($original[$index], ['remember_token' => null]), $user->fresh()->getRawOriginal());
        }
        $this->assertEquals($assignments, DB::table('user_role_assignments')->get());
        $this->assertEquals($organizations, DB::table('organizations')->get());
        $this->artisan('oapteka:reset-sessions')->expectsOutput('Reset complete: sessions=0, remembered_logins=0')->assertSuccessful();
    }

    public function test_dry_run_reports_counts_without_changes(): void
    {
        $user = User::factory()->admin()->create();
        User::factory()->create(['remember_token' => null]);
        $this->storeSession($user);
        $this->storeSession();
        $sessions = DB::table('sessions')->get();

        $this->artisan('oapteka:reset-sessions', ['--dry-run' => true])->expectsOutput('Dry run: sessions=2, remembered_logins=1')->assertSuccessful();

        $this->assertEquals($sessions, DB::table('sessions')->get());
        $this->assertSame($user->remember_token, $user->fresh()->remember_token);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_unsupported_driver_fails_without_changes(bool $dryRun): void
    {
        $user = User::factory()->admin()->create();
        $this->storeSession($user);
        config(['session.driver' => 'file']);

        $this->artisan('oapteka:reset-sessions', ['--dry-run' => $dryRun])
            ->expectsOutput('The database session driver is required. No changes were made.')->assertFailed();

        $this->assertDatabaseCount('sessions', 1);
        $this->assertSame($user->remember_token, $user->fresh()->remember_token);
    }

    public function test_reset_uses_configured_session_connection_and_table(): void
    {
        $this->storeSession();
        config(['database.connections.session_testing' => config('database.connections.sqlite'), 'session.connection' => 'session_testing', 'session.table' => 'browser_sessions']);
        $connection = DB::connection('session_testing');
        $connection->getSchemaBuilder()->create('browser_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        try {
            $connection->table('browser_sessions')->insert(['id' => 'other-device']);
            $this->artisan('oapteka:reset-sessions', ['--dry-run' => true])->expectsOutput('Dry run: sessions=1, remembered_logins=0')->assertSuccessful();
            $this->assertSame(1, $connection->table('browser_sessions')->count());
            $this->artisan('oapteka:reset-sessions')->expectsOutput('Reset complete: sessions=1, remembered_logins=0')->assertSuccessful();
            $this->assertSame(0, $connection->table('browser_sessions')->count());
            $this->assertDatabaseCount('sessions', 1);
        } finally {
            DB::purge('session_testing');
        }
    }

    #[TestWith(['admin'])]
    #[TestWith(['pharmacy'])]
    #[TestWith(['wholesaler'])]
    public function test_old_cookie_is_rejected_and_fresh_login_succeeds(string $role): void
    {
        $user = User::factory()->{$role}()->create(['phone' => '+992901234567', 'password' => $role === 'pharmacy' ? '000000' : 'password']);
        $sessionId = $this->storeSession($user);
        $this->newBrowserRequest($sessionId);
        $this->get(route('profile.edit'))->assertOk();

        $this->artisan('oapteka:reset-sessions')->assertSuccessful();

        $this->newBrowserRequest($sessionId);
        $this->get(route('profile.edit'))->assertRedirectToRoute('login');
        $this->assertGuest();
        if ($role === 'admin') {
            $this->post(route('admin.login.authenticate'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        } else {
            config(['auth.development_otp_code' => '000000']);
            $supplier = $role === 'wholesaler';
            $this->withSession(['phone_otp.'.($supplier ? 'supplier_' : '').'login' => $user->phone])
                ->post(route($supplier ? 'provider.otp.verify' : 'login.otp.verify'), ['code' => '000000'])->assertRedirect();
        }
        $this->assertAuthenticatedAs($user);
    }

    private function storeSession(?User $user = null, bool $expired = false): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user?->id,
            'payload' => base64_encode(json_encode($user ? [auth()->guard()->getName() => $user->id] : ['phone_otp.login' => '+992901234567'])),
            'last_activity' => $expired ? now()->subDays(60)->timestamp : now()->timestamp,
        ]);

        return $id;
    }

    private function newBrowserRequest(string $sessionId): void
    {
        auth()->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->withCookie(config('session.cookie'), $sessionId);
    }
}
