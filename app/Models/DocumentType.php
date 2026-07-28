<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'icon',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // === RELATIONSHIPS ===
    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    // === SCOPES ===
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // === ACCESSORS ===
    public function getDisplayNameAttribute()
    {
        return $this->name;
    }
}
