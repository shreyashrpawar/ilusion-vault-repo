<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Secret;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\SecretSent;

class SecretController extends Controller
{
    public function store(Request $request)
    {
        $user = auth()->guard('sanctum')->user() ?? auth()->user();

        $request->validate([
            'payload' => 'required|string',
            'expiry' => ['required', 'string', function ($attribute, $value, $fail) use ($user) {
                if (!$user && !in_array($value, ['1 Day', '1 Hour'])) {
                    $fail("Guest users can only set an expiry of 1 day or less.");
                }
            }],
            'burn_on_read' => 'boolean',
            'identifier' => 'nullable|string|max:255',
            'custom_address' => [
                'nullable', 
                'string', 
                'min:5', 
                'alpha_dash',
                'unique:secrets,secret_id',
                function ($attribute, $value, $fail) use ($user) {
                    if (!$user && !empty($value)) {
                        $fail("Guest users are not allowed to use a custom address.");
                    }
                }
            ],
            'recipient_email' => ['nullable', 'string', function ($attribute, $value, $fail) use ($user) {
                if (!$user && !empty($value)) {
                    $fail("Guest users are not allowed to send notifications to recipient emails.");
                }
                $emails = array_filter(array_map('trim', explode(',', $value)));
                foreach ($emails as $email) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $fail("The {$attribute} contains an invalid email address: {$email}.");
                    }
                }
            }],
            'encryption_hint' => 'nullable|string',
            'files' => ['nullable', 'array', function ($attribute, $value, $fail) use ($user) {
                if (!$user && !empty($value)) {
                    $fail("Guest users are not allowed to attach files.");
                }
            }],
            'files.*' => 'nullable|file|max:102400',
            'file_metadata' => 'nullable|string',
        ]);

        $expiryDate = Carbon::now();
        $expiryValue = $request->expiry;
        if (($expiryValue === 'Never' || $expiryValue === 'No Expiry') && !auth()->check()) {
            $expiryValue = '7 Days';
        }

        switch ($expiryValue) {
            case 'Never':
            case 'No Expiry':
                $expiryDate->addYears(100);
                break;
            case '15 Days':
                $expiryDate->addDays(15);
                break;
            case '7 Days':
                $expiryDate->addDays(7);
                break;
            case '1 Day':
                $expiryDate->addDays(1);
                break;
            case '1 Hour':
                $expiryDate->addHour();
                break;
            default:
                $expiryDate->addDays(7);
        }

        $secretId = $request->custom_address ?: Str::random(10);
        
        if (!$request->custom_address) {
            while (Secret::where('secret_id', $secretId)->exists()) {
                $secretId = Str::random(10);
            }
        }

        $filePaths = [];
        $metadata = [];
        if ($request->input('file_metadata')) {
            $metadata = json_decode($request->input('file_metadata'), true) ?? [];
        }

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $index => $file) {
                // Store file directly inside secrets/ directory using a random hash (without secretId subfolder)
                $path = $file->store('secrets', 'r2');
                
                $filePaths[] = [
                    'path' => $path,
                    'encrypted_metadata' => $metadata[$index]['encrypted_metadata'] ?? '',
                    'salt' => $metadata[$index]['salt'] ?? '',
                    'iv' => $metadata[$index]['iv'] ?? '',
                ];
            }
        }

        $secret = Secret::create([
            'secret_id' => $secretId,
            'identifier' => $request->identifier,
            'encrypted_payload' => $request->payload,
            'expiry_date' => $expiryDate,
            'burn_on_read' => $request->burn_on_read ?? false,
            'recipient_email' => $request->recipient_email,
            'encryption_hint' => $request->encryption_hint,
            'file_paths' => $filePaths,
            'user_id' => $user ? $user->id : null,
        ]);

        $secretUrl = url('/secret/' . $secret->secret_id);

        if ($request->recipient_email) {
            $emails = array_filter(array_map('trim', explode(',', $request->recipient_email)));
            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($email)->queue(
                        new SecretSent($secretUrl, $user ? $user->name : null)
                    );
                }
            }
        }

        return response()->json([
            'secret_id' => $secret->secret_id,
            'url' => $secretUrl,
            'identifier' => $secret->identifier,
            'created_at' => $secret->created_at->toIso8601String(),
            'expiry_date' => $secret->expiry_date->toIso8601String(),
            'burn_on_read' => $secret->burn_on_read,
        ]);
    }

    public function show($secretId)
    {
        $secret = Secret::where('secret_id', $secretId)->first();

        if (!$secret || $secret->expiry_date < Carbon::now()) {
            if ($secret) {
                // Cleanup R2 files
                if ($secret->file_paths) {
                    foreach ($secret->file_paths as $file) {
                        if (is_array($file) && isset($file['path'])) {
                            Storage::disk('r2')->delete($file['path']);
                        } elseif (is_string($file)) {
                            Storage::disk('r2')->delete($file);
                        }
                    }
                }
                $secret->delete();
            }
            return response()->json(['message' => 'Secret not found or expired.'], 404);
        }

        $filePaths = [];
        if ($secret->file_paths) {
            foreach ($secret->file_paths as $file) {
                if (is_array($file)) {
                    $filePaths[] = [
                        'download_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                            'secrets.file.download',
                            now()->addMinutes(15),
                            [
                                'path' => $file['path'],
                                'burn' => $secret->burn_on_read ? '1' : '0'
                            ]
                        ),
                        'encrypted_metadata' => $file['encrypted_metadata'],
                        'salt' => $file['salt'],
                        'iv' => $file['iv'],
                    ];
                } else {
                    // Fallback for legacy simple string paths
                    $filePaths[] = [
                        'download_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                            'secrets.file.download',
                            now()->addMinutes(15),
                            [
                                'path' => $file,
                                'burn' => $secret->burn_on_read ? '1' : '0'
                            ]
                        ),
                        'encrypted_metadata' => '',
                        'salt' => '',
                        'iv' => '',
                    ];
                }
            }
        }

        $payload = [
            'secret_id' => $secret->secret_id,
            'encrypted_payload' => $secret->encrypted_payload,
            'encryption_hint' => $secret->encryption_hint,
            'file_paths' => $filePaths,
            'burn_on_read' => $secret->burn_on_read,
        ];

        return response()->json($payload);
    }

    public function burn($secretId)
    {
        $secret = Secret::where('secret_id', $secretId)->first();

        if (!$secret || $secret->expiry_date < Carbon::now()) {
            return response()->json(['message' => 'Secret not found or expired.'], 404);
        }

        if (!$secret->burn_on_read) {
            return response()->json(['message' => 'This secret is not configured to burn on read.'], 400);
        }

        $secret->delete();

        return response()->json(['message' => 'Secret burned successfully.']);
    }

    public function downloadFile(Request $request)
    {
        if (!$request->hasHeader('X-Vault-Decrypted')) {
            abort(403, 'Direct access to file downloads is not allowed. Files must be decrypted and requested through the application.');
        }

        $path = $request->input('path');
        $burn = $request->input('burn');

        if (!Storage::disk('r2')->exists($path)) {
            abort(404, 'File not found or already deleted.');
        }

        $headers = [
            'Content-Type' => 'application/octet-stream',
        ];

        return response()->streamDownload(function () use ($path, $burn) {
            $stream = Storage::disk('r2')->readStream($path);
            
            if ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($burn == '1') {
                Storage::disk('r2')->delete($path);
            }
        }, basename($path), $headers);
    }

    public function destroy($secretId)
    {
        $secret = Secret::where('secret_id', $secretId)->first();

        if (!$secret) {
            return response()->json(['message' => 'Secret not found.'], 404);
        }

        // If the secret belongs to a registered user, only the owner can delete it.
        if ($secret->user_id !== null) {
            $currentUser = auth()->guard('sanctum')->user() ?? auth()->user();
            if (!$currentUser || $currentUser->id != $secret->user_id) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
        }

        // Delete associated files
        if ($secret->file_paths) {
            foreach ($secret->file_paths as $file) {
                if (is_array($file) && isset($file['path'])) {
                    Storage::disk('r2')->delete($file['path']);
                } elseif (is_string($file)) {
                    Storage::disk('r2')->delete($file);
                }
            }
        }

        $secret->delete();

        return response()->json(['message' => 'Secret deleted successfully.']);
    }
}
