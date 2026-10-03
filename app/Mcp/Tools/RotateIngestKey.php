<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Ingest\AppManager;
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
final class RotateIngestKey extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'rotate_ingest_key';

    protected string $description = 'Preview, then invalidate one app ingest key and return its replacement exactly once.';

    public function __construct(private readonly AppManager $apps) {}

    public function schema(JsonSchema $schema): array
    {
        return ['app_id' => $schema->integer()->min(1)->required()];
    }

    public function preview(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->arguments($request);

        return $this->respond(function () use ($arguments): array {
            $app = $this->app((int) $arguments['app_id']);

            return [
                'app_id' => $app->id,
                'app_name' => $app->name,
                'old_key_will_be_invalidated' => true,
                'new_key_returned_once' => true,
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
                'app_id' => $app->id,
                'ingest_key' => $this->apps->rotateIngestKey($app),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function arguments(Request $request): array
    {
        return $this->validateArguments($request, [
            'app_id' => ['required', 'integer', 'min:1'],
        ], ['app_id']);
    }
}
