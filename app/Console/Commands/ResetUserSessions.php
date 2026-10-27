<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('oapteka:reset-sessions {--dry-run : Report counts without changing sessions or remembered logins}')]
#[Description('Log out all users and devices by deleting all database sessions and revoking remembered logins')]
class ResetUserSessions extends Command
{
    public function handle(): int
    {
        if (config('session.driver') !== 'database') {
            $this->error('The database session driver is required. No changes were made.');

            return self::FAILURE;
        }

        $sessions = DB::connection(config('session.connection'))->table(config('session.table'));
        $users = (new User)->getConnection()->table((new User)->getTable())->whereNotNull('remember_token');

        if ($this->option('dry-run')) {
            $sessionCount = $sessions->count();
            $rememberedLoginCount = $users->count();
        } else {
            $rememberedLoginCount = $users->update(['remember_token' => null]);
            $sessionCount = $sessions->delete();
        }

        $this->info(($this->option('dry-run') ? 'Dry run: ' : 'Reset complete: ')."sessions={$sessionCount}, remembered_logins={$rememberedLoginCount}");

        return self::SUCCESS;
    }
}
