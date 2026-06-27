<?php

use App\Models\Secret;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('r2');
});

test('can create a secret with encrypted metadata and files', function () {
    $user = User::factory()->create();
    $file1 = UploadedFile::fake()->create('document.pdf', 100);
    $file2 = UploadedFile::fake()->create('photo.jpg', 200);

    $metadata = [
        [
            'encrypted_metadata' => 'encrypted_pdf_meta_base64',
            'salt' => 'pdf_salt_base64',
            'iv' => 'pdf_iv_base64',
        ],
        [
            'encrypted_metadata' => 'encrypted_jpg_meta_base64',
            'salt' => 'jpg_salt_base64',
            'iv' => 'jpg_iv_base64',
        ],
    ];

    $response = $this->actingAs($user)->postJson(route('secrets.store'), [
        'payload' => 'encrypted_text_payload_base64',
        'expiry' => '1 Day',
        'burn_on_read' => true,
        'files' => [$file1, $file2],
        'file_metadata' => json_encode($metadata),
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['secret_id', 'url']);

    $secretId = $response->json('secret_id');
    $secret = Secret::where('secret_id', $secretId)->first();

    expect($secret)->not->toBeNull();
    expect($secret->burn_on_read)->toBeTrue();
    expect($secret->file_paths)->toBeArray()->toHaveCount(2);

    $firstFile = $secret->file_paths[0];
    expect($firstFile)->toHaveKeys(['path', 'encrypted_metadata', 'salt', 'iv']);
    expect($firstFile['encrypted_metadata'])->toBe('encrypted_pdf_meta_base64');
    expect($firstFile['salt'])->toBe('pdf_salt_base64');
    expect($firstFile['iv'])->toBe('pdf_iv_base64');

    // Verify files were stored directly in 'secrets' directory on R2 disk
    Storage::disk('r2')->assertExists($firstFile['path']);
    expect($firstFile['path'])->not->toContain($secretId);
});

test('retrieving secret returns signed routes and deletes secret if burn_on_read is true', function () {
    $file = UploadedFile::fake()->create('notes.txt', 50);

    $secret = Secret::create([
        'secret_id' => 'burnable_secret',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->addHour(),
        'burn_on_read' => true,
        'file_paths' => [
            [
                'path' => $file->store('secrets', 'r2'),
                'encrypted_metadata' => 'meta',
                'salt' => 'salt',
                'iv' => 'iv',
            ]
        ],
    ]);

    $response = $this->getJson(route('secrets.show', 'burnable_secret'));

    $response->assertOk();
    $response->assertJsonStructure([
        'encrypted_payload',
        'encryption_hint',
        'file_paths' => [
            '*' => ['download_url', 'encrypted_metadata', 'salt', 'iv']
        ],
        'burn_on_read'
    ]);

    // File should have a download URL containing the path and burn flag
    $downloadUrl = $response->json('file_paths.0.download_url');
    expect($downloadUrl)->toContain('burn=1');
    expect($downloadUrl)->toContain('path=secrets%2F');

    // Secret should NOT be deleted from DB because of new client-side burn flow
    expect(Secret::where('secret_id', 'burnable_secret')->exists())->toBeTrue();

    // Call burn API
    $burnResponse = $this->postJson(route('secrets.burn', 'burnable_secret'));
    $burnResponse->assertOk();

    // Secret should now be deleted from DB
    expect(Secret::where('secret_id', 'burnable_secret')->exists())->toBeFalse();
});

test('downloading file via signed route streams the file and deletes it if burn is 1', function () {
    $file = UploadedFile::fake()->create('notes.txt', 50);
    $path = $file->store('secrets', 'r2');

    // Generate signed route URL
    $signedUrl = URL::temporarySignedRoute(
        'secrets.file.download',
        now()->addMinutes(15),
        [
            'path' => $path,
            'burn' => '1',
        ]
    );

    // Request the download
    $response = $this->get($signedUrl, [
        'X-Vault-Decrypted' => '1'
    ]);

    $response->assertOk();
    $response->assertHeader('Content-Disposition', 'attachment; filename=' . basename($path));
    
    // File content is verified
    expect($response->streamedContent())->toBe($file->get());

    // File should be deleted from storage due to burn parameter
    Storage::disk('r2')->assertMissing($path);
});

test('cannot download file directly without decrypted app header', function () {
    $file = UploadedFile::fake()->create('notes.txt', 50);
    $path = $file->store('secrets', 'r2');

    $signedUrl = URL::temporarySignedRoute(
        'secrets.file.download',
        now()->addMinutes(15),
        [
            'path' => $path,
            'burn' => '1',
        ]
    );

    $response = $this->get($signedUrl);
    $response->assertStatus(403);
});

test('expired secrets delete R2 files and return 404', function () {
    $file = UploadedFile::fake()->create('old.txt', 50);
    $path = $file->store('secrets', 'r2');

    $secret = Secret::create([
        'secret_id' => 'expired_secret',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->subMinute(),
        'burn_on_read' => false,
        'file_paths' => [
            [
                'path' => $path,
                'encrypted_metadata' => 'meta',
                'salt' => 'salt',
                'iv' => 'iv',
            ]
        ],
    ]);

    $response = $this->getJson(route('secrets.show', 'expired_secret'));

    $response->assertStatus(404);

    // Database record and R2 files should be cleaned up
    expect(Secret::where('secret_id', 'expired_secret')->exists())->toBeFalse();
    Storage::disk('r2')->assertMissing($path);
});

test('logged in user can create a secret with Never expiry', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->postJson(route('secrets.store'), [
        'payload' => 'encrypted_text_payload_base64',
        'expiry' => 'Never',
        'burn_on_read' => false,
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['secret_id', 'url']);

    $secretId = $response->json('secret_id');
    $secret = Secret::where('secret_id', $secretId)->first();

    expect($secret)->not->toBeNull();
    // Expiry date should be roughly 100 years from now (at least 99 years)
    expect($secret->expiry_date->year)->toBeGreaterThan(now()->year + 99);
});

test('guest user cannot create a secret with more than 1 day expiry', function () {
    $response = $this->postJson(route('secrets.store'), [
        'payload' => 'encrypted_text_payload_base64',
        'expiry' => '7 Days',
        'burn_on_read' => false,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('expiry');
});

test('guest user cannot create a secret with file attachments', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100);
    $metadata = [
        [
            'encrypted_metadata' => 'meta',
            'salt' => 'salt',
            'iv' => 'iv',
        ],
    ];

    $response = $this->postJson(route('secrets.store'), [
        'payload' => 'encrypted_text_payload_base64',
        'expiry' => '1 Day',
        'burn_on_read' => false,
        'files' => [$file],
        'file_metadata' => json_encode($metadata),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('files');
});

test('can validate and queue emails to multiple recipients', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('secrets.store'), [
        'payload' => 'encrypted_text_payload_base64',
        'expiry' => '1 Day',
        'burn_on_read' => false,
        'recipient_email' => 'alice@example.com, bob@example.com, charlie@example.com',
    ]);

    $response->assertOk();

    $secretId = $response->json('secret_id');
    $secret = Secret::where('secret_id', $secretId)->first();
    expect($secret->recipient_email)->toBe('alice@example.com, bob@example.com, charlie@example.com');

    \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\SecretSent::class, 3);
});

