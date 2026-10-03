<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ingest\CredentialAuthenticator;
use App\Domain\Ingest\EventIngestor;
use App\Domain\Ingest\IngestRequestLimiter;
use App\Exceptions\InvalidIngestCredential;
use ArtisanBuild\TelltaleContracts\ContractException;
use ArtisanBuild\TelltaleContracts\EnvelopeV1;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;

final class IngestController extends Controller
{
    public function __invoke(
        Request $request,
        CredentialAuthenticator $authenticator,
        IngestRequestLimiter $limiter,
        EventIngestor $ingestor,
    ): JsonResponse {
        $token = (string) $request->bearerToken();
        $install = $authenticator->install($token);

        if ($install === null) {
            return $this->invalidCredential();
        }

        $limiter->consumeApp($install->app);
        $limiter->consumeInstall($install->app, (string) $install->id);

        try {
            $decoded = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'The envelope is not valid JSON.'], 422);
        }

        if (! $decoded instanceof stdClass) {
            return response()->json(['message' => 'The envelope must be a JSON object.'], 422);
        }

        $payload = get_object_vars($decoded);
        $events = $payload['events'] ?? null;
        $maximumEvents = config('telltale.ingest.max_events_per_batch');

        if (is_array($events) && is_int($maximumEvents) && count($events) > $maximumEvents) {
            return response()->json([
                'message' => "A batch may contain at most {$maximumEvents} events.",
            ], 422);
        }

        try {
            $version = EnvelopeV1::versionFrom($payload);

            if ($version > EnvelopeV1::VERSION) {
                return response()->json([
                    'message' => "Envelope version {$version} is newer than this server supports. Upgrade the Telltale server before retrying.",
                    'supported_envelope_version' => EnvelopeV1::VERSION,
                ], 422);
            }

            $envelope = EnvelopeV1::fromArray($payload);
        } catch (ContractException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $result = $ingestor->ingest($install, $token, $envelope);
        } catch (InvalidIngestCredential) {
            return $this->invalidCredential();
        }

        return response()->json([
            'accepted' => $result->accepted,
            'duplicates' => $result->duplicates,
            'dropped_events_total' => $result->droppedEventsTotal,
        ], 202);
    }

    private function invalidCredential(): JsonResponse
    {
        return response()->json(['message' => 'Invalid credentials.'], 401);
    }
}
