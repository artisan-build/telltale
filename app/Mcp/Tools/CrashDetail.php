<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Analytics\CrashQuery;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class CrashDetail extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'crash_detail';

    protected string $description = 'Return one retained app-scoped crash event, including bounded frames and binary symbol inputs.';

    public function __construct(private readonly CrashQuery $crashes) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'app_id' => $schema->integer()->min(1)->required(),
            'event_id' => $schema->string()->min(26)->max(26)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->validateArguments($request, [
            'app_id' => ['required', 'integer', 'min:1'],
            'event_id' => ['required', 'string', 'ulid'],
        ], ['app_id', 'event_id']);

        return $this->respond(fn (): array => $this->crashes->detail($this->app((int) $arguments['app_id']), (string) $arguments['event_id']));
    }
}
