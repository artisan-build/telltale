<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StoredEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property int $install_id
 * @property string $event_id
 * @property string $name
 * @property string $type
 * @property CarbonImmutable $occurred_at
 * @property string $session_id
 * @property array<string, mixed> $props
 * @property array<string, mixed>|null $error
 * @property string $client_version
 * @property int|null $server_session_id
 * @property int|null $error_group_id
 * @property string|null $app_version
 * @property string|null $platform
 * @property string|null $os
 *
 * @mixin IdeHelperStoredEvent
 */
final class StoredEvent extends Model
{
    /** @use HasFactory<StoredEventFactory> */
    use HasFactory;

    protected $table = 'events';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'install_id',
        'event_id',
        'name',
        'type',
        'occurred_at',
        'session_id',
        'props',
        'error',
        'client_version',
        'server_session_id',
        'error_group_id',
        'app_version',
        'platform',
        'os',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'props' => 'array',
            'error' => 'array',
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

    /** @return BelongsTo<TrackedSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrackedSession::class, 'server_session_id');
    }

    /** @return BelongsTo<ErrorGroup, $this> */
    public function errorGroup(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class, 'error_group_id');
    }
}
