<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * One granted permission for one role.
 *
 * Deliberately not Spatie's permission table: those are Shield's, they govern
 * the admin panel, and mixing the two is how the panel's list came to look
 * like it controlled the whole app.
 */
class RolePermission extends Model
{
    protected $fillable = ['role_id', 'permission'];

    protected static function booted(): void
    {
        static::saved(fn () => Permissions::forget());
        static::deleted(fn () => Permissions::forget());
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
