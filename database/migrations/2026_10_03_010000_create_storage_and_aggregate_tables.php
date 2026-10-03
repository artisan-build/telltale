<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installs', function (Blueprint $table): void {
            $table->timestampTz('first_seen_at', 6)->nullable();
            $table->timestampTz('context_observed_at', 6)->nullable();
            $table->jsonb('current_context')->nullable();
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->timestampTz('occurred_at', 6)->change();
        });

        Schema::create('telltale_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('install_id')->constrained('installs')->cascadeOnDelete();
            $table->timestampTz('started_at', 6);
            $table->timestampTz('ended_at', 6);
            $table->unsignedBigInteger('event_count');
            $table->unsignedBigInteger('screen_count');
            $table->timestampsTz();

            $table->unique(['app_id', 'id']);
            $table->foreign(['app_id', 'install_id'])
                ->references(['app_id', 'id'])
                ->on('installs')
                ->cascadeOnDelete();
            $table->index(['app_id', 'started_at']);
            $table->index(['install_id', 'started_at']);
        });

        Schema::create('error_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->timestampTz('first_seen_at', 6);
            $table->timestampTz('last_seen_at', 6);
            $table->text('first_version');
            $table->text('last_version');
            $table->unsignedBigInteger('total_occurrences');
            $table->unsignedBigInteger('installs_affected');
            $table->jsonb('sample_error');
            $table->jsonb('sample_context');
            $table->timestampsTz();

            $table->unique(['app_id', 'fingerprint']);
            $table->index(['app_id', 'last_seen_at']);
        });

        Schema::create('error_group_installs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('error_group_id')->constrained('error_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('install_id');
            $table->timestampTz('first_seen_at', 6);
            $table->timestampTz('last_seen_at', 6);
            $table->text('first_version');
            $table->text('last_version');
            $table->unsignedBigInteger('occurrence_count');
            $table->jsonb('sample_error');
            $table->jsonb('sample_context');
            $table->timestampsTz();

            $table->unique(['error_group_id', 'install_id']);
        });

        Schema::create('daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->date('aggregate_date');
            $table->string('dimension', 32);
            $table->char('dimension_key', 64);
            $table->text('dimension_value');
            $table->unsignedBigInteger('event_count');
            $table->timestampsTz();

            $table->unique(['app_id', 'aggregate_date', 'dimension', 'dimension_key']);
            $table->index(['app_id', 'dimension', 'aggregate_date']);
        });

        Schema::create('daily_active_installs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->date('active_date');
            $table->unsignedBigInteger('install_id');
            $table->text('app_version');
            $table->text('platform');
            $table->text('os');
            $table->timestampsTz();

            $table->unique(['app_id', 'active_date', 'install_id']);
            $table->index(['app_id', 'active_date']);
        });

        Schema::create('daily_new_installs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->date('first_seen_on');
            $table->unsignedBigInteger('install_id');
            $table->text('app_version');
            $table->timestampsTz();

            $table->unique(['app_id', 'install_id']);
            $table->index(['app_id', 'first_seen_on']);
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('server_session_id')->nullable()->constrained('telltale_sessions')->nullOnDelete();
            $table->foreignId('error_group_id')->nullable()->constrained('error_groups')->nullOnDelete();
            $table->text('app_version')->nullable();
            $table->text('platform')->nullable();
            $table->text('os')->nullable();
            $table->index(['app_id', 'name', 'occurred_at']);
            $table->index(['app_id', 'session_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn([
                'server_session_id',
                'error_group_id',
                'app_version',
                'platform',
                'os',
            ]);
        });

        Schema::dropIfExists('daily_new_installs');
        Schema::dropIfExists('daily_active_installs');
        Schema::dropIfExists('daily_aggregates');
        Schema::dropIfExists('error_group_installs');
        Schema::dropIfExists('error_groups');
        Schema::dropIfExists('telltale_sessions');

        Schema::table('installs', function (Blueprint $table): void {
            $table->dropColumn(['first_seen_at', 'context_observed_at', 'current_context']);
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->timestampTz('occurred_at')->change();
        });
    }
};
