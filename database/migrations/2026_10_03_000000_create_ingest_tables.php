<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->char('ingest_key_hash', 64)->unique();
            $table->integer('rate_per_minute');
            $table->integer('install_rate_per_minute');
            $table->integer('daily_event_cap');
            $table->integer('install_daily_event_cap');
            $table->timestampsTz();
        });

        Schema::create('installs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->uuid('install_uuid');
            $table->char('token_hash', 64)->unique();
            $table->bigInteger('dropped_events_total')->default(0);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['app_id', 'install_uuid']);
            $table->unique(['app_id', 'id']);
        });

        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('install_id')->constrained('installs')->cascadeOnDelete();
            $table->char('event_id', 26);
            $table->string('name');
            $table->string('type', 32);
            $table->timestampTz('occurred_at');
            $table->string('session_id');
            $table->jsonb('props');
            $table->jsonb('error')->nullable();
            $table->string('client_version');
            $table->timestampsTz();

            $table->unique(['app_id', 'event_id']);
            $table->foreign(['app_id', 'install_id'])
                ->references(['app_id', 'id'])
                ->on('installs')
                ->cascadeOnDelete();
            $table->index(['app_id', 'created_at']);
            $table->index(['install_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('installs');
        Schema::dropIfExists('apps');
    }
};
