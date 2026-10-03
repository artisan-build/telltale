<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\ActiveUsers;
use App\Mcp\Tools\Apps;
use App\Mcp\Tools\DeleteInstall;
use App\Mcp\Tools\ErrorDetail;
use App\Mcp\Tools\Errors;
use App\Mcp\Tools\EventCounts;
use App\Mcp\Tools\Funnel;
use App\Mcp\Tools\IngestHealth;
use App\Mcp\Tools\InstallTimeline;
use App\Mcp\Tools\ReleaseAdoption;
use App\Mcp\Tools\Retention;
use App\Mcp\Tools\RevokeInstall;
use App\Mcp\Tools\RotateIngestKey;
use App\Mcp\Tools\ScreenFlow;
use App\Mcp\Tools\Sessions;
use Laravel\Mcp\Server;

final class TelltaleMcpServer extends Server
{
    protected string $name = 'Telltale';

    protected string $version = '1.0.0';

    protected string $instructions = 'Query bounded Telltale product analytics and error data. Destructive operations require a preview and single-use confirmation.';

    protected array $tools = [
        Apps::class,
        IngestHealth::class,
        EventCounts::class,
        ActiveUsers::class,
        ReleaseAdoption::class,
        ScreenFlow::class,
        Funnel::class,
        Retention::class,
        Sessions::class,
        Errors::class,
        ErrorDetail::class,
        InstallTimeline::class,
        DeleteInstall::class,
        RevokeInstall::class,
        RotateIngestKey::class,
    ];
}
