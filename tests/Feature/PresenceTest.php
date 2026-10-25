<?php

use EvanSchleret\LaravelUserPresence\Events\UserWentIdle;
use EvanSchleret\LaravelUserPresence\Events\UserWentOffline;
use EvanSchleret\LaravelUserPresence\Events\UserWentOnline;
use EvanSchleret\LaravelUserPresence\Middleware\UpdateLastSeen;
use EvanSchleret\LaravelUserPresence\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00'));
    config()->set('user-presence.online_threshold', 300);
    config()->set('user-presence.idle_threshold', 600);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('normalizes uncast database timestamps when reading presence attributes', function (): void {
    $user = User::query()->create([
        'last_seen_at' => now()->subMinutes(7)->format('Y-m-d H:i:s'),
    ]);

    expect($user->is_online)->toBeFalse()
        ->and($user->is_idle)->toBeTrue()
        ->and($user->presence_status)->toBe('idle')
        ->and($user->last_seen_ago)->toBe('7 minutes ago');
});

it('dispatches online when a user has no previous timestamp', function (): void {
    Event::fake();

    $user = User::query()->create();
    $user->updatePresenceStatus();

    Event::assertDispatched(UserWentOnline::class);
    Event::assertNotDispatched(UserWentIdle::class);
    Event::assertNotDispatched(UserWentOffline::class);
    expect($user->fresh()->getAttribute('presence_broadcast_status'))->toBe('online');
});

it('reports an idle period before marking a returning user online', function (): void {
    Event::fake();

    $user = User::query()->create([
        'last_seen_at' => now()->subMinutes(7),
    ]);

    $user->updatePresenceStatus();

    Event::assertDispatched(UserWentIdle::class);
    Event::assertDispatched(UserWentOnline::class);
    Event::assertNotDispatched(UserWentOffline::class);
});

it('reports an offline period before marking a returning user online', function (): void {
    Event::fake();

    $user = User::query()->create([
        'last_seen_at' => now()->subMinutes(11),
    ]);

    $user->updatePresenceStatus();

    Event::assertDispatched(UserWentOffline::class);
    Event::assertDispatched(UserWentOnline::class);
});

it('does not dispatch duplicate transitions for a stale user instance', function (): void {
    Event::fake();

    $user = User::query()->create([
        'last_seen_at' => now()->subMinutes(11),
    ]);
    $firstRequest = $user->fresh();
    $concurrentRequest = $user->fresh();

    $firstRequest->updatePresenceStatus();
    $concurrentRequest->updatePresenceStatus();

    Event::assertDispatchedTimes(UserWentOffline::class, 1);
    Event::assertDispatchedTimes(UserWentOnline::class, 1);
});

it('updates the authenticated user through its middleware', function (): void {
    $user = User::query()->create([
        'last_seen_at' => now()->subMinutes(11),
    ]);

    $this->actingAs($user, 'web');

    $response = app(UpdateLastSeen::class)->handle(
        Request::create('/'),
        fn (Request $request): Response => new Response('ok'),
    );

    expect($response->getContent())->toBe('ok')
        ->and(Carbon::parse($user->fresh()->getAttribute('last_seen_at'))->equalTo(now()))->toBeTrue();
});
