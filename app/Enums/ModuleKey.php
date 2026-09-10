<?php

namespace App\Enums;

enum ModuleKey: string
{
    case Catalog = 'catalog';
    case Orders = 'orders';
    case Subscription = 'subscription';
    case SupplierOffers = 'supplier_offers';
    case Barter = 'barter';
    case Finance = 'finance';
}
