<?php

namespace App\Services;

use Closure;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProductionDatabasePull
{
    /** @var array<string, mixed> */
    private array $settings;

    /** @var array<string, mixed> */
    private array $connection;

    /** @var list<string> */
    private array $docker = [];

    /** @var array<string, string|false> */
    private array $environment;

    private int $timeout;

    public function __construct(private DatabasePullProcess $process) {}

    /** @param array<string, mixed> $settings */
    public function run(array $settings, Closure $confirm, Closure $report): int
    {
        $temporary = null;
        $partial = null;
        $backup = null;
        $lock = null;
        $replacing = false;
        $signals = [];
        $confirming = false;
        $this->process->interrupted = false;
        $this->docker = [];

        try {
            if (app()->environment('production')) {
                throw new DatabasePullException('Production environments cannot be replaced.');
            }
            $this->settings = $settings;
            $this->validateSettings();
            $this->resolveConnection();
            $directory = $this->backupDirectory();
            $lockPath = storage_path('app/database-pull.lock');
            if (is_link($lockPath)) {
                throw new DatabasePullException('Unsafe database replacement lock.');
            }
            $lock = fopen($lockPath, 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DatabasePullException('Another database replacement is already running.');
            }
            chmod($lockPath, 0600);
            if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
                $signals['async'] = pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM] as $signal) {
                    $signals[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, function () use (&$confirming): void {
                        $this->process->interrupted = true;
                        if ($confirming) {
                            throw new DatabasePullException('Operation interrupted.', 130);
                        }
                    });
                }
            }

            $report('Validating local database and PostgreSQL tools...');
            $local = $this->discoverLocal();
            $report('Discovering production database over SSH...');
            $remote = $this->remote('discover');
            if (! is_array($remote) || array_keys($remote) !== ['container', 'database', 'server', 'dump'] || ! preg_match('/^[a-f0-9]{64}$/D', $remote['container'] ?? '') || ! is_string($remote['database']) || ! is_int($remote['server']) || ! is_int($remote['dump'])) {
                throw new DatabasePullException('Remote discovery returned invalid metadata.');
            }
            if ($this->docker !== [] && $remote['container'] === $this->docker[array_key_last($this->docker)]) {
                throw new DatabasePullException('The production source container cannot be the local replacement target.');
            }
            if ($remote['server'] > $local['server'] || $remote['dump'] > $local['server'] || $remote['dump'] > $local['restore']) {
                throw new DatabasePullException('Local PostgreSQL server or restore client is older than production.');
            }
            $backup = $directory.'/local-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.dump';
            $report('Source: '.$this->settings['host'].' / '.$this->display($remote['database']));
            $report('Local target: '.$this->display($this->connection['host']).':'.$this->connection['port'].' / '.$this->display($this->connection['database']));
            $report('Backup: '.$backup);
            $report('Stop local applications and workers before continuing. All local database objects will be replaced.');
            $confirming = true;
            try {
                $accepted = $confirm();
            } finally {
                $confirming = false;
            }
            if (! $accepted) {
                $report('Cancelled; no dumps were created and the database was not changed.');

                return $this->process->interrupted ? 130 : 1;
            }
            if ($this->process->interrupted) {
                throw new DatabasePullException('Operation interrupted.', 130);
            }
            DB::purge(config('database.default'));
            $active = trim($this->pg('psql', ['-X', '-At', '-v', 'ON_ERROR_STOP=1', '-c', 'SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid()']));
            if ($active !== '0') {
                throw new DatabasePullException('Stop local applications and workers: the target database has other active connections.');
            }
            $temporary = $directory.'/.pull-'.bin2hex(random_bytes(12));
            if (! mkdir($temporary, 0700)) {
                throw new DatabasePullException('Cannot create the restricted archive directory.');
            }
            $archive = $temporary.'/production.dump';
            $report('Streaming a consistent production snapshot...');
            $this->writeArchive($archive, function (mixed $output) use ($remote): void {
                $this->remote('export', $remote, $output);
            });
            $this->inspectArchive($archive);
            $report('Backing up the local database...');
            $partial = $backup.'.partial';
            $this->writeArchive($partial, function (mixed $output): void {
                $this->pg('pg_dump', ['--format=custom'], output: $output, capture: false);
            });
            $this->inspectArchive($partial);
            if (! rename($partial, $backup)) {
                throw new DatabasePullException('Cannot retain the completed local backup.');
            }
            $partial = null;
            $report('Completed local backup: '.$backup);
            $report('Recreating and restoring the local database...');
            DB::purge(config('database.default'));
            $replacing = true;
            $this->recreate($local);
            $this->restore($archive);
            $replacing = false;
            $report('Local database replaced successfully. Backup retained: '.$backup);

            return 0;
        } catch (Throwable $exception) {
            $interrupted = $this->process->interrupted || $exception->getCode() === 130;
            $report($exception instanceof DatabasePullException ? $exception->getMessage() : 'Database pull failed; details suppressed to protect credentials.');
            if ($replacing) {
                $report('Replacement failed. Recovering the original local database...');
                $this->process->interrupted = false;
                try {
                    $this->recreate($local);
                    $this->restore($backup);
                    $report('Original local database recovered. Backup retained: '.$backup);
                } catch (Throwable) {
                    $report('RECOVERY FAILED: the local database may be absent or incomplete. Backup retained: '.$backup);
                    $report('With the same local connection settings and PostgreSQL tools, recreate the target database, then run:');
                    $report('Supply PGPASSWORD through your environment before running this command:');
                    $report($this->recoveryCommand($backup));
                }
            }

            return $interrupted || $this->process->interrupted ? 130 : 1;
        } finally {
            if ($partial !== null && is_file($partial)) {
                unlink($partial);
            }
            if ($temporary !== null && is_dir($temporary)) {
                foreach (glob($temporary.'/*') as $file) {
                    unlink($file);
                }
                rmdir($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            if (isset($signals['async'])) {
                foreach ([SIGINT, SIGTERM] as $signal) {
                    pcntl_signal($signal, $signals[$signal]);
                }
                pcntl_async_signals($signals['async']);
            }
        }
    }

    private function validateSettings(): void
    {
        $host = $this->settings['host'] ?? '';
        if (! is_string($host) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:@-]*$/D', $host) || str_contains($host, '@')) {
            throw new DatabasePullException('Provide an SSH alias or hostname; use --ssh-user for the user.');
        }
        if (! in_array($this->settings['local-mode'], ['docker', 'native'], true)) {
            throw new DatabasePullException('Local mode must be docker or native.');
        }
        $timeout = filter_var($this->settings['timeout'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
        if ($timeout === false) {
            throw new DatabasePullException('Timeout must be between 1 and 86400 seconds.');
        }
        $this->timeout = $timeout;
        if (! empty($this->settings['ssh-port']) && filter_var($this->settings['ssh-port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new DatabasePullException('Invalid SSH port.');
        }
        foreach ($this->settings as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item) && preg_match('/[\x00-\x1f\x7f]/', $item)) {
                    throw new DatabasePullException('Configuration must not contain control characters.');
                }
            }
        }
    }

    private function resolveConnection(): void
    {
        try {
            $configuration = config('database.connections.'.config('database.default'));
            $this->connection = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        } catch (Throwable) {
            throw new DatabasePullException('Cannot resolve the configured local database connection.');
        }
        if (($this->connection['driver'] ?? '') !== 'pgsql' || isset($this->connection['read']) || isset($this->connection['write'])) {
            throw new DatabasePullException('The local default connection must be a single PostgreSQL connection; SQLite and other engines are unsupported.');
        }
        foreach (['host', 'database', 'username'] as $key) {
            if (! is_scalar($this->connection[$key] ?? null)) {
                throw new DatabasePullException('Invalid local PostgreSQL connection identity.');
            }
            $this->connection[$key] = (string) $this->connection[$key];
            if ($this->connection[$key] === '' || preg_match('/[\x00-\x1f\x7f]/', $this->connection[$key])) {
                throw new DatabasePullException('Invalid local PostgreSQL connection identity.');
            }
        }
        if (in_array($this->connection['database'], ['postgres', 'template0', 'template1'], true) || strlen($this->connection['database']) > 63 || str_contains($this->connection['database'], '=')) {
            throw new DatabasePullException('Refusing a maintenance, template, or invalid database name.');
        }
        $this->connection['port'] ??= 5432;
        if (filter_var($this->connection['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new DatabasePullException('Invalid local PostgreSQL port.');
        }
        $this->environment = [
            'PGHOST' => $this->connection['host'],
            'PGPORT' => (string) $this->connection['port'],
            'PGUSER' => $this->connection['username'],
            'PGPASSWORD' => (string) ($this->connection['password'] ?? ''),
            'PGDATABASE' => $this->connection['database'],
            'PGSSLMODE' => (string) ($this->connection['sslmode'] ?? 'prefer'),
            'PGCONNECT_TIMEOUT' => '10',
            'PGSERVICE' => false, 'PGSERVICEFILE' => '/dev/null', 'PGPASSFILE' => '/dev/null',
            'PGOPTIONS' => '', 'PGHOSTADDR' => false, 'PGCLIENTENCODING' => 'UTF8',
        ];
    }

    private function backupDirectory(): string
    {
        $path = $this->settings['backup-directory'] ?: storage_path('app/database-backups');
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        $existing = $path;
        while (! file_exists($existing)) {
            $existing = dirname($existing);
        }
        $resolved = realpath($existing);
        foreach ([public_path(), storage_path('app/public')] as $public) {
            $public = realpath($public) ?: $public;
            if ($resolved === $public || str_starts_with($resolved.'/', $public.'/')) {
                throw new DatabasePullException('Backups must be outside publicly served storage.');
            }
        }
        if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new DatabasePullException('Backup directory must not contain parent traversal.');
        }
        if (! is_dir($path) && ! mkdir($path, 0700, true)) {
            throw new DatabasePullException('Cannot create the backup directory.');
        }
        if (! chmod($path, 0700) || ! is_writable($path)) {
            throw new DatabasePullException('Backup directory is not writable or private.');
        }

        return realpath($path);
    }

    /** @return array<string, mixed> */
    private function discoverLocal(): array
    {
        if ($this->settings['local-mode'] === 'docker') {
            $endpoint = json_decode($this->execute(['docker', 'context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}'], 'Local Docker context inspection'), true);
            if (! is_string($endpoint) || ! str_starts_with($endpoint, 'unix://') || (getenv('DOCKER_HOST') && ! str_starts_with(getenv('DOCKER_HOST'), 'unix://'))) {
                throw new DatabasePullException('Remote Docker endpoints are forbidden for local replacement.');
            }
            $compose = ['docker', 'compose', '--project-name', $this->settings['local-project']];
            foreach ($this->settings['local-compose-file'] as $file) {
                $compose = [...$compose, '-f', $file];
            }
            $ids = preg_split('/\s+/', trim($this->execute([...$compose, 'ps', '--status', 'running', '-q', $this->settings['local-db-service']], 'Local container discovery')));
            if (count($ids) !== 1 || ! preg_match('/^[a-f0-9]{12,64}$/D', $ids[0])) {
                throw new DatabasePullException('A unique running local PostgreSQL container is required.');
            }
            $container = json_decode($this->execute(['docker', 'inspect', $ids[0]], 'Local container inspection'), true)[0] ?? [];
            $labels = $container['Config']['Labels'] ?? [];
            if (($labels['com.docker.compose.project'] ?? null) !== $this->settings['local-project'] || ($labels['com.docker.compose.service'] ?? null) !== $this->settings['local-db-service'] || ! ($container['State']['Running'] ?? false)) {
                throw new DatabasePullException('Local container does not belong to the configured Compose project/service.');
            }
            $host = $this->connection['host'];
            $matches = false;
            foreach ($container['NetworkSettings']['Networks'] ?? [] as $network) {
                if (in_array($host, [...($network['Aliases'] ?? []), $network['IPAddress'] ?? '', ltrim($container['Name'] ?? '', '/')], true) && (int) $this->connection['port'] === 5432) {
                    $matches = true;
                }
            }
            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                foreach ($container['NetworkSettings']['Ports']['5432/tcp'] ?? [] as $binding) {
                    if ((string) $binding['HostPort'] === (string) $this->connection['port'] && in_array($binding['HostIp'], ['0.0.0.0', '::', $host], true)) {
                        $matches = true;
                    }
                }
            }
            if (! $matches) {
                throw new DatabasePullException('Local container does not match the configured database host and port.');
            }
            $this->docker = ['docker', 'exec', '-i'];
            foreach (array_keys($this->environment) as $name) {
                $this->docker = [...$this->docker, '--env', $name];
            }
            $this->docker[] = $container['Id'];
            $this->environment['PGHOST'] = '127.0.0.1';
            $this->environment['PGPORT'] = '5432';
        } elseif (! in_array($this->connection['host'], ['localhost', '127.0.0.1', '::1'], true) && ! str_starts_with($this->connection['host'], '/')) {
            throw new DatabasePullException('Native mode requires a loopback host or local Unix socket.');
        }
        $sql = <<<'SQL'
SELECT json_build_object(
 'server', current_setting('server_version_num')::int / 10000,
 'allowed', (r.rolsuper OR r.rolcreatedb) AND (r.rolsuper OR d.datdba = r.oid),
 'template', d.datistemplate, 'encoding', pg_encoding_to_char(d.encoding),
 'collate', d.datcollate, 'ctype', d.datctype, 'provider', COALESCE(to_jsonb(d)->>'datlocprovider', 'c'),
 'locale', COALESCE(to_jsonb(d)->>'datlocale', to_jsonb(d)->>'daticulocale'),
 'rules', to_jsonb(d)->>'daticurules',
 'connections', (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid())
) FROM pg_database d JOIN pg_roles r ON r.rolname = current_user WHERE d.datname = current_database();
SQL;
        $metadata = json_decode($this->pg('psql', ['-X', '-At', '-v', 'ON_ERROR_STOP=1', '-c', $sql]), true);
        if (! is_array($metadata) || ! ($metadata['allowed'] ?? false) || ($metadata['template'] ?? true) || ! in_array($metadata['provider'] ?? '', ['c', 'i', 'b'], true) || ($metadata['server'] ?? 0) < 10) {
            throw new DatabasePullException('Local database requires PostgreSQL 10+, database ownership and CREATEDB (or superuser) privileges.');
        }
        foreach (['encoding', 'collate', 'ctype'] as $key) {
            if (! is_string($metadata[$key] ?? null) || $metadata[$key] === '') {
                throw new DatabasePullException('Cannot determine the local database locale and encoding.');
            }
        }
        if ($metadata['provider'] !== 'c' && (! is_string($metadata['locale'] ?? null) || $metadata['locale'] === '')) {
            throw new DatabasePullException('Cannot determine the local database locale provider settings.');
        }
        $this->pg('psql', ['-X', '-At', '-v', 'ON_ERROR_STOP=1', '-c', 'SELECT 1'], database: 'postgres');
        $dump = $this->clientVersion('pg_dump');
        $metadata['restore'] = $this->clientVersion('pg_restore');
        if ($dump !== $metadata['server'] || $metadata['restore'] !== $metadata['server']) {
            throw new DatabasePullException('Local PostgreSQL dump and restore clients must match the server major version.');
        }

        return $metadata;
    }

    private function clientVersion(string $tool): int
    {
        if (! preg_match('/\b(\d+)\./', $this->pg($tool, ['--version']), $matches)) {
            throw new DatabasePullException('Cannot determine PostgreSQL client version.');
        }

        return (int) $matches[1];
    }

    /** @param array<string, mixed> $metadata */
    private function recreate(array $metadata): void
    {
        $database = '"'.str_replace('"', '""', $this->connection['database']).'"';
        $literal = fn (string $value): string => "'".str_replace("'", "''", $value)."'";
        $sql = "SET standard_conforming_strings = on;\n".'DROP DATABASE IF EXISTS '.$database.";\n";
        $sql .= 'CREATE DATABASE '.$database.' WITH TEMPLATE template0 ENCODING '.$literal($metadata['encoding']).' LC_COLLATE '.$literal($metadata['collate']).' LC_CTYPE '.$literal($metadata['ctype']);
        if ($metadata['server'] >= 15) {
            $provider = ['c' => 'libc', 'i' => 'icu', 'b' => 'builtin'][$metadata['provider']];
            $sql .= ' LOCALE_PROVIDER '.$provider;
            if ($provider !== 'libc') {
                $sql .= ($provider === 'icu' ? ' ICU_LOCALE ' : ' BUILTIN_LOCALE ').$literal($metadata['locale']);
            }
            if ($provider === 'icu' && ! empty($metadata['rules'])) {
                $sql .= ' ICU_RULES '.$literal($metadata['rules']);
            }
        }
        $sql .= ";\n";
        $this->pg('psql', ['-X', '-v', 'ON_ERROR_STOP=1'], input: $sql, database: 'postgres', capture: false);
    }

    private function restore(string $archive): void
    {
        $input = fopen($archive, 'rb');
        try {
            $this->pg('pg_restore', ['--no-owner', '--no-acl', '--exit-on-error', '--single-transaction', '--dbname', $this->connection['database']], input: $input, capture: false);
        } finally {
            fclose($input);
        }
    }

    private function inspectArchive(string $path): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) === 0) {
            throw new DatabasePullException('Exported database archive is empty.');
        }
        $input = fopen($path, 'rb');
        try {
            $this->pg('pg_restore', ['--list'], input: $input, capture: false);
        } finally {
            fclose($input);
        }
    }

    private function writeArchive(string $path, Closure $write): void
    {
        $output = fopen($path, 'x+b');
        if ($output === false) {
            throw new DatabasePullException('Cannot create a private database archive.');
        }
        try {
            if (! chmod($path, 0600)) {
                throw new DatabasePullException('Cannot restrict database archive permissions.');
            }
            $write($output);
            if (! fflush($output) || ! fsync($output)) {
                throw new DatabasePullException('Cannot flush the database archive to disk.');
            }
        } finally {
            fclose($output);
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  resource|null  $output
     * @return array{container: string, database: string, server: int, dump: int}|null
     */
    private function remote(string $action, array $expected = [], mixed $output = null): ?array
    {
        $options = array_intersect_key($this->settings, array_flip(['remote-path', 'remote-env-file', 'remote-compose-file', 'remote-project', 'remote-app-service', 'remote-db-service']));
        $options['timeout'] = $this->timeout;
        $options['expected'] = $expected;
        $payload = base64_encode(json_encode($options, JSON_THROW_ON_ERROR));
        $command = 'python3 - '.escapeshellarg($action).' '.escapeshellarg($payload);
        $ssh = ['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15'];
        foreach (['ssh-config' => '-F', 'ssh-user' => '-l', 'ssh-port' => '-p', 'ssh-key' => '-i'] as $key => $flag) {
            if ($this->settings[$key] !== null && $this->settings[$key] !== '') {
                $ssh = [...$ssh, $flag, (string) $this->settings[$key]];
            }
        }
        $result = $this->process->run([...$ssh, '--', $this->settings['host'], $command], $this->timeout, 'Remote '.$action, input: file_get_contents(__DIR__.'/database_pull_remote.py'), output: $output, capture: $action === 'discover');

        if ($action !== 'discover') {
            return null;
        }
        $metadata = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($metadata)) {
            throw new DatabasePullException('Remote discovery returned invalid metadata.');
        }

        return $metadata;
    }

    /** @param list<string> $arguments */
    private function execute(array $arguments, string $stage): string
    {
        return $this->process->run($arguments, $this->timeout, $stage);
    }

    /**
     * @param  list<string>  $arguments
     * @param  resource|string|null  $input
     * @param  resource|null  $output
     */
    private function pg(string $tool, array $arguments, mixed $input = null, mixed $output = null, ?string $database = null, bool $capture = true): string
    {
        $environment = $this->environment;
        if ($database !== null) {
            $environment['PGDATABASE'] = $database;
        }

        $clientArguments = $arguments === ['--version'] ? $arguments : ['--no-password', ...$arguments];

        return $this->process->run([...$this->docker, $tool, ...$clientArguments], $this->timeout, 'Local '.$tool, $environment, $input, $output, $capture);
    }

    private function display(string $value): string
    {
        return preg_replace('/[^\pL\pN_.:\/-]/u', '?', $value) ?? '[invalid identity]';
    }

    private function recoveryCommand(string $backup): string
    {
        $arguments = [...$this->docker, 'pg_restore', '--no-password', '--no-owner', '--no-acl', '--exit-on-error', '--single-transaction'];
        if ($this->docker === []) {
            $arguments = [...$arguments, '--host', $this->connection['host'], '--port', (string) $this->connection['port'], '--username', $this->connection['username']];
        }
        $arguments = [...$arguments, '--dbname', $this->connection['database']];

        $environment = ['env'];
        $assignments = [];
        foreach ($this->environment as $name => $value) {
            if ($name !== 'PGPASSWORD') {
                if ($value === false) {
                    $environment = [...$environment, '-u', $name];
                } else {
                    $assignments[] = $name.'='.$value;
                }
            }
        }

        return implode(' ', array_map(escapeshellarg(...), [...$environment, ...$assignments, ...$arguments])).' < '.escapeshellarg($backup);
    }
}
