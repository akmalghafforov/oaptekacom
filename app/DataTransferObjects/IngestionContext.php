<?php

namespace App\DataTransferObjects;

use App\Enums\PriceListImportSource;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class IngestionContext
{
    public function __construct(
        public PriceListImportSource $source,
        public ?User $actor = null,
        public ?string $senderEmail = null,
        public ?string $messageId = null,
        public ?CarbonImmutable $receivedAt = null,
        public ?array $profileConfiguration = null,
        public ?array $sourceMetadata = null,
        public ?CarbonImmutable $sourcePublishedAt = null,
        public ?string $sourceDatePrecision = null,
        public ?string $sourceFingerprint = null,
    ) {}
}
