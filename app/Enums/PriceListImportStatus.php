<?php

namespace App\Enums;

enum PriceListImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Preview = 'preview';
    case Committing = 'committing';
    case Completed = 'completed';
    case Superseded = 'superseded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'В очереди', self::Processing => 'Обрабатывается', self::Preview => 'Ожидает проверки',
            self::Committing => 'Активируется', self::Completed => 'Активен', self::Superseded => 'Заменён', self::Failed => 'Ошибка',
        };
    }
}
