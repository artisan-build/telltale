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
 * @property-read TrackedApp $app
 *
 * @mixin IdeHelperInstall
 */
final class Install extends Model
{
    /** @use HasFactory<InstallFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'install_uuid',
        'token_hash',
        'dropped_events_total',
        'revoked_at',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dropped_events_total' => 'integer',
            'revoked_at' => 'immutable_datetime',
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
}
