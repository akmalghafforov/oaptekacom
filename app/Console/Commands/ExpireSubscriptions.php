<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscriptions:expire')]
#[Description('Expires elapsed paid subscriptions and restores the Free plan')]
class ExpireSubscriptions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SubscriptionService $subscriptions): int
    {
        $this->info('Expired subscriptions: '.$subscriptions->expireDueSubscriptions());

        return self::SUCCESS;
    }
}
