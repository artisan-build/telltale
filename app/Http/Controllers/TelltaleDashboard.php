<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\McpAnalytics;
use App\Domain\Ingest\AppManager;
use App\Models\TrackedApp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final readonly class TelltaleDashboard
{
    public function __construct(
        private AppManager $apps,
        private McpAnalytics $analytics,
    ) {}

    public function __invoke(): View
    {
        return $this->render();
    }

    public function show(TrackedApp $app): View
    {
        return $this->render($app);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate(['name' => ['required', 'string']]);
        $name = (string) $validated['name'];

        if (
            preg_match('//u', $name) !== 1
            || strlen($name) > AppManager::MAX_NAME_LENGTH
            || str_contains($name, "\0")
        ) {
            throw ValidationException::withMessages([
                'name' => 'The app name must be valid UTF-8 text no longer than 255 bytes.',
            ]);
        }

        $created = $this->apps->create($name);

        return $this->oneTimeResponse($created->app(), $created->ingestValue(), 'created');
    }

    public function rotate(TrackedApp $app): Response
    {
        return $this->oneTimeResponse($app, $this->apps->rotateIngestKey($app), 'rotated');
    }

    private function render(?TrackedApp $selected = null): View
    {
        return view('dashboard', $this->viewData($selected));
    }

    private function oneTimeResponse(TrackedApp $selected, string $ingestValue, string $credentialAction): Response
    {
        return response()->view(
            'dashboard',
            $this->viewData($selected, $ingestValue, $credentialAction),
            headers: [
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function viewData(
        ?TrackedApp $selected = null,
        ?string $ingestValue = null,
        ?string $credentialAction = null,
    ): array {
        $apps = $this->analytics->apps();

        if ($selected === null && $apps !== []) {
            $selected = TrackedApp::query()->findOrFail($apps[0]['id']);
        }

        return [
            'apps' => $apps,
            'selected' => $selected,
            'health' => $selected === null ? null : $this->analytics->ingestHealth($selected, 30),
            'ingestValue' => $ingestValue,
            'credentialAction' => $credentialAction,
        ];
    }
}
