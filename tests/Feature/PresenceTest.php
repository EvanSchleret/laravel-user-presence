<?php

use EvanSchleret\LaravelUserPresence\Events\UserWentIdle;
use EvanSchleret\LaravelUserPresence\Events\UserWentOffline;
use EvanSchleret\LaravelUserPresence\Middleware\UpdateLastSeen;
use EvanSchleret\LaravelUserPresence\Services\PresenceService;
use EvanSchleret\LaravelUserPresence\Tests\Fixtures\AlternateUser;
use EvanSchleret\LaravelUserPresence\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00'));
    config()->set('user-presence.online_threshold', 300);
    config()->set('user-presence.idle_threshold', 600);
    config()->set('user-presence.store', 'cache');
    config()->set('user-presence.cache_store', 'array');
    Cache::store('array')->flush();
    Event::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('works without adding presence columns to the users table', function (): void {
    config()->set('user-presence.store', 'auto');
    $user = User::query()->create();

    $user->updatePresenceStatus();

    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'presence_broadcast_status'))->toBeFalse()
        ->and($user->fresh()->presence_status)->toBe('online');
});

it('calculates online, idle, and offline at the configured boundaries', function (): void {
    $user = User::query()->create();
    $user->updatePresenceStatus();

    expect($user->presence_status)->toBe('online')
        ->and($user->is_offline)->toBeFalse();

    Carbon::setTestNow(now()->addSeconds(299));
    expect($user->presence_status)->toBe('online');

    Carbon::setTestNow(now()->addSecond());
    expect($user->presence_status)->toBe('idle')
        ->and($user->is_idle)->toBeTrue()
        ->and($user->is_offline)->toBeTrue();

    Carbon::setTestNow(now()->addSeconds(299));
    expect($user->presence_status)->toBe('idle');

    Carbon::setTestNow(now()->addSecond());
    expect($user->presence_status)->toBe('offline')
        ->and($user->is_offline)->toBeTrue();
});

it('treats users without a retained activity as offline and reports never', function (): void {
    $user = User::query()->create();

    expect($user->presence_status)->toBe('offline')
        ->and($user->is_offline)->toBeTrue()
        ->and($user->is_idle)->toBeFalse()
        ->and($user->last_seen_ago)->toBe('never');

    Event::assertNotDispatched(UserWentOffline::class);
});

it('formats last seen from the retained cache timestamp', function (): void {
    $user = User::query()->create();
    $user->updatePresenceStatus();
    Carbon::setTestNow(now()->addMinutes(7));

    expect($user->last_seen_ago)->toBe('7 minutes ago');
});

it('supports a selected Laravel cache store', function (): void {
    $path = sys_get_temp_dir().'/laravel-user-presence-'.getmypid().'-'.spl_object_id($this);
    File::deleteDirectory($path);
    config()->set('cache.stores.file', [
        'driver' => 'file',
        'path' => $path,
    ]);
    config()->set('user-presence.cache_store', 'file');

    $user = User::query()->create();
    $user->updatePresenceStatus();

    expect($user->presence_status)->toBe('online');

    Cache::store('file')->flush();
    File::deleteDirectory($path);
});

it('namespaces cache entries by model class and identifier', function (): void {
    $user = User::query()->create();
    $alternateUser = new AlternateUser;
    $alternateUser->setRawAttributes(['id' => $user->getKey()]);
    $alternateUser->exists = true;

    $user->updatePresenceStatus();

    expect($user->presence_status)->toBe('online')
        ->and($alternateUser->presence_status)->toBe('offline');
});

it('keeps activity beyond the offline threshold when configured retention is too short', function (): void {
    config()->set('user-presence.cache_retention', 10);

    $user = User::query()->create();
    $user->updatePresenceStatus();
    Carbon::setTestNow(now()->addSeconds(600));

    expect($user->presence_status)->toBe('offline')
        ->and($user->last_seen_ago)->not->toBe('never');
});

