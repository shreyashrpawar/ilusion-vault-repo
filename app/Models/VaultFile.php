<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @use HasFactory<\Database\Factories\VaultFileFactory>
 */
class VaultFile extends Model
{
    /** @use HasFactory<\Database\Factories\VaultFileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'file_path',
        'encrypted_metadata',
        'salt',
        'iv',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
