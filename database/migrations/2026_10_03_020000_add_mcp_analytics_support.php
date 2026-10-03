<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_health_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->date('health_date');
            $table->unsignedBigInteger('rejection_count')->default(0);
            $table->unsignedBigInteger('rate_limit_count')->default(0);
            $table->timestampsTz();

            $table->unique(['app_id', 'health_date']);
            $table->index(['app_id', 'health_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_health_daily');
    }
};
