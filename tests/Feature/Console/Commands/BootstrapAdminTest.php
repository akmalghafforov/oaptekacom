<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_provisions_admin_with_password_file_without_confirming_two_factor(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'admin-password-');
        file_put_contents($file, "secure deployment password\n");
        try {
            $this->artisan('oapteka:bootstrap-admin', ['--name' => 'Admin', '--email' => 'admin@example.com', '--phone' => '992900000001', '--password-file' => $file, '--no-interaction' => true])->assertSuccessful();
            $user = User::where('email', 'admin@example.com')->firstOrFail();
            $this->assertSame(UserRole::Admin, $user->role);
            $this->assertTrue(Hash::check('secure deployment password', $user->password));
            $this->assertNull($user->two_factor_confirmed_at);
        } finally {
            unlink($file);
        }
    }

    public function test_empty_password_file_does_not_create_an_admin(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'admin-password-');
        file_put_contents($file, "\n");
        try {
            $this->artisan('oapteka:bootstrap-admin', ['--name' => 'Admin', '--email' => 'admin@example.com', '--phone' => '992900000001', '--password-file' => $file, '--no-interaction' => true])->assertFailed();
            $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
        } finally {
            unlink($file);
        }
    }

    public function test_missing_password_file_does_not_create_an_admin(): void
    {
        $this->artisan('oapteka:bootstrap-admin', ['--name' => 'Admin', '--email' => 'admin@example.com', '--phone' => '992900000001', '--password-file' => '/nonexistent/admin-password', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_noninteractive_provisioning_requires_a_password(): void
    {
        $this->artisan('oapteka:bootstrap-admin', ['--name' => 'Admin', '--email' => 'admin@example.com', '--phone' => '992900000001', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }
}
