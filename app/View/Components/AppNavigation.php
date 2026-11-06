<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\TrackedApp;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;

/**
 * Every tracked app, listed in the sidebar so a person can go straight to one.
 * The first six show and the rest wait behind Show more, so a long list
 * neither overruns the sidebar nor makes it scroll.
 */
final class AppNavigation extends Component
{
    /** How many apps show before Show more. */
    public const int VISIBLE = 6;

    /** The most apps the sidebar ever lists, the bound the dashboard has always kept. */
    public const int LIMIT = 100;

    /** @var Collection<int, TrackedApp> */
    public Collection $apps;

    public ?int $currentAppId;

    public int $visible = self::VISIBLE;

    /**
     * Read the apps by creation order, the order the dashboard has always used.
     */
    public function __construct()
    {
        $this->apps = TrackedApp::query()->orderBy('id')->limit(self::LIMIT)->get(['id', 'name']);

        $current = Route::current()?->parameter('app');
        $this->currentAppId = $current instanceof TrackedApp ? $current->id : null;
    }

    /** Leave the sidebar section out entirely until there is an app to list. */
    public function shouldRender(): bool
    {
        return $this->apps->isNotEmpty();
    }

    public function render(): View
    {
        return view('components.app-navigation');
    }
}
