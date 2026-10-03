<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Install;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Destructive)]
#[TwoPhase]
final class RevokeInstall extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'revoke_install';

    protected string $description = 'Preview, then revoke one app-scoped install token without deleting its history.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'app_id' => $schema->integer()->min(1)->required(),
            'install_id' => $schema->string()->min(36)->max(36)->required(),
        ];
    }

    public function preview(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->arguments($request);

        return $this->respond(function () use ($arguments): array {
            $install = $this->install($arguments);

            return [
                'app_id' => $install->app_id,
                'install_id' => $install->install_uuid,
                'currently_revoked' => $install->revoked_at !== null,
                'history_preserved' => true,
            ];
        });
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->arguments($request);

        return $this->respond(function () use ($arguments): array {
            $install = $this->install($arguments);
            $alreadyRevoked = $install->revoked_at !== null;
            $install->forceFill(['revoked_at' => $install->revoked_at ?? now()])->save();

            return [
                'revoked' => ! $alreadyRevoked,
                'history_preserved' => true,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function arguments(Request $request): array
    {
        return $this->validateArguments($request, [
            'app_id' => ['required', 'integer', 'min:1'],
            'install_id' => ['required', 'uuid'],
        ], ['app_id', 'install_id']);
    }

    /** @param array<string, mixed> $arguments */
    private function install(array $arguments): Install
    {
        $app = $this->app((int) $arguments['app_id']);

        return Install::query()
            ->where('app_id', $app->id)
            ->where('install_uuid', $arguments['install_id'])
            ->firstOrFail();
    }
}
