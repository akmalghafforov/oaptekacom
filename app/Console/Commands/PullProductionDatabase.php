<?php

namespace App\Console\Commands;

use App\Services\ProductionDatabasePull;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('db:pull-production {host? : Existing SSH alias or production hostname}
    {--ssh-user=} {--ssh-port=} {--ssh-key=} {--ssh-config=}
    {--remote-path=} {--remote-env-file=} {--remote-compose-file=*}
    {--remote-project=} {--remote-app-service=} {--remote-db-service=}
    {--local-mode=} {--local-compose-file=*} {--local-project=} {--local-db-service=}
    {--backup-directory=} {--timeout=}')]
#[Description('Back up and replace the local PostgreSQL database with a production snapshot')]
class PullProductionDatabase extends Command
{
    public function handle(ProductionDatabasePull $pull): int
    {
        if (app()->environment('production') || ! $this->input->isInteractive()) {
            $this->error('This command requires an interactive, non-production environment.');

            return self::FAILURE;
        }

        $settings = config('database-pull');
        foreach (array_keys($settings) as $key) {
            if ($key === 'host') {
                $settings[$key] = $this->argument('host') ?? $settings[$key];
            } elseif ($this->input->hasParameterOption('--'.$key) || $this->option($key) !== null && $this->option($key) !== []) {
                $settings[$key] = $this->option($key);
            }
        }

        return $pull->run(
            $settings,
            fn (): bool => $this->confirm('Replace this local database with production data?', false),
            fn (string $message) => $this->line($message),
        );
    }
}
