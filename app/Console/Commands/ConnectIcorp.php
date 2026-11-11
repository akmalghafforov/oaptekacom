<?php

namespace App\Console\Commands;

use App\Services\IcorpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ConnectIcorp extends Command
{
    protected $signature = 'external-prices:connect-icorp {--take-over : Sign out the other device if the workspace is already in use}';

    protected $description = 'Log in to the configured iCorp workspace and save an encrypted cookie session';

    public function handle(IcorpClient $client): int
    {
        $lock = Cache::lock('external-prices:icorp', 7200);
        if (! $lock->get()) {
            $this->error('An extraction is already using the iCorp account.');

            return self::FAILURE;
        }
        try {
            $client->connect((bool) $this->option('take-over'));
            $this->info('iCorp session encrypted and saved.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Could not connect to iCorp. Check connectivity and account access.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
