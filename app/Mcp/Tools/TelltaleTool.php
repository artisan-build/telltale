<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\McpAccess;
use App\Models\TrackedApp;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

abstract class TelltaleTool extends Tool
{
    public function shouldRegister(McpAccess $access): bool
    {
        return $access->allows();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    protected function authorize(): ActingPrincipal
    {
        return resolve(McpAccess::class)->authorize();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function validateArguments(Request $request, array $rules, array $keys): array
    {
        $unexpected = array_values(array_diff(array_keys($request->all()), $keys));

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'arguments' => 'Unexpected arguments: '.implode(', ', $unexpected).'.',
            ]);
        }

        return $request->validate($rules);
    }

    protected function app(int $id): TrackedApp
    {
        return TrackedApp::query()->findOrFail($id);
    }

    /** @param callable(): mixed $operation */
    protected function respond(callable $operation): Response
    {
        try {
            return Response::json($operation());
        } catch (ModelNotFoundException) {
            return Response::error('The requested Telltale resource was not found.');
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('Telltale could not complete the request.');
        }
    }
}
