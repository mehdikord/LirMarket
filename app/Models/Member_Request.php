<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member_Request extends Model
{
    protected $table = 'member_requests';

    protected $fillable = [
        'member_id',
        'from',
        'to',
        'amount',
        'file_url',
        'receive_code',
        'recieve_name',
        'status',
        'code',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Get the member that owns the request.
     */
    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}

