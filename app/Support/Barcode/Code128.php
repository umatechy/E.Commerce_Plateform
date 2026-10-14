<?php

declare(strict_types=1);

namespace App\Support\Barcode;

/**
 * Phase B49: Code 128 (code set B) for tracking numbers on shipping labels,
 * as an SVG — no dependency. Code set B covers printable ASCII (space to ~),
 * which is what tracking and order numbers use.
 *
 * Each symbol is six alternating bar/space widths (11 modules), the stop
 * symbol seven (13 modules); the check symbol is the weighted sum mod 103.
 * The human-readable number is always printed under the bars, so a label
 * stays usable if a scanner cannot read them.
 */
final class Code128
{
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /** @return list<int> the symbol values, start and check and stop included */
    public static function values(string $text): array
    {
        if ($text === '' || preg_match('/^[\x20-\x7E]+$/', $text) !== 1) {
            throw new \InvalidArgumentException('Code 128 B takes printable ASCII only.');
        }
        $values = [self::START_B];
        $sum = self::START_B;
        foreach (str_split($text) as $position => $char) {
            $value = ord($char) - 32;
            $values[] = $value;
            $sum += $value * ($position + 1);
        }
        $values[] = $sum % 103;
        $values[] = self::STOP;

        return $values;
    }

    /** @return list<int> module widths, bar first, alternating with spaces */
    public static function widths(string $text): array
    {
        $widths = [];
        foreach (self::values($text) as $value) {
            foreach (str_split(self::PATTERNS[$value]) as $digit) {
                $widths[] = (int) $digit;
            }
        }

        return $widths;
    }

    /** The bars as an SVG (quiet zone of ten modules on each side). */
    public static function svg(string $text, int $moduleWidth = 2, int $height = 60): string
    {
        $x = 10 * $moduleWidth;
        $rects = '';
        foreach (self::widths($text) as $index => $width) {
            if ($index % 2 === 0) {
                $rects .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $x, $width * $moduleWidth, $height);
            }
            $x += $width * $moduleWidth;
        }
        $total = $x + 10 * $moduleWidth;

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g></svg>', $total, $height, $total, $height, $rects);
    }

    /** @return list<string> */
    public static function patterns(): array
    {
        return self::PATTERNS;
    }
}
