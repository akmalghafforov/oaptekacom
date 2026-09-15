<?php

namespace App\Services\PriceList;

use App\Enums\PriceListRowDisposition;
use App\Jobs\CommitPriceListImport;
use App\Jobs\MaterializePriceListImportChunk;
use App\Models\PriceListImport;
use Illuminate\Support\Facades\Bus;

class ActivationDispatcher
{
    public function __construct(private readonly ImportActivator $activator) {}

    public function dispatch(PriceListImport $import, ?int $actorId = null): void
    {
        $this->activator->reconcileStagedOffers($import);
        $jobs = $import->rows()
            ->whereIn('disposition', [PriceListRowDisposition::Valid, PriceListRowDisposition::Warning])
            ->orderBy('id')
            ->pluck('id')
            ->chunk((int) config('price-list-imports.activation_chunk_size', 500))
            ->map(fn ($ids): MaterializePriceListImportChunk => new MaterializePriceListImportChunk(
                $import->id,
                (int) $ids->first(),
                (int) $ids->last(),
                $actorId,
            ))
            ->all();
        $jobs[] = new CommitPriceListImport($import->id, $actorId);

        Bus::chain($jobs)->onQueue(config('price-list-imports.queue'))->dispatch();
    }
}
