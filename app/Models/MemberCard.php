<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberCard extends Model
{
    protected $table = 'member_cards';

    protected $fillable = [
        'member_id',
        'card',
        'use',
    ];

    protected $attributes = [
        'use' => '0',
    ];

    /**
     * Get the member that owns the card.
     */
    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
