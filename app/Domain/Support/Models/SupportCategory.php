<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

enum SupportCategory: string
{
    case Order = 'order';
    case Shipping = 'shipping';
    case Payment = 'payment';
    case Returns = 'returns';
    case Product = 'product';
    case Account = 'account';
    case Billing = 'billing';
    case Technical = 'technical';
    case Other = 'other';

    /** @return list<self> what each desk offers requesters */
    public static function forDesk(SupportDesk $desk): array
    {
        return $desk === SupportDesk::Store
            ? [self::Order, self::Shipping, self::Payment, self::Returns, self::Product, self::Account, self::Other]
            : [self::Billing, self::Technical, self::Account, self::Payment, self::Other];
    }
}
