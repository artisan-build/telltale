<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\RejectIngestCredentialsFromMcp;
use App\Mcp\TelltaleMcpServer;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

final class TelltaleMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set([
            'built-for-cloud.mcp.path' => '/mcp',
            'built-for-cloud.mcp.write_path' => null,
            'built-for-cloud.mcp.destructive_path' => '/mcp/destructive',
            'built-for-cloud.mcp.delegated' => true,
            'built-for-cloud.mcp.two_phase.cache_store' => 'database',
        ]);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            Mcp::web('/mcp', TelltaleMcpServer::class)
                ->middleware([
                    RejectIngestCredentialsFromMcp::class,
                    'bfc.mcp:product,read',
                ]);
            Mcp::web('/mcp/destructive', TelltaleMcpServer::class)
                ->middleware([
                    RejectIngestCredentialsFromMcp::class,
                    'bfc.mcp:product,destructive',
                ]);
        });
    }
}
