<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->index(['status', 'reminder_3day_sent_at'], 'er_status_3day_idx');
            $table->index(['status', 'reminder_24hour_sent_at'], 'er_status_24h_idx');
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropIndex('er_status_3day_idx');
            $table->dropIndex('er_status_24h_idx');
        });
    }
};
