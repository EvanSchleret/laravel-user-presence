<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'presence_broadcast_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('presence_broadcast_status')->nullable();
                $table->index(['presence_broadcast_status', 'last_seen_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'presence_broadcast_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['presence_broadcast_status', 'last_seen_at']);
                $table->dropColumn('presence_broadcast_status');
            });
        }
    }
};
