<?php

namespace Bausteln\SnipeitOidc\Services;

use App\Models\Group;
use App\Models\User;
use Bausteln\SnipeitOidc\Support\NameSplitter;
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
        // field), then fall back to email. The default Eloquent query excludes
        // soft-deleted rows via the SoftDeletes trait's global scope.
        $user = User::where('username', $username)->first()
            ?: User::where('email', $email)->first();

        // If no active match, check whether a soft-deleted record blocks this
        // identity. Auto-provisioning would either fail (unique_undeleted) or
        // create a parallel account with no asset history — both bad. Refuse
        // login and surface the case clearly so an admin can either restore
        // the user in Snipe-IT or remove them from the IdP.
        if (! $user) {
            $trashed = User::onlyTrashed()
                ->where(function ($q) use ($username, $email) {
                    if ($username) { $q->where('username', $username); }
                    if ($email)    { $q->orWhere('email', $email); }
                })
                ->first();
            if ($trashed) {
                Log::warning('OIDC: refusing login — Snipe-IT account is soft-deleted', [
                    'username'        => $username,
                    'email'           => $email,
                    'trashed_user_id' => $trashed->id,
                    'hint'            => 'Restore the user in Snipe-IT or remove them from the IdP.',
                ]);
                return null;
            }
        }

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

        $email     = $claims[$map['email']]      ?? null;
        $username  = $claims[$map['username']]   ?? ($email ? Str::before($email, '@') : null);
        // Prefer explicit given_name/family_name; otherwise split a combined
        // name (e.g. given_name = "Andrin Monn" with no family_name).
        [$firstName, $lastName] = $this->resolveName($claims);

        // Snipe-IT's ValidatingTrait enforces `first_name => required` at the
        // model level. Fall back to the email local-part so login never fails
        // on an IdP profile that's missing a name. Admins can fix it in the
        // Snipe-IT UI later; the next OIDC login re-syncs from claims.
        if (! $firstName) {
            $firstName = $email ? Str::before($email, '@') : 'OIDC User';
        }

        $user = new User();
        $user->username    = $username;
        $user->email       = $email;
        $user->first_name  = $firstName;
        $user->last_name   = $lastName;
        $user->activated   = 1;
        // Random password — login is via OIDC only. Length matches Snipe-IT defaults.
        $user->password    = bcrypt(Str::random(40));
        $user->permissions = json_encode(config('oidc.default_permissions'));

        $this->saveOrThrow($user, 'provision');
        return $user;
    }

    private function syncFromClaims(User $user, array $claims): void
    {
        $map = config('oidc.claim_map');

        // Keep email fresh — the IdP is the source of truth.
        $user->email = $claims[$map['email']] ?? $user->email;

        [$firstName, $lastName] = $this->resolveName($claims);
        if ($firstName) {
            $user->first_name = $firstName;
        }
        // Only overwrite last_name when resolveName produced one — don't blank an
        // admin's manual correction on a login that yielded no last name. (When the
        // IdP sends an explicit family_name, resolveName already returns it here.)
        if ($lastName !== '') {
            $user->last_name = $lastName;
        }

        $this->saveOrThrow($user, 'syncFromClaims');
    }

    /**
     * Persist a User and surface validation errors with enough context to
     * debug from logs alone. Snipe-IT uses watson/validating, which throws
     * ValidationException on save() — the default exception message ("The
     * given data was invalid.") is useless without the underlying errors.
     */
    private function saveOrThrow(User $user, string $phase): void
    {
        try {
            $user->save();
        } catch (\Throwable $e) {
            Log::error('OIDC: Snipe-IT User save failed', [
                'phase'      => $phase,
                'username'   => $user->username,
                'email'      => $user->email,
                'first_name' => $user->first_name,
                'message'    => $e->getMessage(),
                // watson/validating exposes per-field errors via getErrors()
                'errors'     => method_exists($e, 'getErrors') ? $e->getErrors() : null,
            ]);
            throw $e;
        }
    }

    /**
     * Resolve [first, last] from claims.
     *
     * - Both given_name and family_name present -> trust the explicit pair.
     * - family_name missing but given_name is combined ("Andrin Monn") -> split.
     * - single token / empty -> [first|null, ''] (caller applies email fallback).
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveName(array $claims): array
    {
        $map   = config('oidc.claim_map');
        $first = trim((string) ($claims[$map['first_name']] ?? ''));
        $last  = trim((string) ($claims[$map['last_name']]  ?? ''));

        if ($last !== '') {
            // first name is null when given_name is absent but family_name is
            // present; provision()/syncFromClaims() handle the null.
            return [$first !== '' ? $first : null, $last];
        }

        if (str_contains($first, ' ')) {
            return NameSplitter::split($first); // [first token, remainder]
        }

        return [$first !== '' ? $first : null, ''];
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
        $this->saveOrThrow($user, 'applyGroupMapping');

        $groupIds = Group::whereIn('name', $claimGroups)->pluck('id')->all();
        $missing  = array_diff($claimGroups, Group::whereIn('name', $claimGroups)->pluck('name')->all());
        if ($missing) {
            Log::info('OIDC: skipping unknown groups from claim', ['user' => $user->username, 'missing' => array_values($missing)]);
        }

        $user->groups()->sync($groupIds);
    }
}
