<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property string $fingerprint
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property string $first_version
 * @property string $last_version
 * @property int $total_occurrences
 * @property int $installs_affected
 * @property array<string, mixed> $sample_error
 * @property array<string, string> $sample_context
 */
final class ErrorGroup extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'fingerprint',
        'first_seen_at',
        'last_seen_at',
        'first_version',
        'last_version',
        'total_occurrences',
        'installs_affected',
        'sample_error',
        'sample_context',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'total_occurrences' => 'integer',
            'installs_affected' => 'integer',
            'sample_error' => 'array',
            'sample_context' => 'array',
        ];
    }

    /** @return BelongsTo<TrackedApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(TrackedApp::class, 'app_id');
    }
}
