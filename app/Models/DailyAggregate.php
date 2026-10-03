<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AggregateDimension;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $app_id
 * @property string $aggregate_date
 * @property AggregateDimension $dimension
 * @property string $dimension_key
 * @property string $dimension_value
 * @property int $event_count
 */
final class DailyAggregate extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'aggregate_date',
        'dimension',
        'dimension_key',
        'dimension_value',
        'event_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'aggregate_date' => 'immutable_date',
            'dimension' => AggregateDimension::class,
            'event_count' => 'integer',
        ];
    }
}
