<?php

namespace App\Enums;

enum PromotionStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case NeedsRevision = 'needs_revision';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::PendingReview => 'На проверке',
            self::Published => 'Опубликована',
            self::NeedsRevision => 'Нужны исправления',
            self::Rejected => 'Отклонена',
        };
    }
}
