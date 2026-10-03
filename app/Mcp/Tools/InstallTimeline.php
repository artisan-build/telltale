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
final class InstallTimeline extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'install_timeline';

    protected string $description = 'Return up to 200 ordered retained events for exactly one app-scoped install or client session.';

    public function __construct(private readonly McpAnalytics $analytics) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'app_id' => $schema->integer()->min(1)->required(),
            'install_id' => $schema->string()->min(36)->max(36),
            'session_id' => $schema->string()->min(1)->max(255),
            'limit' => $schema->integer()->min(1)->max(200)->default(100),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->validateArguments($request, [
            'app_id' => ['required', 'integer', 'min:1'],
            'install_id' => ['required_without:session_id', 'prohibits:session_id', 'uuid'],
            'session_id' => ['required_without:install_id', 'prohibits:install_id', 'string', 'min:1', 'max:255'],
            'limit' => ['sometimes', 'integer', 'between:1,200'],
        ], ['app_id', 'install_id', 'session_id', 'limit']);

        return $this->respond(fn (): array => $this->analytics->installTimeline(
            $this->app((int) $arguments['app_id']),
            isset($arguments['install_id']) ? (string) $arguments['install_id'] : null,
            isset($arguments['session_id']) ? (string) $arguments['session_id'] : null,
            (int) ($arguments['limit'] ?? 100),
        ));
    }
}
