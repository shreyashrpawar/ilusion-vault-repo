<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use App\Mail\ContactMessage;

Route::get('/', function () {
    $secrets = [];
    if (auth()->check()) {
        $secrets = \App\Models\Secret::where('user_id', auth()->id())
            ->where('expiry_date', '>', now())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($secret) {
                return [
                    'secret_id' => $secret->secret_id,
                    'identifier' => $secret->identifier,
                    'url' => url('/secret/' . $secret->secret_id),
                    'expiry_date' => $secret->expiry_date->toIso8601String(),
                    'burn_on_read' => $secret->burn_on_read,
                    'recipient_email' => $secret->recipient_email,
                    'created_at' => $secret->created_at->toIso8601String(),
                ];
            });
    }
    return inertia('Welcome', [
        'secrets' => $secrets
    ]);
})->name('home');
Route::inertia('/create', 'Create')->name('secrets.create');
Route::inertia('/view', 'View')->name('secrets.retrieve');
Route::get('/secret/{id}', function ($id) {
    return inertia('View', [
        'initialSecretId' => $id
    ]);
})->name('secrets.view');

// Legal / Info Pages
Route::inertia('/contact', 'Contact')->name('contact');

Route::get('/vs/{competitor}', function ($competitor) {
    $competitor = strtolower($competitor);
    $validCompetitors = ['bitwarden-send', 'firefox-send', '1password-send'];
    if (!in_array($competitor, $validCompetitors)) {
        abort(404);
    }
    return inertia('Comparison', [
        'competitor' => $competitor
    ]);
})->name('comparison');

// Contact form submission
Route::post('/contact', function (Request $request) {
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email',
        'message' => 'required|string',
    ]);

    Mail::to('virat1vsdhoni1@gmail.com')->send(new ContactMessage(
        $request->input('name'),
        $request->input('email'),
        $request->input('message')
    ));
    return response()->json(['message' => 'Message sent successfully.']);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('profile', function () {
        $secrets = \App\Models\Secret::where('user_id', auth()->id())
            ->where('expiry_date', '>', now())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($secret) {
                return [
                    'secret_id' => $secret->secret_id,
                    'url' => url('/secret/' . $secret->secret_id),
                    'expiry_date' => $secret->expiry_date->toIso8601String(),
                    'burn_on_read' => $secret->burn_on_read,
                    'recipient_email' => $secret->recipient_email,
                    'created_at' => $secret->created_at->toIso8601String(),
                ];
            });

        return inertia('Profile', [
            'secrets' => $secrets,
        ]);
    })->name('profile');
});

use App\Http\Controllers\SecretController;

Route::post('/api/secrets', [SecretController::class, 'store'])
    ->middleware('throttle:secrets.create')
    ->name('secrets.store');
Route::post('/api/secrets/check', function (Request $request) {
    $request->validate([
        'ids' => 'required|array',
        'ids.*' => 'string',
    ]);

    $existingIds = \App\Models\Secret::whereIn('secret_id', $request->ids)
        ->where('expiry_date', '>', now())
        ->pluck('secret_id')
        ->toArray();

    return response()->json([
        'existing' => $existingIds
    ]);
})->name('secrets.check');
Route::get('/api/secrets/{secretId}', [SecretController::class, 'show'])
    ->middleware('throttle:secrets.view')
    ->name('secrets.show');
Route::delete('/api/secrets/{secretId}', [SecretController::class, 'destroy'])->name('secrets.destroy');
Route::post('/api/secrets/{secretId}/burn', [SecretController::class, 'burn'])->name('secrets.burn');
Route::get('/api/secrets/file/download', [SecretController::class, 'downloadFile'])
    ->name('secrets.file.download')
    ->middleware('signed');


require __DIR__.'/settings.php';
