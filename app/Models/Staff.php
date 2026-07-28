<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Staff extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'staff_id',
        'first_name',
        'last_name',
        'profession',
        'contact_number',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}