<?php

declare(strict_types=1);

namespace App\Models;

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
 */
final class StoredEvent extends Model
{
    /** @use HasFactory<StoredEventFactory> */
    use HasFactory;

    protected $table = 'events';

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
}
