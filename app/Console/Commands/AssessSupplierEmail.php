<?php

namespace App\Console\Commands;

use App\Services\SupplierEmailExclusions;
use App\Services\SupplierInboxEvidence;
use Illuminate\Console\Command;

class AssessSupplierEmail extends Command
{
    protected $signature = 'suppliers:assess-email';

    protected $description = 'Refresh previous-calendar-month email evidence without changing administrator settings';

    public function handle(SupplierInboxEvidence $inbox, SupplierEmailExclusions $exclusions): int
    {
        $inbox->reconcileStored();
        $exclusions->assess();

        return self::SUCCESS;
    }
}
