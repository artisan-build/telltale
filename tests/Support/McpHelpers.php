<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Testing\TestResponse;

/** @param array<string, mixed> $arguments @return array<string, mixed> */
function mcpToolPayload(string $name, array $arguments = []): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $name.'-'.str()->random(8),
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ];
}

/** @param array<string, mixed> $payload */
function telltaleMcpRequest(string $path, array $payload, string $token): TestResponse
{
    return test()->postJson($path, $payload, ['Authorization' => 'Bearer '.$token]);
}

/** @param list<string> $abilities */
function telltaleMcpCredential(
    array $abilities,
    ?User $user = null,
    CredentialPurpose $purpose = CredentialPurpose::Mcp,
): string {
    $token = 'mcp-'.bin2hex(random_bytes(24));
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => $purpose,
        'subject_type' => $user instanceof User ? SubjectType::UserPrincipal : SubjectType::Installation,
        'subject_ref' => $user instanceof User ? 'user-'.$user->id : 'telltale-installation',
        'name' => 'Telltale MCP test',
        'abilities' => $abilities,
        'user_id' => $user instanceof User ? (string) $user->id : null,
        'secret_hash' => hash('sha256', $token),
        'status' => CredentialStatus::Active,
    ]);

    return $token;
}

/** @return array<string, mixed> */
function telltaleMcpTool(string $name, array $arguments, string $token): array
{
    return mcpContent(telltaleMcpRequest('/mcp', mcpToolPayload($name, $arguments), $token)->assertOk());
}

/** @return array<string, mixed> */
function mcpContent(TestResponse $response): array
{
    $text = $response->json('result.content.0.text');
    expect($text)->toBeString();
    expect($response->json('result.isError'))->not->toBeTrue($text);

    return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
}
