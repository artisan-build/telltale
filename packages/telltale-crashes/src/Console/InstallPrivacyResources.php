<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleCrashes\Console;

use ArtisanBuild\TelltaleCrashes\Build\PrivacyManifest;
use Illuminate\Console\Command;

final class InstallPrivacyResources extends Command
{
    protected $signature = 'nativephp:telltale-crashes:privacy
        {--platform=} {--build-path=} {--plugin-path=} {--app-id=} {--config=} {--plugins=}';

    protected $description = 'Merge crash privacy declarations into the NativePHP iOS target';

    public function handle(PrivacyManifest $manifest): int
    {
        if ($this->option('platform') === 'ios') {
            $path = $this->option('build-path');
            if (! is_string($path) || $path === '') {
                return self::FAILURE;
            }
            $manifest->install($path);
        }

        return self::SUCCESS;
    }
}
