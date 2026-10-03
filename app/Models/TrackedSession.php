<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $app_id
 * @property int $install_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $ended_at
 * @property int $event_count
 * @property int $screen_count
 *
 * @mixin IdeHelperTrackedSession
 */
final class TrackedSession extends Model
{
    protected $table = 'telltale_sessions';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'install_id',
        'started_at',
        'ended_at',
        'event_count',
        'screen_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'event_count' => 'integer',
            'screen_count' => 'integer',
        ];
    }

    /** @return BelongsTo<TrackedApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(TrackedApp::class, 'app_id');
    }

    /** @return BelongsTo<Install, $this> */
    public function install(): BelongsTo
    {
        return $this->belongsTo(Install::class, 'install_id');
    }

    /** @return HasMany<StoredEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(StoredEvent::class, 'server_session_id');
    }
}
