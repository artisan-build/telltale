<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SetupInstructions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class GenerateDocumentation extends Command
{
    protected $signature = 'telltale:generate-docs
        {--check : Fail instead of writing when a generated artifact is stale}
        {--local : Confirm that this filesystem operation targets the local checkout}';

    protected $description = 'Generate checked-in setup and Scalpels handoff documentation.';

    public function handle(SetupInstructions $instructions): int
    {
        if (! $this->option('local')) {
            $this->error('This command writes the local checkout. Pass --local explicitly.');

            return self::FAILURE;
        }

        $stale = [];

        foreach ($instructions->artifacts() as $relativePath => $contents) {
            $path = base_path($relativePath);

            if ($this->option('check')) {
                if (! File::exists($path) || File::get($path) !== $contents) {
                    $stale[] = $relativePath;
                }

                continue;
            }

            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
            $this->line("Generated {$relativePath}");
        }

        if ($stale !== []) {
            $this->error('Generated documentation is stale: '.implode(', ', $stale));

            return self::FAILURE;
        }

        if ($this->option('check')) {
            $this->info('Generated documentation is current.');
        }

        return self::SUCCESS;
    }
}
