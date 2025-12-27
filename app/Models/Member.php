<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'telegram_username',
        'telegram_id',
        'image',
        'is_verified',
        'is_block',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_block' => 'boolean',
    ];

    /**
     * Get the documents for the member.
     */
    public function documents()
    {
        return $this->hasMany(Member_Document::class);
    }

    /**
     * Get the requests for the member.
     */
    public function requests()
    {
        return $this->hasMany(Member_Request::class);
    }
}
