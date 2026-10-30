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
final class CrashFreeUsers extends TelltaleTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect, RespectsEffectCeiling;

    protected string $name = 'crash_free_users';

    protected string $description = 'Return an observed install-based crash-free proxy from retained ordinary and crash events, with OS coverage caveats.';

    public function __construct(private readonly CrashQuery $crashes) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'app_id' => $schema->integer()->min(1)->required(),
            'from' => $schema->string()->format('date-time'),
            'to' => $schema->string()->format('date-time'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $arguments = $this->validateArguments($request, [
            'app_id' => ['required', 'integer', 'min:1'],
            'from' => ['sometimes', 'string', 'max:64', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'to' => ['sometimes', 'string', 'max:64', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'],
        ], ['app_id', 'from', 'to']);
        [$from, $to] = $this->crashes->window($arguments['from'] ?? null, $arguments['to'] ?? null);

        return $this->respond(fn (): array => $this->crashes->freeUsers($this->app((int) $arguments['app_id']), $from, $to));
    }
}
