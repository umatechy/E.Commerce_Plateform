<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

/** Module 33 §11 "Supported Value Types" — only the types B17's actual 6 seeded settings need. */
enum SettingType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case StringArray = 'string_array';
    case Secret = 'secret'; // Module 33 §16 — Laravel's real Crypt facade, see inspection findings
}
