<?php

namespace App\Enums;

enum ProductCategory: string
{
    case Inhalations = 'Ингаляции';
    case Injections = 'Инъекции';
    case Suspension = 'Суспензия';
    case Suppositories = 'Свечи';
    case ChewingGum = 'Жевательная резинка';
    case Microenema = 'Микроклизма';
    case Lozenges = 'Пастилки';
    case Tablets = 'Таблетки';
    case Capsules = 'Капсулы';
    case Dragee = 'Драже';
    case Syrup = 'Сироп';
    case Ointment = 'Мазь';
    case Cream = 'Крем';
    case Gel = 'Гель';
    case Drops = 'Капли';
    case Spray = 'Спрей';
    case Aerosol = 'Аэрозоль';
    case Solution = 'Раствор';
    case Powder = 'Порошок';
    case Granules = 'Гранулы';
    case Patch = 'Пластырь';
    case Emulsion = 'Эмульсия';
    case Liniment = 'Линимент';
    case Lotion = 'Лосьон';
    case Paste = 'Паста';
    case Oil = 'Масло';
    case Tincture = 'Настойка';
    case Shampoo = 'Шампунь';
    case Extract = 'Экстракт';
    case Unrecognized = 'Не распознано';

    /** @return list<self> */
    public static function ordered(): array
    {
        return [self::Inhalations, self::Injections, self::Suspension, self::Suppositories, self::ChewingGum, self::Microenema, self::Lozenges, self::Tablets, self::Capsules, self::Dragee, self::Syrup, self::Ointment, self::Cream, self::Gel, self::Drops, self::Spray, self::Aerosol, self::Solution, self::Powder, self::Granules, self::Patch, self::Emulsion, self::Liniment, self::Lotion, self::Paste, self::Oil, self::Tincture, self::Shampoo, self::Extract, self::Unrecognized];
    }

    public function code(): string
    {
        return match ($this) {
            self::Inhalations => 'inhalations', self::Injections => 'injections', self::Suspension => 'suspension',
            self::Suppositories => 'suppositories', self::ChewingGum => 'chewing_gum', self::Microenema => 'microenema',
            self::Lozenges => 'lozenges', self::Tablets => 'tablets', self::Capsules => 'capsules', self::Dragee => 'dragee',
            self::Syrup => 'syrup', self::Ointment => 'ointment', self::Cream => 'cream', self::Gel => 'gel',
            self::Drops => 'drops', self::Spray => 'spray', self::Aerosol => 'aerosol', self::Solution => 'solution',
            self::Powder => 'powder', self::Granules => 'granules', self::Patch => 'patch', self::Emulsion => 'emulsion',
            self::Liniment => 'liniment', self::Lotion => 'lotion', self::Paste => 'paste', self::Oil => 'oil',
            self::Tincture => 'tincture', self::Shampoo => 'shampoo', self::Extract => 'extract', self::Unrecognized => 'unrecognized',
        };
    }

    public static function fromCode(string $code): ?self
    {
        return collect(self::cases())->first(fn (self $category): bool => $category->code() === $code);
    }
}
