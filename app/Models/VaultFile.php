<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VaultFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'file_path',
        'encrypted_metadata',
        'salt',
        'iv',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
