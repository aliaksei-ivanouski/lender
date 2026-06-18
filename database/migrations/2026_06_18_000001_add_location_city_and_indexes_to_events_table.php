<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('location_city', 100)->nullable()->after('longitude');
            $table->index('location_city');
            $table->index('created_time');
            $table->index(['status', 'created_time']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_location_city_index');
            $table->dropIndex('events_created_time_index');
            $table->dropIndex('events_status_created_time_index');
            $table->dropColumn('location_city');
        });
    }
};
