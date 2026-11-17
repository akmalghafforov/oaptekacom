<?php

namespace Tests\Feature;

use App\Services\DatabasePullException;
use App\Services\DatabasePullProcess;
use App\Services\ProductionDatabasePull;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PullProductionDatabaseTest extends TestCase
{
    private ?string $directory = null;

    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            foreach ($this->originalEnvironment as $key => [$env, $server, $value]) {
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                putenv($value === false ? $key : $key.'='.$value);
            }
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function environment(string $key, string $value): void
    {
        $this->originalEnvironment[$key] ??= [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }

    /** @return array<string, mixed> */
    private function fixture(string $failure = '', string $mode = 'docker'): array
    {
        $this->directory = sys_get_temp_dir().'/database-pull-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/bin', 0700);
        mkdir($this->directory.'/storage/app', 0700, true);
        mkdir($this->directory.'/remote', 0700);
        file_put_contents($this->directory.'/remote/.env.production', "APP_ENV=production\n");
        file_put_contents($this->directory.'/remote/compose.production.yaml', 'services: {}');
        file_put_contents($this->directory.'/state', json_encode(['local_only' => [1, 2]]));
        file_put_contents($this->directory.'/production-state', json_encode(['production_only' => [42]]));
        foreach (['ssh', 'docker', 'psql', 'pg_dump', 'pg_restore'] as $tool) {
            copy(base_path('tests/Fixtures/database-pull-tool.py'), $this->directory.'/bin/'.$tool);
            chmod($this->directory.'/bin/'.$tool, 0700);
        }
        $this->environment('PATH', $this->directory.'/bin:'.getenv('PATH'));
        $this->environment('PULL_TEST_ROOT', $this->directory);
        $this->environment('PULL_FAILURE', $failure);
        $this->app->useStoragePath($this->directory.'/storage');
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
            'database' => 'local-db', 'username' => 'local-user', 'password' => 'SECRET_PASSWORD',
        ]]);
        $settings = [
            'host' => 'production-alias', 'remote-path' => $this->directory.'/remote',
            'backup-directory' => $this->directory.'/backups', 'timeout' => 10,
            'local-mode' => $mode,
            'ssh-user' => null, 'ssh-port' => null, 'ssh-key' => null, 'ssh-config' => null,
            'remote-env-file' => null, 'remote-compose-file' => [], 'remote-project' => null,
            'remote-app-service' => 'app', 'remote-db-service' => null,
            'local-compose-file' => ['docker-compose.yml'], 'local-project' => 'oaptekacom', 'local-db-service' => 'postgres',
        ];
        config(['database-pull' => $settings]);

        return $settings;
    }

    /** @param array<string, mixed> $settings @return array{int, string} */
    private function pull(array $settings, bool $confirm = true): array
    {
        $messages = [];
        $status = $this->app->make(ProductionDatabasePull::class)->run(
            $settings, fn (): bool => $confirm,
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        return [$status, implode("\n", $messages)];
    }

    /** @return list<array{tool: string, args: list<string>, side: string}> */
    private function commands(): array
    {
        $path = $this->directory.'/commands';

        return is_file($path) ? array_map(fn (string $line): array => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES)) : [];
    }

    /** @return array<string, array{string}> */
    public static function localModes(): array
    {
        return ['docker' => ['docker'], 'native' => ['native']];
    }

    #[DataProvider('localModes')]
    public function test_replaces_all_local_objects_after_retaining_a_private_backup(string $mode): void
    {
        $settings = $this->fixture(mode: $mode);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(0, $status, $output);
        $this->assertSame(['production_only' => [42]], json_decode(file_get_contents($this->directory.'/state'), true));
        $backups = glob($this->directory.'/backups/*.dump');
        $this->assertCount(1, $backups);
        $this->assertSame(['local_only' => [1, 2]], json_decode(explode("\n", file_get_contents($backups[0]), 2)[1], true));
        $this->assertSame(0600, fileperms($backups[0]) & 0777);
        $this->assertSame(0700, fileperms(dirname($backups[0])) & 0777);
        $this->assertStringContainsString('Completed local backup: '.$backups[0], $output);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS "local-db";', file_get_contents($this->directory.'/sql'));
        $this->assertStringContainsString('TEMPLATE template0', file_get_contents($this->directory.'/sql'));
        $this->assertSame([], glob($this->directory.'/backups/.pull-*'));
        $this->assertStringNotContainsString('SECRET_PASSWORD', json_encode($this->commands()).$output);
        $restore = array_values(array_filter($this->commands(), fn (array $command): bool => in_array('--single-transaction', $command['args'], true)))[0];
        foreach (['--no-owner', '--no-acl', '--exit-on-error', '--single-transaction'] as $flag) {
            $this->assertContains($flag, $restore['args']);
        }
        $stages = array_column($this->commands(), 'args');
        $dumpPosition = array_key_first(array_filter($stages, fn (array $args): bool => in_array('--format=custom', $args, true)));
        $restorePosition = array_key_first(array_filter($stages, fn (array $args): bool => in_array('--single-transaction', $args, true)));
        $this->assertLessThan($restorePosition, $dumpPosition);
    }

    /** @return array<string, array{string, int}> */
    public static function productionSafetyScenarios(): array
    {
        return [
            'successful replacement' => ['', 0],
            'export failure' => ['ssh-export', 1],
            'backup failure' => ['backup', 1],
            'local restore recovery' => ['restore', 1],
            'failed local recovery' => ['recovery', 1],
        ];
    }

    #[DataProvider('productionSafetyScenarios')]
    public function test_fetching_production_never_modifies_source_data_even_during_local_recovery(string $failure, int $expectedStatus): void
    {
        $settings = $this->fixture($failure);
        $originalProduction = file_get_contents($this->directory.'/production-state');

        [$status, $output] = $this->pull($settings);

        $this->assertSame($expectedStatus, $status, $output);
        $this->assertSame($originalProduction, file_get_contents($this->directory.'/production-state'));
        $remoteCommands = array_filter($this->commands(), fn (array $command): bool => $command['side'] === 'remote');
        $this->assertNotEmpty($remoteCommands);
        foreach ($remoteCommands as $command) {
            $this->assertSame('docker', $command['tool']);
            $arguments = $command['args'];
            $this->assertContains($arguments[0], ['context', 'compose', 'inspect', 'exec']);
            if ($arguments[0] === 'compose') {
                $this->assertTrue(in_array('config', $arguments, true) || in_array('ps', $arguments, true));
            }
            if ($arguments[0] !== 'exec') {
                continue;
            }
            $containerPosition = array_search(str_repeat('b', 64), $arguments, true);
            if ($containerPosition === false) {
                $containerPosition = array_search(str_repeat('c', 64), $arguments, true);
                $this->assertNotFalse($containerPosition);
                $this->assertSame('php', $arguments[$containerPosition + 1]);

                continue;
            }
            $tool = $arguments[$containerPosition + 1];
            $clientArguments = array_slice($arguments, $containerPosition + 2);
            $this->assertContains($tool, ['psql', 'pg_dump']);
            if ($tool === 'psql') {
                $this->assertSame([
                    '-X', '--no-password', '-At', '-v', 'ON_ERROR_STOP=1', '-c',
                    "SELECT current_setting('server_version_num') WHERE current_setting('transaction_read_only') = 'on'",
                ], $clientArguments);
            } else {
                $this->assertContains($clientArguments, [
                    ['--version'], ['--no-password', '--format=custom'],
                ]);
            }
        }
    }

    public function test_refuses_to_replace_the_production_source_container_as_the_local_target(): void
    {
        $settings = $this->fixture('same-container');
        $originalProduction = file_get_contents($this->directory.'/production-state');

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('source container cannot be the local replacement target', $output);
        $this->assertSame($originalProduction, file_get_contents($this->directory.'/production-state'));
        $this->assertStringNotContainsString('--format=custom', json_encode($this->commands()));
        $this->assertFileDoesNotExist($this->directory.'/sql');
    }

    public function test_command_declining_default_no_confirmation_creates_no_dumps(): void
    {
        $this->fixture();

        $this->artisan('db:pull-production')->expectsConfirmation('Replace this local database with production data?', 'no')->assertExitCode(1);

        $this->assertFileDoesNotExist($this->directory.'/sql');
        $this->assertSame([], glob($this->directory.'/backups/*.dump'));
        $this->assertStringNotContainsString('--format=custom', json_encode($this->commands()));
    }

    public function test_production_is_refused_before_external_processes(): void
    {
        $this->fixture();
        $this->app->instance('env', 'production');

        $this->artisan('db:pull-production')->expectsOutputToContain('requires an interactive, non-production environment')->assertExitCode(1);

        $this->assertSame([], $this->commands());
        $this->assertDirectoryDoesNotExist($this->directory.'/backups');
    }

    public function test_non_interactive_execution_is_refused_before_external_processes(): void
    {
        $this->fixture();
        $input = new ArrayInput(['command' => 'db:pull-production']);
        $input->setInteractive(false);
        $output = new BufferedOutput;

        $status = $this->app->make(Kernel::class)->handle($input, $output);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('requires an interactive, non-production environment', $output->fetch());
        $this->assertSame([], $this->commands());
    }

    /** @return array<string, array{string}> */
    public static function preReplacementFailures(): array
    {
        return array_combine($failures = ['ssh-discover', 'ssh-export', 'export-empty', 'export-corrupt', 'backup', 'backup-corrupt', 'remote-engine', 'version', 'wrong-project', 'privileges', 'template', 'connections', 'ambiguous', 'changed-source', 'local-version'], array_map(fn (string $value): array => [$value], $failures));
    }

    #[DataProvider('preReplacementFailures')]
    public function test_failure_before_replacement_preserves_the_original_database_and_cleans_archives(string $failure): void
    {
        $settings = $this->fixture($failure);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status, $output);
        $this->assertSame(['local_only' => [1, 2]], json_decode(file_get_contents($this->directory.'/state'), true));
        $this->assertFileDoesNotExist($this->directory.'/sql');
        $this->assertSame([], glob($this->directory.'/backups/*.dump'));
        $this->assertSame([], glob($this->directory.'/backups/*.partial'));
        $this->assertSame([], glob($this->directory.'/backups/.pull-*'));
        $this->assertStringNotContainsString('SECRET_PASSWORD', $output);
        $this->assertStringNotContainsString('private-host', $output);
        $this->assertStringNotContainsString('Original local database recovered', $output);
        $expected = match ($failure) {
            'wrong-project' => 'does not belong',
            'privileges', 'template' => 'ownership and CREATEDB',
            'version' => 'older than production',
            'local-version' => 'must match the server major version',
            'connections' => 'other active connections',
            'ssh-discover', 'remote-engine', 'ambiguous' => 'Remote discover failed',
            'ssh-export', 'changed-source' => 'Remote export failed',
            'export-empty' => 'archive is empty',
            'export-corrupt', 'backup-corrupt' => 'Local pg_restore failed',
            'backup' => 'Local pg_dump failed',
        };
        $this->assertStringContainsString($expected, $output);
    }

    /** @return array<string, array{string}> */
    public static function recoveryFailures(): array
    {
        return ['restore' => ['restore'], 'recreate' => ['recreate']];
    }

    #[DataProvider('recoveryFailures')]
    public function test_recovers_the_original_database_after_replacement_failure_and_returns_failure(string $failure): void
    {
        $settings = $this->fixture($failure);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Original local database recovered.', $output);
        $this->assertSame(['local_only' => [1, 2]], json_decode(file_get_contents($this->directory.'/state'), true));
        $this->assertCount(1, glob($this->directory.'/backups/*.dump'));
        $this->assertSame([], glob($this->directory.'/backups/.pull-*'));
    }

    public function test_reports_failed_recovery_with_retained_backup_and_no_credentials(): void
    {
        $settings = $this->fixture('recovery');

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('RECOVERY FAILED', $output);
        $backup = glob($this->directory.'/backups/*.dump')[0];
        $this->assertStringContainsString($backup, $output);
        $this->assertStringContainsString("'pg_restore' '--no-password'", $output);
        $this->assertStringNotContainsString('SECRET_PASSWORD', $output);
        $this->assertSame([], json_decode(file_get_contents($this->directory.'/state'), true));
        $invocation = explode("\n", $output);
        $this->environment('PULL_FAILURE', '');
        $replay = new Process(['sh', '-c', end($invocation)], base_path(), ['PGPASSWORD' => 'SECRET_PASSWORD']);

        $replay->run();

        $this->assertSame(0, $replay->getExitCode(), $replay->getErrorOutput());
        $this->assertSame(['local_only' => [1, 2]], json_decode(file_get_contents($this->directory.'/state'), true));
    }

    public function test_timeout_removes_partial_archives_without_replacement(): void
    {
        $settings = $this->fixture('timeout');
        $settings['timeout'] = 1;

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('timed out', $output);
        $this->assertSame([], glob($this->directory.'/backups/*.partial'));
        $this->assertSame([], glob($this->directory.'/backups/.pull-*'));
        $this->assertFileDoesNotExist($this->directory.'/sql');
    }

    public function test_interruption_removes_partial_archives_and_returns_130(): void
    {
        if (! function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl is unavailable.');
        }
        $settings = $this->fixture('interrupt');

        [$status, $output] = $this->pull($settings);

        $this->assertSame(130, $status, $output);
        $this->assertSame([], glob($this->directory.'/backups/*.partial'));
        $this->assertSame([], glob($this->directory.'/backups/.pull-*'));
        $this->assertFileDoesNotExist($this->directory.'/sql');
    }

    public function test_concurrent_replacement_is_rejected_before_discovery(): void
    {
        $settings = $this->fixture();
        $lock = fopen(storage_path('app/database-pull.lock'), 'c');
        flock($lock, LOCK_EX);

        try {
            [$status, $output] = $this->pull($settings);

            $this->assertSame(1, $status);
            $this->assertStringContainsString('already running', $output);
            $this->assertSame([], $this->commands());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['SQLite' => ['sqlite'], 'MySQL' => ['mysql']];
    }

    #[DataProvider('engines')]
    public function test_rejects_non_postgresql_local_connections_before_processes(string $engine): void
    {
        $settings = $this->fixture();
        config(['database.connections.pgsql.driver' => $engine]);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('must be a single PostgreSQL connection', $output);
        $this->assertSame([], $this->commands());
    }

    public function test_database_url_is_used_without_exposing_credentials(): void
    {
        $settings = $this->fixture(mode: 'native');
        config(['database.connections.pgsql.url' => 'postgresql://local-user:SECRET_PASSWORD@127.0.0.1:5432/from-url']);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('127.0.0.1:5432 / from-url', $output);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS "from-url"', file_get_contents($this->directory.'/sql'));
        $this->assertStringNotContainsString('SECRET_PASSWORD', $output.json_encode($this->commands()));
    }

    public function test_remote_docker_endpoint_is_refused_for_local_mode(): void
    {
        $settings = $this->fixture();
        $this->environment('PULL_DOCKER_ENDPOINT', 'tcp://production:2376');

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Remote Docker endpoints are forbidden', $output);
        $this->assertFileDoesNotExist($this->directory.'/sql');
    }

    public function test_command_options_override_configuration_and_remote_arguments_remain_data(): void
    {
        $this->fixture();
        $path = $this->directory.'/remote \' $(touch INJECTED)';
        rename($this->directory.'/remote', $path);
        $this->environment('PULL_LOCAL_PROJECT', 'custom-local');

        $this->artisan('db:pull-production', [
            'host' => 'another-alias', '--ssh-user' => 'deploy', '--ssh-port' => '2222',
            '--ssh-key' => '/keys/key with spaces', '--ssh-config' => '/config/ssh',
            '--remote-path' => $path, '--remote-compose-file' => ['compose.production.yaml'],
            '--remote-project' => 'oapteka-op', '--remote-app-service' => 'app', '--remote-db-service' => 'postgres',
            '--local-project' => 'custom-local', '--local-compose-file' => ['custom.yml'], '--local-db-service' => 'postgres',
            '--backup-directory' => $this->directory.'/alternate', '--timeout' => '5',
        ])->expectsConfirmation('Replace this local database with production data?', 'no')->assertExitCode(1);

        $ssh = array_values(array_filter($this->commands(), fn (array $command): bool => $command['tool'] === 'ssh'))[0]['args'];
        foreach (['another-alias', 'deploy', '2222', '/keys/key with spaces', '/config/ssh', 'StrictHostKeyChecking=yes'] as $value) {
            $this->assertContains($value, $ssh);
        }
        $this->assertFileDoesNotExist(base_path('INJECTED'));
        $this->assertDirectoryExists($this->directory.'/alternate');
        $this->assertStringContainsString('custom.yml', json_encode($this->commands()));
    }

    public function test_ssh_alias_retains_unspecified_authentication_settings(): void
    {
        $settings = $this->fixture();

        [$status] = $this->pull($settings, false);

        $this->assertSame(1, $status);
        $ssh = array_values(array_filter($this->commands(), fn (array $command): bool => $command['tool'] === 'ssh'))[0]['args'];
        foreach (['-l', '-p', '-i', '-F'] as $flag) {
            $this->assertNotContains($flag, $ssh);
        }
    }

    public function test_ignores_a_stopped_unrelated_postgresql_service(): void
    {
        $settings = $this->fixture('stopped-unrelated');

        [$status, $output] = $this->pull($settings);

        $this->assertSame(0, $status, $output);
        $this->assertSame(['production_only' => [42]], json_decode(file_get_contents($this->directory.'/state'), true));
    }

    /** @return array<string, array{string, int}> */
    public static function replacementInterruptions(): array
    {
        return ['timeout' => ['restore-timeout', 1], 'signal' => ['restore-interrupt', 130]];
    }

    #[DataProvider('replacementInterruptions')]
    public function test_recovers_after_timeout_or_interruption_during_restore(string $failure, int $exit): void
    {
        if (! function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl is unavailable.');
        }
        $settings = $this->fixture($failure);
        $settings['timeout'] = 1;

        [$status, $output] = $this->pull($settings);

        $this->assertSame($exit, $status, $output);
        $this->assertStringContainsString('Original local database recovered.', $output);
        $this->assertSame(['local_only' => [1, 2]], json_decode(file_get_contents($this->directory.'/state'), true));
        $this->assertCount(1, glob($this->directory.'/backups/*.dump'));
        $this->assertSame([], glob($this->directory.'/backups/.pull-*'));
    }

    public function test_inherited_postgresql_hostaddr_cannot_redirect_local_tools(): void
    {
        $settings = $this->fixture();
        $this->environment('PGHOSTADDR', '203.0.113.10');

        [$status, $output] = $this->pull($settings);

        $this->assertSame(0, $status, $output);
    }

    #[DataProvider('localModes')]
    public function test_inherited_postgresql_service_cannot_redirect_or_break_database_clients(string $mode): void
    {
        $settings = $this->fixture(mode: $mode);
        $this->environment('PGSERVICE', 'unexpected-inherited-service');
        $this->environment('PGHOSTADDR', '203.0.113.10');

        [$status, $output] = $this->pull($settings);

        $this->assertSame(0, $status, $output);
        $this->assertSame(['production_only' => [42]], json_decode(file_get_contents($this->directory.'/state'), true));
    }

    public function test_native_mode_refuses_a_remote_database_host(): void
    {
        $settings = $this->fixture(mode: 'native');
        config(['database.connections.pgsql.host' => 'production.example.com']);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('loopback host or local Unix socket', $output);
        $this->assertSame([], $this->commands());
    }

    public function test_docker_mode_refuses_a_database_connection_that_does_not_match_the_container(): void
    {
        $settings = $this->fixture();
        config(['database.connections.pgsql.port' => 9999]);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('does not match the configured database host and port', $output);
        $this->assertFileDoesNotExist($this->directory.'/sql');
    }

    /** @return array<string, array{string}> */
    public static function maintenanceDatabases(): array
    {
        return ['postgres' => ['postgres'], 'template0' => ['template0'], 'template1' => ['template1']];
    }

    #[DataProvider('maintenanceDatabases')]
    public function test_refuses_maintenance_databases_before_processes(string $database): void
    {
        $settings = $this->fixture();
        config(['database.connections.pgsql.database' => $database]);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Refusing a maintenance, template, or invalid database name', $output);
        $this->assertSame([], $this->commands());
    }

    public function test_database_identifiers_are_quoted_in_recreation_sql(): void
    {
        $settings = $this->fixture();
        config(['database.connections.pgsql.database' => 'local"; DROP DATABASE other; --']);

        [$status, $output] = $this->pull($settings);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS "local""; DROP DATABASE other; --";', file_get_contents($this->directory.'/sql'));
    }

    public function test_refuses_backups_in_public_storage_even_through_a_symlink(): void
    {
        $settings = $this->fixture();
        symlink(public_path(), $this->directory.'/public-link');
        $settings['backup-directory'] = $this->directory.'/public-link/database-backups';

        [$status, $output] = $this->pull($settings);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('outside publicly served storage', $output);
        $this->assertSame([], $this->commands());
    }

    public function test_large_process_metadata_is_bounded_and_diagnostics_remain_private(): void
    {
        $this->fixture();
        $runner = $this->app->make(DatabasePullProcess::class);

        try {
            $runner->run(['python3', '-c', 'import sys; sys.stderr.write("SECRET_PASSWORD" * 100000); sys.stdout.write("x" * 2097152)'], 5, 'Metadata discovery');
            $this->fail('Oversized metadata must be rejected.');
        } catch (DatabasePullException $exception) {
            $this->assertSame('Metadata discovery failed; subprocess details were suppressed to protect credentials.', $exception->getMessage());
        }
    }

    public function test_large_archives_are_streamed_completely_to_disk(): void
    {
        $this->fixture();
        $output = fopen($this->directory.'/large.dump', 'w+b');

        try {
            $result = $this->app->make(DatabasePullProcess::class)->run(
                ['python3', '-c', 'import sys; [sys.stdout.buffer.write(b"x" * 65536) for _ in range(256)]'],
                5, 'Streaming archive', output: $output,
            );

            $this->assertSame('', $result);
            $this->assertSame(16777216, fstat($output)['size']);
        } finally {
            fclose($output);
        }
    }

    public function test_interruption_during_confirmation_exits_without_creating_dumps(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl or posix is unavailable.');
        }
        $settings = $this->fixture();
        $handler = pcntl_signal_get_handler(SIGINT);
        $messages = [];

        $status = $this->app->make(ProductionDatabasePull::class)->run($settings, function (): bool {
            posix_kill(getmypid(), SIGINT);

            return true;
        }, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertSame(130, $status, implode("\n", $messages));
        $this->assertSame($handler, pcntl_signal_get_handler(SIGINT));
        $this->assertSame([], glob($this->directory.'/backups/*.dump'));
        $this->assertStringNotContainsString('--format=custom', json_encode($this->commands()));
        $this->assertFileDoesNotExist($this->directory.'/sql');
    }

    public function test_remote_discovery_uses_a_unix_socket_when_production_rejects_loopback_tcp(): void
    {
        $settings = $this->fixture('remote-loopback-blocked');

        [$status, $output] = $this->pull($settings, false);

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('Cancelled; no dumps were created', $output);
        $this->assertStringNotContainsString('--format=custom', json_encode($this->commands()));
        $this->assertFileDoesNotExist($this->directory.'/sql');
    }

    public function test_remote_export_uses_a_unix_socket_when_production_rejects_loopback_tcp(): void
    {
        $settings = $this->fixture('remote-loopback-blocked');

        [$status, $output] = $this->pull($settings);

        $this->assertSame(0, $status, $output);
        $this->assertSame(['production_only' => [42]], json_decode(file_get_contents($this->directory.'/state'), true));
        $this->assertCount(1, glob($this->directory.'/backups/*.dump'));
    }

    public function test_remote_environment_file_falls_back_to_dotenv(): void
    {
        $settings = $this->fixture();
        rename($this->directory.'/remote/.env.production', $this->directory.'/remote/.env');

        [$status, $output] = $this->pull($settings, false);

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('Cancelled; no dumps were created', $output);
        $remoteCompose = array_values(array_filter($this->commands(), fn (array $command): bool => $command['side'] === 'remote' && $command['args'][0] === 'compose'))[0]['args'];
        $this->assertContains($this->directory.'/remote/.env', $remoteCompose);
    }

    public function test_local_mode_option_overrides_configured_docker_mode(): void
    {
        $this->fixture();

        $this->artisan('db:pull-production', ['--local-mode' => 'native'])
            ->expectsConfirmation('Replace this local database with production data?', 'no')->assertExitCode(1);

        $localDocker = array_filter($this->commands(), fn (array $command): bool => $command['side'] === 'local' && $command['tool'] === 'docker');
        $this->assertSame([], $localDocker);
    }
}
