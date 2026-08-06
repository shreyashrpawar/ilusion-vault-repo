<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Models\Secret;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    $expiredSecrets = Secret::where('expiry_date', '<', now())->get();
    
    foreach ($expiredSecrets as $secret) {
        if (!empty($secret->file_paths)) {
            foreach ($secret->file_paths as $file) {
                $filePath = is_array($file) ? ($file['path'] ?? null) : $file;
                if ($filePath) {
                    Storage::disk('r2')->delete($filePath);
                }
            }
        }
        $secret->delete();
    }
})->daily()->name('cleanup_expired_secrets')->withoutOverlapping();
