<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

use App\Exceptions\InvalidIngestCredential;
use App\Models\Install;
use App\Models\TrackedApp;
use Illuminate\Support\Facades\DB;

final class InstallRegistrar
{
    public function register(TrackedApp $app, string $ingestValue, string $installUuid): RegisteredInstall
    {
        return DB::transaction(function () use ($app, $ingestValue, $installUuid): RegisteredInstall {
            $lockedApp = TrackedApp::query()->whereKey($app->getKey())->lockForUpdate()->firstOrFail();

            if (! Credential::matches($ingestValue, 'tti', $lockedApp->ingest_key_hash)) {
                throw new InvalidIngestCredential;
            }

            $install = Install::query()
                ->where('app_id', $lockedApp->id)
                ->where('install_uuid', $installUuid)
                ->lockForUpdate()
                ->first();

            if ($install?->revoked_at !== null) {
                throw new InvalidIngestCredential;
            }

            $created = $install === null;
            $credential = Credential::issue('ttx');

            if ($install === null) {
                Install::query()->create([
                    'app_id' => $lockedApp->id,
                    'install_uuid' => $installUuid,
                    'token_hash' => $credential->hash(),
                    'dropped_events_total' => 0,
                    'revoked_at' => null,
                ]);
            } else {
                $install->forceFill([
                    'token_hash' => $credential->hash(),
                ])->save();
            }

            return new RegisteredInstall($credential->value(), $created);
        });
    }
}
