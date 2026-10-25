<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('renames the legacy timestamp and preserves its data on upgrade and rollback', function (): void {
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('last_activity_at')->nullable();
    });
    Schema::create('migrations', function (Blueprint $table): void {
        $table->id();
        $table->string('migration');
        $table->integer('batch');
    });

    DB::table('users')->insert([
        'last_activity_at' => '2026-10-26 10:00:00',
    ]);
    DB::table('migrations')->insert([
        'migration' => '2025_07_29_131846_add_last_activity_at_to_users_table',
        'batch' => 1,
    ]);

    $migration = require __DIR__.'/../../src/database/migrations/2026_10_26_000000_rename_last_activity_at_to_last_seen_at.php';
    $migration->up();

    expect(Schema::hasColumn('users', 'last_activity_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue()
        ->and(DB::table('users')->value('last_seen_at'))->toBe('2026-10-26 10:00:00');

    $migration->down();

    expect(Schema::hasColumn('users', 'last_activity_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'last_seen_at'))->toBeFalse()
        ->and(DB::table('users')->value('last_activity_at'))->toBe('2026-10-26 10:00:00');
});

it('keeps an existing last seen column when the legacy column is absent', function (): void {
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('last_seen_at')->nullable();
    });

    $migration = require __DIR__.'/../../src/database/migrations/2026_10_26_000000_rename_last_activity_at_to_last_seen_at.php';
    $migration->up();
    $migration->down();

    expect(Schema::hasColumn('users', 'last_seen_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'last_activity_at'))->toBeFalse();
});

it('adds and removes the presence broadcast status marker', function (): void {
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('last_seen_at')->nullable();
    });

    $migration = require __DIR__.'/../../src/database/migrations/2026_10_26_000001_add_presence_broadcast_status_to_users_table.php';
    $migration->up();

    expect(Schema::hasColumn('users', 'presence_broadcast_status'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('users', 'presence_broadcast_status'))->toBeFalse();
});
