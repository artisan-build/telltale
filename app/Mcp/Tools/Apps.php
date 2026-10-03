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
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class Apps extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'apps';

    protected string $description = 'List up to 100 tracked apps with last-seen activity, live versions, and cumulative client drops.';

    public function __construct(private readonly McpAnalytics $analytics) {}

    public function handle(Request $request): Response
    {
        $this->authorize();
        $this->validateArguments($request, [], []);

        return $this->respond(fn (): array => $this->analytics->apps());
    }
}