test('fails validation for invalid emails in comma separated list', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->postJson(route('secrets.store'), [
        'payload' => 'encrypted_text_payload_base64',
        'expiry' => '1 Day',
        'burn_on_read' => false,
        'recipient_email' => 'alice@example.com, invalid-email, bob@example.com',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('recipient_email');
});

test('owner can delete their secret and associated files', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('mydoc.pdf', 100);
    $path = $file->store('secrets', 'r2');

    $secret = Secret::create([
        'secret_id' => 'my_secret',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->addHour(),
        'burn_on_read' => false,
        'user_id' => $user->id,
        'file_paths' => [
            [
                'path' => $path,
                'encrypted_metadata' => 'meta',
                'salt' => 'salt',
                'iv' => 'iv',
            ]
        ],
    ]);

    Storage::disk('r2')->assertExists($path);

    $response = $this->actingAs($user)->deleteJson(route('secrets.destroy', 'my_secret'));
    $response->assertOk();

    expect(Secret::where('secret_id', 'my_secret')->exists())->toBeFalse();
    Storage::disk('r2')->assertMissing($path);
});

test('another user cannot delete owner secret', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $secret = Secret::create([
        'secret_id' => 'owner_secret',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->addHour(),
        'burn_on_read' => false,
        'user_id' => $owner->id,
    ]);

    $response = $this->actingAs($other)->deleteJson(route('secrets.destroy', 'owner_secret'));
    $response->assertStatus(403);

    expect(Secret::where('secret_id', 'owner_secret')->exists())->toBeTrue();
});

test('anyone can delete a guest secret', function () {
    $file = UploadedFile::fake()->create('guest_doc.pdf', 100);
    $path = $file->store('secrets', 'r2');

    $secret = Secret::create([
        'secret_id' => 'guest_secret',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->addHour(),
        'burn_on_read' => false,
        'user_id' => null,
        'file_paths' => [
            [
                'path' => $path,
                'encrypted_metadata' => 'meta',
                'salt' => 'salt',
                'iv' => 'iv',
            ]
        ],
    ]);

    $response = $this->deleteJson(route('secrets.destroy', 'guest_secret'));
    $response->assertOk();

    expect(Secret::where('secret_id', 'guest_secret')->exists())->toBeFalse();
    Storage::disk('r2')->assertMissing($path);
});

test('can check status of secrets', function () {
    $activeSecret = Secret::create([
        'secret_id' => 'active_id',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->addHour(),
        'burn_on_read' => false,
    ]);

    $expiredSecret = Secret::create([
        'secret_id' => 'expired_id',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->subHour(),
        'burn_on_read' => false,
    ]);

    $response = $this->postJson(route('secrets.check'), [
        'ids' => ['active_id', 'expired_id', 'non_existent_id']
    ]);

    $response->assertOk();
    $response->assertExactJson([
        'existing' => ['active_id']
    ]);
});
