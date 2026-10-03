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
final class Funnel extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'funnel';

    protected string $description = 'Calculate ordered per-install funnel conversion and drop-off from retained raw events.';

    public function __construct(private readonly McpAnalytics $analytics) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'app_id' => $schema->integer()->min(1)->required(),
            'steps' => $schema->array()->items($schema->string()->min(1)->max(255))->min(1)->max(10)->required(),
            'days' => $schema->integer()->min(1)->max(90)->default(30),
            'window_minutes' => $schema->integer()->min(1)->max(10080)->default(1440),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->validateArguments($request, [
            'app_id' => ['required', 'integer', 'min:1'],
            'steps' => ['required', 'array', 'between:1,10'],
            'steps.*' => ['string', 'min:1', 'max:255', 'distinct'],
            'days' => ['sometimes', 'integer', 'between:1,90'],
            'window_minutes' => ['sometimes', 'integer', 'between:1,10080'],
        ], ['app_id', 'steps', 'days', 'window_minutes']);

        /** @var non-empty-list<string> $steps */
        $steps = array_values($arguments['steps']);

        return $this->respond(fn (): array => $this->analytics->funnel(
            $this->app((int) $arguments['app_id']),
            $steps,
            (int) ($arguments['days'] ?? 30),
            (int) ($arguments['window_minutes'] ?? 1440),
        ));
    }
}
