<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabasePullProcess
{
    public bool $interrupted = false;

    /**
     * Stream archives without retaining process output or credentials in diagnostics.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     * @param  resource|string|null  $input
     * @param  resource|null  $output
     */
    public function run(array $arguments, int $timeout, string $stage, array $environment = [], mixed $input = null, mixed $output = null, bool $capture = true): string
    {
        $process = new Process($arguments, base_path(), $environment, $input, $timeout);
        $result = '';
        $failed = false;
        $process->disableOutput();

        try {
            $process->start(function (string $type, string $data) use ($output, $capture, &$result, &$failed): void {
                if ($failed) {
                    return;
                }
                if ($type === Process::OUT) {
                    if (is_resource($output)) {
                        while ($data !== '') {
                            $written = fwrite($output, $data);
                            if ($written === false || $written === 0) {
                                $failed = true;

                                return;
                            }
                            $data = substr($data, $written);
                        }
                    } elseif ($capture) {
                        if (strlen($result) + strlen($data) > 1024 * 1024) {
                            $failed = true;

                            return;
                        }
                        $result .= $data;
                    }
                }
            });
            do {
                if ($this->interrupted) {
                    throw new DatabasePullException('Operation interrupted.', 130);
                }
                $process->checkTimeout();
                $running = $process->isRunning();
                if ($failed) {
                    throw new DatabasePullException('Process output could not be accepted.');
                }
                if ($running) {
                    usleep(20000);
                }
            } while ($running);
            if ($failed || ! $process->isSuccessful()) {
                throw new DatabasePullException('Subprocess failed.');
            }

            return $result;
        } catch (Throwable $exception) {
            if ($exception->getCode() === 130) {
                throw new DatabasePullException('Operation interrupted.', 130);
            }
            $reason = $exception instanceof ProcessTimedOutException ? ' timed out.' : ' failed; subprocess details were suppressed to protect credentials.';
            throw new DatabasePullException($stage.$reason);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
    }
}
