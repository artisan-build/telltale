<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\InstallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $app_id
 * @property string $install_uuid
 * @property string $token_hash
 * @property int $dropped_events_total
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $context_observed_at
 * @property array<string, string>|null $current_context
 * @property-read TrackedApp $app
 *
 * @mixin IdeHelperInstall
 */
final class Install extends Model
{
    /** @use HasFactory<InstallFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'install_uuid',
        'token_hash',
        'dropped_events_total',
        'revoked_at',
        'first_seen_at',
        'context_observed_at',
        'current_context',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dropped_events_total' => 'integer',
            'revoked_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'context_observed_at' => 'immutable_datetime',
            'current_context' => 'array',
        ];
    }

    /** @return BelongsTo<TrackedApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(TrackedApp::class, 'app_id');
    }

    /** @return HasMany<StoredEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(StoredEvent::class, 'install_id');
    }

    /** @return HasMany<TrackedSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TrackedSession::class, 'install_id');
    }
}
