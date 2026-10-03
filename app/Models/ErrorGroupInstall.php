<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $error_group_id
 * @property int $install_id
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property string $first_version
 * @property string $last_version
 * @property int $occurrence_count
 * @property array<string, mixed> $sample_error
 * @property array<string, string> $sample_context
 *
 * @mixin IdeHelperErrorGroupInstall
 */
final class ErrorGroupInstall extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'error_group_id',
        'install_id',
        'first_seen_at',
        'last_seen_at',
        'first_version',
        'last_version',
        'occurrence_count',
        'sample_error',
        'sample_context',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'occurrence_count' => 'integer',
            'sample_error' => 'array',
            'sample_context' => 'array',
        ];
    }

    /** @return BelongsTo<ErrorGroup, $this> */
    public function errorGroup(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class);
    }
}
