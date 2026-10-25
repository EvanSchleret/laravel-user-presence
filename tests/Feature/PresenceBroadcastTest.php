<?php

use EvanSchleret\LaravelUserPresence\Events\UserWentIdle;
use EvanSchleret\LaravelUserPresence\Events\UserWentOffline;
use EvanSchleret\LaravelUserPresence\Events\UserWentOnline;
use EvanSchleret\LaravelUserPresence\Tests\Fixtures\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00'));
    config()->set('user-presence.online_threshold', 300);
    config()->set('user-presence.idle_threshold', 600);
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

it('synchronizes idle and offline transitions once when scheduled', function (): void {
    Event::fake();

    $user = User::query()->create([
        'last_seen_at' => now()->subMinutes(7),
        'presence_broadcast_status' => 'online',
    ]);

    $this->artisan('user-presence:update-statuses')->assertExitCode(0);

    expect($user->fresh()->getAttribute('presence_broadcast_status'))->toBe('idle');
    Event::assertDispatched(UserWentIdle::class);
    Event::assertNotDispatched(UserWentOffline::class);

    $this->artisan('user-presence:update-statuses')->assertExitCode(0);
    Event::assertDispatchedTimes(UserWentIdle::class, 1);

    Carbon::setTestNow(now()->addMinutes(4));

    $this->artisan('user-presence:update-statuses')->assertExitCode(0);

    expect($user->fresh()->getAttribute('presence_broadcast_status'))->toBe('offline');
    Event::assertDispatchedTimes(UserWentOffline::class, 1);
});
