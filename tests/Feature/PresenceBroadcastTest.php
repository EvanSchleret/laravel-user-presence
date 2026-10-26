<?php

use EvanSchleret\LaravelUserPresence\Events\UserWentIdle;
use EvanSchleret\LaravelUserPresence\Events\UserWentOffline;
use EvanSchleret\LaravelUserPresence\Events\UserWentOnline;
use EvanSchleret\LaravelUserPresence\Tests\Fixtures\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00'));
    config()->set('user-presence.online_threshold', 300);
    config()->set('user-presence.idle_threshold', 600);
    config()->set('user-presence.cache_store', 'array');
    config()->set('user-presence.store', 'cache');
    Cache::store('array')->flush();
    Event::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('broadcasts status changes to a private per-user Echo channel', function (): void {
    $user = User::query()->create();
    $event = new UserWentOnline($user);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-user-presence.'.$user->getKey())
        ->and($event->broadcastAs())->toBe('user.went.online')
        ->and($event->broadcastWith())->toBe([
            'user_id' => $user->getKey(),
            'status' => 'online',
        ]);
});

it('authorizes only the authenticated user on their channel by default', function (): void {
    $user = User::query()->create();
    $channels = Broadcast::connection()->getChannels();

    expect($channels['user-presence.{userId}']($user, (string) $user->getKey()))->toBeTrue()
        ->and($channels['user-presence.{userId}']($user, 'another-user'))->toBeFalse();
});

it('evaluates idle and offline transitions without a scheduled command', function (): void {
    $user = User::query()->create();
    $user->updatePresenceStatus();

    Carbon::setTestNow(now()->addSeconds(300));
    expect($user->presence_status)->toBe('idle');
    Event::assertDispatchedTimes(UserWentIdle::class, 1);

    Carbon::setTestNow(now()->addSeconds(300));
    expect($user->presence_status)->toBe('offline');
    Event::assertDispatchedTimes(UserWentOffline::class, 1);

    Event::assertDispatchedTimes(UserWentOnline::class, 1);
});

it('keeps the legacy command as an optional column-store status sweep', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->timestamp('last_seen_at')->nullable();
        $table->string('presence_broadcast_status')->nullable();
    });
    config()->set('user-presence.store', 'auto');
    $user = User::query()->create()->fresh();
    $user->updatePresenceStatus();
    Carbon::setTestNow(now()->addSeconds(300));

    $this->artisan('user-presence:update-statuses')->assertExitCode(0);

    expect($user->fresh()->presence_status)->toBe('idle')
        ->and($user->fresh()->getAttribute('presence_broadcast_status'))->toBe('idle');
    Event::assertDispatched(UserWentIdle::class);

    $this->artisan('user-presence:update-statuses')->assertExitCode(0);
    Event::assertDispatchedTimes(UserWentIdle::class, 1);

    Carbon::setTestNow(now()->addSeconds(300));
    $this->artisan('user-presence:update-statuses')->assertExitCode(0);

    expect($user->fresh()->presence_status)->toBe('offline');
    Event::assertDispatched(UserWentOffline::class);
});

it('does not sweep users when cache storage is active', function (): void {
    $user = User::query()->create();
    $user->updatePresenceStatus();
    Carbon::setTestNow(now()->addSeconds(300));

    $this->artisan('user-presence:update-statuses')->assertExitCode(0);

    Event::assertNotDispatched(UserWentIdle::class);
    expect($user->presence_status)->toBe('idle');
    Event::assertDispatched(UserWentIdle::class);
});

it('does not dispatch events when broadcasting is disabled', function (): void {
    config()->set('user-presence.broadcasting.enabled', false);
    $user = User::query()->create();

    $user->updatePresenceStatus();
    Carbon::setTestNow(now()->addSeconds(300));
    expect($user->presence_status)->toBe('idle');

    Event::assertNotDispatched(UserWentOnline::class);
    Event::assertNotDispatched(UserWentIdle::class);
    Event::assertNotDispatched(UserWentOffline::class);
});
