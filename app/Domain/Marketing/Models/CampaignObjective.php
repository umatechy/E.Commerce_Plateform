<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

/**
 * Module 15 §6 "Campaign Objectives" — descriptive only, per the
 * module's own explicit rule: "Objective is descriptive and must not
 * bypass eligibility rules." Only AbandonedCartRecovery has real
 * trigger logic behind it in B10 (see inspection findings); the rest
 * are schema-ready labels a staff member can select for a manually-
 * audienced campaign.
 */
enum CampaignObjective: string
{
    case Awareness = 'awareness';
    case NewCustomerAcquisition = 'new_customer_acquisition';
    case FirstPurchase = 'first_purchase';
    case RepeatPurchase = 'repeat_purchase';
    case AbandonedCartRecovery = 'abandoned_cart_recovery';
    case ProductPromotion = 'product_promotion';
    case CategoryPromotion = 'category_promotion';
    case SeasonalSale = 'seasonal_sale';
    case CustomerReactivation = 'customer_reactivation';
}