it('treats an expired cache key as unknown instead of a calculated transition', function (): void {
    config()->set('user-presence.cache_retention', 10);
    $user = User::query()->create();
    $user->updatePresenceStatus();
    Carbon::setTestNow(now()->addSeconds(602));

    expect($user->presence_status)->toBe('offline')
        ->and($user->last_seen_ago)->toBe('never');

    Event::assertNotDispatched(UserWentOffline::class);
});

it('supports the legacy last seen column store', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->timestamp('last_seen_at')->nullable();
        $table->string('presence_broadcast_status')->nullable();
    });
    config()->set('user-presence.store', 'auto');

    $user = User::query()->create()->fresh();
    $user->updatePresenceStatus();

    expect(Carbon::parse($user->fresh()->getAttribute('last_seen_at'))->equalTo(now()))->toBeTrue()
        ->and($user->fresh()->getAttribute('presence_broadcast_status'))->toBe('online')
        ->and($user->presence_status)->toBe('online');

    Carbon::setTestNow(now()->addSeconds(300));
    expect($user->presence_status)->toBe('idle')
        ->and($user->fresh()->getAttribute('presence_broadcast_status'))->toBe('idle');
});

it('does not write on every request from the same session', function (): void {
    $user = User::query()->create();
    $presence = app(PresenceService::class);
    $presence->recordActivity($user, 'session:one');
    Carbon::setTestNow(now()->addSeconds(30));
    $presence->recordActivity($user, 'session:one');

    expect($presence->lastSeenAt($user)?->equalTo(now()->subSeconds(30)))->toBeTrue();
});

it('keeps a user online while another session remains active', function (): void {
    $user = User::query()->create();
    $presence = app(PresenceService::class);
    $presence->recordActivity($user, 'session:first');

    Carbon::setTestNow(now()->addSeconds(240));
    $presence->recordActivity($user, 'session:second');

    Carbon::setTestNow(now()->addSeconds(240));

    expect($presence->status($user))->toBe('online')
        ->and($presence->lastSeenAt($user)?->equalTo(now()->subSeconds(240)))->toBeTrue();
});

it('dispatches calculated transitions when the status is evaluated', function (): void {
    $user = User::query()->create();
    $user->updatePresenceStatus();
    Carbon::setTestNow(now()->addSeconds(300));

    expect($user->presence_status)->toBe('idle');
    Event::assertDispatchedTimes(UserWentIdle::class, 1);

    Carbon::setTestNow(now()->addSeconds(300));
    expect($user->presence_status)->toBe('offline');
    Event::assertDispatchedTimes(UserWentOffline::class, 1);
});

it('uses the same presence service from request middleware', function (): void {
    $user = User::query()->create();
    $this->actingAs($user, 'web');

    $response = app(UpdateLastSeen::class)->handle(
        Request::create('/'),
        fn (Request $request): Response => new Response('ok'),
    );

    expect($response->getContent())->toBe('ok')
        ->and($user->presence_status)->toBe('online');
});

it('can disable request middleware tracking through configuration', function (): void {
    config()->set('user-presence.middleware.enabled', false);
    $user = User::query()->create();
    $this->actingAs($user, 'web');

    app(UpdateLastSeen::class)->handle(
        Request::create('/'),
        fn (Request $request): Response => new Response('ok'),
    );

    expect(app(PresenceService::class)->lastSeenAt($user))->toBeNull();
});

it('accepts an authenticated heartbeat without trusting a client timestamp', function (): void {
    $user = User::query()->create();
    $this->actingAs($user, 'web');

    $this->postJson('/user-presence/heartbeat', ['last_seen_at' => '2000-01-01 00:00:00'])
        ->assertNoContent();

    expect(app(PresenceService::class)->lastSeenAt($user)?->equalTo(now()))->toBeTrue();
});

it('requires authentication for the heartbeat endpoint', function (): void {
    $this->postJson('/user-presence/heartbeat')->assertUnauthorized();
});

it('limits heartbeat requests per authenticated user', function (): void {
    $user = User::query()->create();
    $this->actingAs($user, 'web');
    $responses = [];

    for ($attempt = 0; $attempt < 61; $attempt++) {
        $responses[] = $this->postJson('/user-presence/heartbeat')->getStatusCode();
    }

    expect($responses)->toContain(429);
});
