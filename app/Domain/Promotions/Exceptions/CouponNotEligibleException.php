<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Exceptions;

use RuntimeException;

/**
 * Module 14 §48 "Coupon Enumeration Protection" — deliberately ONE
 * generic exception for every ineligibility reason (not found,
 * expired, usage limit reached, minimum spend not met, wrong
 * customer, etc.). Never expose WHICH reason to the client — that
 * would leak private campaign information (e.g. confirming a guessed
 * code exists but "you're not eligible" reveals more than intended).
 */
final class CouponNotEligibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This coupon code is not valid for your order.');
    }
}
