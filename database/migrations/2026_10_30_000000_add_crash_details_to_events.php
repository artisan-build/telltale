<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_event_prune_watermarks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->timestampTz('pruned_before');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->jsonb('crash')->nullable();
            $table->char('crash_report_id', 64)->nullable();
            $table->char('crash_fingerprint', 64)->nullable();
            $table->integer('crash_occurrence_count')->nullable();
            $table->boolean('crash_fatal')->nullable();
            $table->unique(['app_id', 'install_id', 'crash_report_id'], 'events_install_crash_report_unique');
            $table->index(['app_id', 'type', 'occurred_at'], 'events_crash_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_event_prune_watermarks');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropUnique('events_install_crash_report_unique');
            $table->dropIndex('events_crash_window_index');
            $table->dropColumn(['crash', 'crash_report_id', 'crash_fingerprint', 'crash_occurrence_count', 'crash_fatal']);
        });
    }
};
