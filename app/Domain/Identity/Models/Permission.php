<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Centrally-defined permission (Module 02 §6 "Centralized Permission
 * Definitions"). Permissions themselves are PLATFORM-level (not
 * tenant-scoped) — the same permission catalog ("products.create",
 * "orders.refund", etc.) is shared by every store; what differs per
 * store is which Roles a store chooses to grant which Permissions to,
 * via the tenant-scoped permission_role pivot.
 */
final class Permission extends Model
{
    use HasFactory;

    protected $table = 'permissions';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'group',
        'description',
    ];
}
