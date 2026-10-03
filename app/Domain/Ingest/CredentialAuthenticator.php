<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Models\Install;
use App\Models\TrackedApp;

final class CredentialAuthenticator
{
    public function app(string $value): ?TrackedApp
    {
        if (! Credential::hasFormat($value, 'tti')) {
            return null;
        }

        $app = TrackedApp::query()->where('ingest_key_hash', Credential::hash($value))->first();

        if ($app === null || ! Credential::matches($value, 'tti', $app->ingest_key_hash)) {
            return null;
        }

        return $app;
    }

    public function install(string $value): ?Install
    {
        if (! Credential::hasFormat($value, 'ttx')) {
            return null;
        }

        $install = Install::query()
            ->with('app')
            ->where('token_hash', Credential::hash($value))
            ->whereNull('revoked_at')
            ->first();

        if ($install === null || ! Credential::matches($value, 'ttx', $install->token_hash)) {
            return null;
        }

        return $install;
    }
}
