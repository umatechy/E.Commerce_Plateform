<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The correlation ID of the work in progress (SRS API-011). A web
 * request gets it from AssignRequestId. Laravel's Context carries it
 * onto the jobs that request queues. Work that starts on its own — a
 * scheduled command — starts one here, so its log lines, audit entries
 * and the records it creates can still be tied together.
 */
final class RequestId
{
    public static function current(): ?string
    {
        $id = Context::get(AssignRequestId::ATTRIBUTE);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** For console work: keep an inherited ID, otherwise begin one, e.g. "backup-run-0b3c…". */
    public static function begin(string $prefix): string
    {
        $id = self::current() ?? Str::limit(Str::slug($prefix), 20, '').'-'.Str::uuid();
        Context::add(AssignRequestId::ATTRIBUTE, $id);

        return $id;
    }
}
