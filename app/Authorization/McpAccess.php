<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Exceptions\DelegatedPrincipalUnsupported;
use ArtisanBuild\BuiltForCloud\Exceptions\NoActingPrincipal;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final class McpAccess
{
    public function __construct(
        private readonly ActingPrincipalResolver $principals,
        private readonly Request $request,
    ) {}

    public function allows(): bool
    {
        return $this->authorized($this->principals->acting($this->request)) instanceof ActingPrincipal;
    }

    public function authorize(): ActingPrincipal
    {
        try {
            $principal = $this->principals->require($this->request);
        } catch (DelegatedPrincipalUnsupported|NoActingPrincipal) {
            throw new AuthorizationException('This MCP principal is not authorized to read Telltale data.');
        }

        $principal = $this->authorized($principal);

        if (! $principal instanceof ActingPrincipal) {
            throw new AuthorizationException('This MCP principal is not authorized to read Telltale data.');
        }

        return $principal;
    }

    private function authorized(ActingPrincipal $principal): ?ActingPrincipal
    {
        if ($principal->delegated) {
            return in_array($principal->role, [ConsoleRole::Member, ConsoleRole::Admin], true)
                ? $principal
                : null;
        }

        $credential = $principal->principal;

        if (! $credential instanceof Credential
            || $credential->purpose !== CredentialPurpose::Mcp
            || ! $credential->hasAbility(TelltaleAbility::Read->value)) {
            return null;
        }

        if ($credential->user_id !== null) {
            $user = $this->principals->localUser($this->request);

            if (! $user instanceof User
                || $user->status !== 'active'
                || ! RolePolicy::canUseProduct($user->role)) {
                return null;
            }
        }

        return $principal;
    }
}
