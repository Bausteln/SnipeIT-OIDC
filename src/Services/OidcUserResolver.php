<?php

namespace Bausteln\SnipeitOidc\Services;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolve an OIDC-authenticated identity to a Snipe-IT User.
 *
 * The IdP has already verified the user; our job is to map claims to a
 * row in the `users` table — creating, updating, or rejecting as policy
 * dictates.
 */
class OidcUserResolver
{
    public function resolve(array $claims): ?User
    {
        $map = config('oidc.claim_map');

        $username = $claims[$map['username']] ?? null;
        $email    = $claims[$map['email']]    ?? null;

        if (! $username && ! $email) {
            return null; // Nothing stable to key on — refuse.
        }

        // Look existing user up by username first (Snipe-IT's primary identity
        // field), then fall back to email.
        $user = User::where('username', $username)->first()
            ?: User::where('email', $email)->first();

        if (! $user && config('oidc.provisioning') === 'existing') {
            return null; // Strict mode: don't create on the fly.
        }

        if (! $user) {
            $user = $this->provision($claims);
        } else {
            $this->syncFromClaims($user, $claims);
        }

        $this->applyGroupMapping($user, $claims);

        return $user;
    }

    private function provision(array $claims): User
    {
        $map = config('oidc.claim_map');

        $user = new User();
        $user->username   = $claims[$map['username']] ?? Str::before($claims[$map['email']], '@');
        $user->email      = $claims[$map['email']]    ?? null;
        $user->first_name = $claims[$map['first_name']] ?? '';
        $user->last_name  = $claims[$map['last_name']]  ?? '';
        $user->activated  = 1;
        // Random password — login is via OIDC only. Length matches Snipe-IT defaults.
        $user->password   = bcrypt(Str::random(40));
        $user->permissions = json_encode(config('oidc.default_permissions'));
        $user->save();

        return $user;
    }

    private function syncFromClaims(User $user, array $claims): void
    {
        $map = config('oidc.claim_map');

        // Keep names + email fresh — the IdP is the source of truth.
        $user->email      = $claims[$map['email']]      ?? $user->email;
        $user->first_name = $claims[$map['first_name']] ?? $user->first_name;
        $user->last_name  = $claims[$map['last_name']]  ?? $user->last_name;
        $user->save();
    }

    /**
     * Map OIDC group claims onto Snipe-IT permissions/groups.
     *
     * Policy: authoritative sync. The IdP is the source of truth, so every
     * login we (a) recompute the superuser flag from admin_groups and
     * (b) sync the user's Snipe-IT groups to exactly match the claim.
     *
     * Unknown claim values (no matching Snipe-IT group) are skipped
     * intentionally — auto-creating groups would let IdP typos pollute
     * Snipe-IT's permission model.
     */
    private function applyGroupMapping(User $user, array $claims): void
    {
        $map         = config('oidc.claim_map');
        $claimGroups = (array) ($claims[$map['groups']] ?? []);
        $adminGroups = config('oidc.admin_groups');

        $perms = json_decode($user->permissions, true) ?: [];
        if (array_intersect($claimGroups, $adminGroups)) {
            $perms['superuser'] = '1';
        } else {
            unset($perms['superuser']);
        }
        $user->permissions = json_encode($perms);
        $user->save();

        $groupIds = Group::whereIn('name', $claimGroups)->pluck('id')->all();
        $missing  = array_diff($claimGroups, Group::whereIn('name', $claimGroups)->pluck('name')->all());
        if ($missing) {
            Log::info('OIDC: skipping unknown groups from claim', ['user' => $user->username, 'missing' => array_values($missing)]);
        }

        $user->groups()->sync($groupIds);
    }
}
