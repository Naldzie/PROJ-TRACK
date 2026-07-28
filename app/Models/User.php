<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'role',
        'email',
        'profile_photo',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    public function staff()
    {
        return $this->hasOne(Staff::class);
    }

    public function contractor()
    {
        return $this->hasOne(Contractor::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors (IMPORTANT)
    |--------------------------------------------------------------------------
    */

    public function getDisplayNameAttribute()
    {
        return match ($this->role) {
            'admin' => $this->admin?->full_name ?? $this->name ?? $this->email,

            'staff' => trim(
                ($this->staff?->first_name ?? '') . ' ' .
                ($this->staff?->last_name ?? '')
            ) ?: $this->name ?? $this->email,

            'contractor' => $this->contractor?->contractor_name
                                    ?? $this->contractor?->company_name
                                    ?? $this->name
                                    ?? $this->email,

            default => $this->name ?? $this->email,
        };
    }

    public function getDisplayIdAttribute()
    {
        return match ($this->role) {
            'admin' => $this->admin?->admin_id ?? 'N/A',
            'staff' => $this->staff?->staff_id ?? 'N/A',
            'contractor' => $this->contractor?->contractor_id ?? 'N/A',
            default => 'N/A',
        };
    }
}