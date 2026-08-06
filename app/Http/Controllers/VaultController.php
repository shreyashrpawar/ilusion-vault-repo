<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\VaultFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class VaultController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $files = VaultFile::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();

        $formattedFiles = $files->map(function ($file) {
            return [
                'id' => $file->id,
                'encrypted_metadata' => $file->encrypted_metadata,
                'salt' => $file->salt,
                'iv' => $file->iv,
                'created_at' => $file->created_at->toIso8601String(),
                'download_url' => URL::temporarySignedRoute(
                    'vault.download',
                    now()->addMinutes(15),
                    ['path' => $file->file_path],
                    absolute: false
                ),
            ];
        });

        return response()->json($formattedFiles);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|file|max:102400',
            'file_metadata' => 'required|string',
        ]);

        $metadata = json_decode($request->input('file_metadata'), true) ?? [];
        $savedFiles = [];

        foreach ($request->file('files') as $index => $file) {
            $path = $file->store("vault/{$user->id}", 'r2');

            $vaultFile = VaultFile::create([
                'user_id' => $user->id,
                'file_path' => $path,
                'encrypted_metadata' => $metadata[$index]['encrypted_metadata'] ?? '',
                'salt' => $metadata[$index]['salt'] ?? '',
                'iv' => $metadata[$index]['iv'] ?? '',
            ]);

            $savedFiles[] = $vaultFile;
        }

        return response()->json(['message' => 'Files securely added to vault.', 'files' => $savedFiles], 201);
    }

    public function downloadFile(Request $request): StreamedResponse
    {
        if (!$request->hasHeader('X-Vault-Decrypted') && !$request->hasHeader('x-vault-decrypted')) {
            abort(403, 'Direct access to file downloads is not allowed. Files must be decrypted and requested through the application.');
        }

        $path = (string) $request->input('path');

        if (!Storage::disk('r2')->exists($path)) {
            abort(404, 'File not found.');
        }

        $headers = [
            'Content-Type' => 'application/octet-stream',
        ];

        return response()->streamDownload(function () use ($path) {
            $stream = Storage::disk('r2')->readStream($path);
            if ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, basename($path), $headers);
    }

    public function destroy(int|string $id): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $file = VaultFile::where('user_id', $user->id)->where('id', $id)->first();

        if (!$file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        Storage::disk('r2')->delete($file->file_path);
        $file->delete();

        return response()->json(['message' => 'File deleted successfully.']);
    }
}
