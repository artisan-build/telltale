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
final class EventCounts extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'event_counts';

    protected string $description = 'Return durable event or screen counts and an optional version, platform, OS, or locale breakdown.';

    public function __construct(private readonly McpAnalytics $analytics) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'app_id' => $schema->integer()->min(1)->required(),
            'days' => $schema->integer()->min(1)->max(90)->default(30),
            'metric' => $schema->string()->enum(['event', 'screen'])->default('event'),
            'breakdown' => $schema->string()->enum(['none', 'version', 'platform', 'os', 'locale'])->default('none'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->validateArguments($request, [
            'app_id' => ['required', 'integer', 'min:1'],
            'days' => ['sometimes', 'integer', 'between:1,90'],
            'metric' => ['sometimes', 'in:event,screen'],
            'breakdown' => ['sometimes', 'in:none,version,platform,os,locale'],
        ], ['app_id', 'days', 'metric', 'breakdown']);

        return $this->respond(fn (): array => $this->analytics->eventCounts(
            $this->app((int) $arguments['app_id']),
            (int) ($arguments['days'] ?? 30),
            (string) ($arguments['metric'] ?? 'event'),
            (string) ($arguments['breakdown'] ?? 'none'),
        ));
    }
}
