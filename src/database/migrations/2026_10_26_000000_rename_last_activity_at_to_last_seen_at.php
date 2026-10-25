<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'last_activity_at') && ! Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->renameColumn('last_activity_at', 'last_seen_at');
            });
        }
    }

    public function down(): void
    {
        if ($this->originalMigrationHasRun()
            && Schema::hasColumn('users', 'last_seen_at')
            && ! Schema::hasColumn('users', 'last_activity_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->renameColumn('last_seen_at', 'last_activity_at');
            });
        }
    }

    private function originalMigrationHasRun(): bool
    {
        return Schema::hasTable('migrations')
            && DB::table('migrations')
                ->where('migration', 'like', '%add_last_activity_at_to_users_table')
                ->exists();
    }
};
