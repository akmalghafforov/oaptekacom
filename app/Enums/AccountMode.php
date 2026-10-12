<?php

namespace App\Enums;

enum AccountMode: string
{
    case Admin = 'admin';
    case Supplier = 'supplier';
    case Pharmacy = 'pharmacy';

    public function role(): UserRole
    {
        return match ($this) {
            self::Admin => UserRole::Admin,
            self::Supplier => UserRole::Wholesaler,
            self::Pharmacy => UserRole::Pharmacy,
        };
    }

    public static function forRole(UserRole $role): self
    {
        return match ($role) {
            UserRole::Admin => self::Admin,
            UserRole::Wholesaler => self::Supplier,
            UserRole::Pharmacy => self::Pharmacy,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Supplier => 'Поставщик',
            self::Pharmacy => 'Аптека',
        };
    }
}
