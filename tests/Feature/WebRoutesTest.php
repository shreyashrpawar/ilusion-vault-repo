<?php

use App\Models\Secret;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('home page returns Welcome component with empty secrets for guest', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->has('secrets', 0)
    );
});

test('home page returns active secrets for authenticated user', function () {
    $user = User::factory()->create();
    
    // Create an active secret for user
    $activeSecret = Secret::create([
        'secret_id' => 'active_secret_id',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->addHour(),
        'burn_on_read' => false,
        'user_id' => $user->id,
    ]);

    // Create an expired secret for user
    $expiredSecret = Secret::create([
        'secret_id' => 'expired_secret_id',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->subHour(),
        'burn_on_read' => false,
        'user_id' => $user->id,
    ]);

    // Create an active secret for another user
    $otherUser = User::factory()->create();
    $otherSecret = Secret::create([
        'secret_id' => 'other_secret_id',
        'encrypted_payload' => 'payload',
        'expiry_date' => now()->addHour(),
        'burn_on_read' => false,
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('secrets', 1)
        ->where('secrets.0.secret_id', 'active_secret_id')
    );
});

test('all static legal and documentation pages load successfully', function () {
    $legalRoutes = [
        'contact',
    ];

    foreach ($legalRoutes as $routeName) {
        $response = $this->get(route($routeName));
        $response->assertOk();
    }
});

test('secret creation and viewing have rate limiters applied', function () {
    $routeStore = Route::getRoutes()->getByName('secrets.store');
    expect($routeStore->gatherMiddleware())->toContain('throttle:secrets.create');

    $routeShow = Route::getRoutes()->getByName('secrets.show');
    expect($routeShow->gatherMiddleware())->toContain('throttle:secrets.view');
});
