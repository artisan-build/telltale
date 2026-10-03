<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $app_id
 * @property int $install_id
 * @property string $first_seen_on
 * @property string $app_version
 *
 * @mixin IdeHelperDailyNewInstall
 */
final class DailyNewInstall extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'app_id',
        'first_seen_on',
        'install_id',
        'app_version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['first_seen_on' => 'immutable_date'];
    }
}
