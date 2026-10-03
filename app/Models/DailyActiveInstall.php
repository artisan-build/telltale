<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $app_id
 * @property int $install_id
 * @property string $active_date
 * @property string $app_version
 * @property string $platform
 * @property string $os
 */
final class DailyActiveInstall extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'active_date',
        'install_id',
        'app_version',
        'platform',
        'os',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active_date' => 'immutable_date'];
    }
}
