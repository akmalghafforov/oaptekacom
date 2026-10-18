<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionMakeTest extends TestCase
{
    /** @return array{status: int, output: string, commands: string} */
    private function runMake(string $target, string $environment = 'production', string $failure = ''): array
    {
        $directory = sys_get_temp_dir().'/oapteka-make-'.bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory.'/environment', "APP_ENV=$environment\n");
        file_put_contents($directory.'/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$DOCKER_LOG"
case "$*" in *" run "*"--no-build"*) printf 'unknown flag: --no-build\n' >&2; exit 1 ;; esac
if [ -n "$DOCKER_FAILURE" ]; then
    case "$*" in *"$DOCKER_FAILURE"*) exit 1 ;; esac
fi
case "$*" in *"config --images") printf '%s\n' oapteka-php:test oapteka-nginx:test postgres:16-alpine node:24-alpine ;; esac
SH);
        chmod($directory.'/docker', 0755);

        try {
            $process = new Process(['make', '--no-print-directory', 'ENV_FILE='.$directory.'/environment', 'COMPOSE_COMMAND=ignored', $target], dirname(__DIR__, 2), [
                'PATH' => $directory.':'.getenv('PATH'),
                'DOCKER_LOG' => $directory.'/commands',
                'DOCKER_FAILURE' => $failure,
            ]);
            $process->run();

            return [
                'status' => $process->getExitCode(),
                'output' => $process->getOutput().$process->getErrorOutput(),
                'commands' => file_exists($directory.'/commands') ? file_get_contents($directory.'/commands') : '',
            ];
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_apply_updates_in_order_without_building_or_stopping_postgres(): void
    {
        $result = $this->runMake('apply');

        $this->assertSame(0, $result['status'], $result['output']);
        $commands = $result['commands'];
        $offset = 0;
        foreach (['config --quiet', 'config --images', 'image inspect', 'stop nginx app queue scheduler', 'storage-init', '--wait postgres', '--pull never dependencies', '--pull never assets', 'release release migrate --force', '--exit-code-from app-init app-init', '--no-deps app queue scheduler nginx'] as $step) {
            $position = strpos($commands, $step, $offset);
            $this->assertNotFalse($position, $step."\n".$commands);
            $offset = $position + strlen($step);
        }
        $this->assertStringContainsString('--project-name oapteka-op', $commands);
        $this->assertStringContainsString('/compose.production.yaml', $commands);
        $this->assertStringNotContainsString(' build', $commands);
        $this->assertStringNotContainsString(' down', $commands);
        $this->assertStringNotContainsString('stop postgres', $commands);
        foreach (explode("\n", trim($commands)) as $command) {
            if (str_contains($command, ' run ')) {
                $this->assertStringContainsString('--pull never', $command);
                $this->assertStringNotContainsString('--no-build', $command);
                $this->assertStringNotContainsString('--build', $command);
            }
            if (str_contains($command, ' up ')) {
                $this->assertStringContainsString('--no-build --pull never', $command);
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function failureSteps(): array
    {
        return [
            'configuration' => ['config --quiet'],
            'image enumeration' => ['config --images'],
            'missing image' => ['image inspect'],
            'stop' => ['stop nginx'],
            'ownership' => ['--pull never storage-init'],
            'database health' => ['--wait postgres'],
            'dependencies' => ['--pull never dependencies'],
            'assets' => ['--pull never assets'],
            'migrations' => ['release release migrate'],
            'caches' => ['--exit-code-from app-init'],
        ];
    }

    #[DataProvider('failureSteps')]
    public function test_apply_does_not_start_application_after_a_failed_step(string $failure): void
    {
        $result = $this->runMake('apply', failure: $failure);

        $this->assertNotSame(0, $result['status']);
        $this->assertStringNotContainsString('--no-deps app queue scheduler nginx', $result['commands']);
        $lines = explode("\n", trim($result['commands']));
        $this->assertStringContainsString($failure, end($lines));
        if ($failure === 'image inspect') {
            $this->assertStringContainsString('make build', $result['output']);
            $this->assertStringNotContainsString('stop nginx', $result['commands']);
        }
    }

    public function test_up_installs_dependencies_before_refreshing_caches_without_running_migrations(): void
    {
        $result = $this->runMake('up');

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertLessThan(strpos($result['commands'], '--exit-code-from app-init'), strpos($result['commands'], '--pull never dependencies'));
        $this->assertStringNotContainsString('release release migrate', $result['commands']);
        $this->assertStringContainsString('--no-deps app queue scheduler nginx', $result['commands']);
    }

    public function test_local_apply_is_rejected_without_invoking_docker(): void
    {
        $result = $this->runMake('apply', 'local');

        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('production-only', $result['output']);
        $this->assertSame('', $result['commands']);
    }

    public function test_local_up_keeps_the_existing_build_workflow(): void
    {
        $result = $this->runMake('up', 'local');

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertStringContainsString('/docker-compose.yml up --build --detach', $result['commands']);
        $this->assertStringNotContainsString('dependencies', $result['commands']);
    }
}
