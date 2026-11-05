<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppSession extends Model
{
    protected $fillable = [
        'principal_type', 'principal_id', 'token_hash', 'sii_token', 'expires_at', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'principal_id');
    }
}
