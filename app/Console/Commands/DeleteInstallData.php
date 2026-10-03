<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Storage\InstallDataDeleter;
use App\Models\TrackedApp;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class DeleteInstallData extends Command
{
    protected $signature = 'telltale:delete-install {app : App database ID} {install : Install UUID}';

    protected $description = 'Delete one app-scoped install and its raw data while preserving historical aggregates.';

    public function handle(InstallDataDeleter $deleter): int
    {
        try {
            $app = TrackedApp::query()->findOrFail((int) $this->argument('app'));
            $deleted = $deleter->delete($app, (string) $this->argument('install'));
        } catch (ModelNotFoundException) {
            $this->error('No install with that UUID exists for the selected app.');

            return self::FAILURE;
        }

        $this->info("Deleted the install and {$deleted} raw event(s); historical aggregates were preserved.");

        return self::SUCCESS;
    }
}
