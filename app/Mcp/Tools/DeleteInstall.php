<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Storage\InstallDataDeleter;
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
final class DeleteInstall extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'delete_install';

    protected string $description = 'Preview, then delete one app-scoped install and its raw history while preserving durable aggregates.';

    public function __construct(private readonly InstallDataDeleter $deleter) {}

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
            $app = $this->app((int) $arguments['app_id']);
            $install = Install::query()
                ->where('app_id', $app->id)
                ->where('install_uuid', $arguments['install_id'])
                ->firstOrFail();

            return [
                'app_id' => $app->id,
                'install_id' => $install->install_uuid,
                'would_delete_events' => $install->events()->count(),
                'would_delete_sessions' => $install->sessions()->count(),
                'aggregates_preserved' => true,
            ];
        });
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->arguments($request);

        return $this->respond(function () use ($arguments): array {
            $app = $this->app((int) $arguments['app_id']);

            return [
                'deleted_events' => $this->deleter->delete($app, (string) $arguments['install_id']),
                'aggregates_preserved' => true,
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
}
