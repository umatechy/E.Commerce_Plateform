<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

/** Module 16 §21 "Index/Noindex Controls" — the module's own exact 4 supported values, used verbatim. */
enum RobotsDirective: string
{
    case Index = 'index';
    case Noindex = 'noindex';
    case Follow = 'follow';
    case Nofollow = 'nofollow';
}
