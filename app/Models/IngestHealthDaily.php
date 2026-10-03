<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $app_id
 * @property string $health_date
 * @property int $rejection_count
 * @property int $rate_limit_count
 */
final class IngestHealthDaily extends Model
{
    protected $table = 'ingest_health_daily';

    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'health_date',
        'rejection_count',
        'rate_limit_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'health_date' => 'immutable_date',
            'rejection_count' => 'integer',
            'rate_limit_count' => 'integer',
        ];
    }
}
