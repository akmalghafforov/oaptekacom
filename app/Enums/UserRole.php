<?php

namespace App\Enums;

enum UserRole: string
{
    case Pharmacy = 'pharmacy';
    case Wholesaler = 'wholesaler';
    case Admin = 'admin';
}
