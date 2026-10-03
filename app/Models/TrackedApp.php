<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrackedAppFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $ingest_key_hash
 * @property int $rate_per_minute
 * @property int $install_rate_per_minute
 * @property int $daily_event_cap
 * @property int $install_daily_event_cap
 *
 * @mixin IdeHelperTrackedApp
 */
final class TrackedApp extends Model
{
    /** @use HasFactory<TrackedAppFactory> */
    use HasFactory;

    protected $table = 'apps';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'ingest_key_hash',
        'rate_per_minute',
        'install_rate_per_minute',
        'daily_event_cap',
        'install_daily_event_cap',
    ];

    /** @var list<string> */
    protected $hidden = ['ingest_key_hash'];

    /** @return HasMany<Install, $this> */
    public function installs(): HasMany
    {
        return $this->hasMany(Install::class, 'app_id');
    }

    /** @return HasMany<StoredEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(StoredEvent::class, 'app_id');
    }

    /** @return HasMany<TrackedSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TrackedSession::class, 'app_id');
    }

    /** @return HasMany<ErrorGroup, $this> */
    public function errorGroups(): HasMany
    {
        return $this->hasMany(ErrorGroup::class, 'app_id');
    }
}
