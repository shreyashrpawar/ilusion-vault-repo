<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Secret extends Model
{
    protected $fillable = [
        'secret_id',
        'identifier',
        'encrypted_payload',
        'expiry_date',
        'burn_on_read',
        'recipient_email',
        'encryption_hint',
        'file_paths',
        'user_id',
    ];

    protected $casts = [
        'expiry_date' => 'datetime',
        'burn_on_read' => 'boolean',
        'file_paths' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
