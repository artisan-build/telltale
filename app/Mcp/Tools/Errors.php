<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Analytics\McpAnalytics;
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
final class Errors extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'errors';

    protected string $description = 'List bounded app-scoped error groups with counts, affected installs, versions, and new-since status.';

    public function __construct(private readonly McpAnalytics $analytics) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'app_id' => $schema->integer()->min(1)->required(),
            'days' => $schema->integer()->min(1)->max(90)->default(30),
            'new_since_version' => $schema->string()->min(1)->max(100),
            'limit' => $schema->integer()->min(1)->max(50)->default(25),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->validateArguments($request, [
            'app_id' => ['required', 'integer', 'min:1'],
            'days' => ['sometimes', 'integer', 'between:1,90'],
            'new_since_version' => ['sometimes', 'string', 'min:1', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ], ['app_id', 'days', 'new_since_version', 'limit']);

        return $this->respond(fn (): array => $this->analytics->errors(
            $this->app((int) $arguments['app_id']),
            (int) ($arguments['days'] ?? 30),
            isset($arguments['new_since_version']) ? (string) $arguments['new_since_version'] : null,
            (int) ($arguments['limit'] ?? 25),
        ));
    }
}
