<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oapteka:bootstrap-admin {--name=} {--email=} {--phone=} {--password-file=}')]
#[Description('Create or promote the initial OAPTEKA administrator')]
class BootstrapAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Имя');
        $email = $this->option('email') ?: $this->ask('Email');
        $phone = $this->option('phone') ?: $this->ask('Телефон');
        $passwordFile = $this->option('password-file');
        if ($passwordFile !== null) {
            if (! is_file($passwordFile) || ! is_readable($passwordFile)) {
                $this->error('Файл пароля недоступен.');

                return self::FAILURE;
            }
            $contents = file_get_contents($passwordFile);
            $password = $contents === false ? '' : rtrim($contents, "\r\n");
        } else {
            $password = $this->input->isInteractive() ? $this->secret('Пароль') : null;
        }

        if (! $name || ! $email || ! $phone || ! $password) {
            $this->error('Все данные обязательны.');

            return self::FAILURE;
        }
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $name, 'phone' => $phone, 'password' => $password, 'organization_id' => null, 'role' => UserRole::Admin, 'is_blocked' => false, 'password_change_required' => false])->save();
        $this->info('Администратор создан. При первом входе настройте 2FA.');

        return self::SUCCESS;
    }
}
