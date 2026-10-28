<?php

namespace App\Console\Commands;

use App\Models\SupplierReview;
use App\Services\TelegramSupplierReviews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ReconcileSupplierReviews extends Command
{
    protected $signature = 'telegram:reconcile-reviews';

    protected $description = 'Dispatch outstanding supplier reviews and report delivery problems';

    public function handle(TelegramSupplierReviews $reviews): int
    {
        SupplierReview::where('delivery_state', 'sending')->where('updated_at', '<', now()->subMinutes(5))->eachById(function (SupplierReview $review): void {
            $lock = Cache::lock('telegram-review:'.$review->id, 120);
            if ($lock->get()) {
                try {
                    SupplierReview::whereKey($review->id)->where('delivery_state', 'sending')->update(['delivery_state' => 'ambiguous', 'last_error' => 'interrupted_send']);
                } finally {
                    $lock->release();
                }
            }
        });
        SupplierReview::whereIn('delivery_state', ['pending', 'retry', 'sent', 'editing'])
            ->whereColumn('version', '>', 'delivered_version')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->eachById(fn (SupplierReview $review) => $reviews->dispatch($review));
        $problems = SupplierReview::whereIn('delivery_state', ['ambiguous', 'failed'])->pluck('id');
        foreach ($problems as $id) {
            $this->warn('Review '.$id.' requires reconciliation; inspect supplier_reviews before retrying.');
        }

        return self::SUCCESS;
    }
}
