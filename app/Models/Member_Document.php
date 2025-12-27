<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member_Document extends Model
{
    protected $table = 'member_documents';

    protected $fillable = [
        'member_id',
        'name',
        'file_type',
        'file_url',
        'file_path',
    ];

    /**
     * Get the member that owns the document.
     */
    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}

