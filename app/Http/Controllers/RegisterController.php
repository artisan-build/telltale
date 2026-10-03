<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ingest\CredentialAuthenticator;
use App\Domain\Ingest\IngestHealthRecorder;
use App\Domain\Ingest\IngestRequestLimiter;
use App\Domain\Ingest\InstallRegistrar;
use App\Exceptions\InvalidIngestCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use JsonException;
use stdClass;

final class RegisterController extends Controller
{
    public function __invoke(
        Request $request,
        CredentialAuthenticator $authenticator,
        IngestRequestLimiter $limiter,
        IngestHealthRecorder $health,
        InstallRegistrar $registrar,
    ): JsonResponse {
        $ingestValue = (string) $request->header('X-Telltale-Ingest', '');
        $app = $authenticator->app($ingestValue);

        if ($app === null) {
            return $this->invalidCredential();
        }

        $limiter->consumeApp($app);

        try {
            $decoded = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $health->rejection($app);

            return response()->json(['message' => 'The registration payload is not valid JSON.'], 422);
        }

        if (! $decoded instanceof stdClass) {
            $health->rejection($app);

            return response()->json(['message' => 'The registration payload must be a JSON object.'], 422);
        }

        $validator = Validator::make(get_object_vars($decoded), [
            'install_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            $health->rejection($app);

            return response()->json([
                'message' => 'The registration payload is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $installUuid = $validator->validated()['install_id'];

        if (! is_string($installUuid)) {
            $health->rejection($app);

            return response()->json(['message' => 'The registration payload is invalid.'], 422);
        }

        $limiter->consumeInstall($app, $installUuid);

        try {
            $registration = $registrar->register($app, $ingestValue, $installUuid);
        } catch (InvalidIngestCredential) {
            $health->rejection($app);

            return $this->invalidCredential();
        }

        return response()->json([
            'install_token' => $registration->token(),
            'token_type' => 'Bearer',
        ], $registration->wasCreated() ? 201 : 200);
    }

    private function invalidCredential(): JsonResponse
    {
        return response()->json(['message' => 'Invalid credentials.'], 401);
    }
}
