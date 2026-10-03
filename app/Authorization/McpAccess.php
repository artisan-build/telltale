<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final class McpAccess
{
    public function allows(): bool
    {
        return $this->principal() instanceof ActingPrincipal;
    }

    public function authorize(): ActingPrincipal
    {
        $principal = $this->principal();

        if (! $principal instanceof ActingPrincipal) {
            throw new AuthorizationException('This MCP principal is not authorized to read Telltale data.');
        }

        return $principal;
    }

    private function principal(): ?ActingPrincipal
    {
        $request = resolve('request');

        if (! $request instanceof Request) {
            return null;
        }

        $delegated = RequestAssertion::principal($request);

        if ($delegated instanceof ActingPrincipal) {
            return $delegated->principal instanceof DelegatedActor
                && $delegated->principal->isActive()
                && in_array($delegated->role, [ConsoleRole::Member, ConsoleRole::Admin], true)
                    ? $delegated
                    : null;
        }

        $credential = $request->user();

        if (! $credential instanceof Credential
            || $credential->purpose !== CredentialPurpose::Mcp
            || ! $credential->hasAbility(TelltaleAbility::Read->value)) {
            return null;
        }

        if ($credential->user_id !== null) {
            $user = User::query()->find($credential->user_id);

            if (! $user instanceof User
                || $user->status !== 'active'
                || ! RolePolicy::canUseProduct($user->role)) {
                return null;
            }
        }

        return ActingPrincipal::local('bfc', $credential);
    }
}
