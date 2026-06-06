<?php

namespace Bausteln\SnipeitOidc\Services;

use App\Models\User;
use Bausteln\SnipeitOidc\Models\OidcGroup;
use Bausteln\SnipeitOidc\Support\NameSplitter;
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

        $this->syncGroups($user, $claims);

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
     * Discover OIDC groups and sync the user's Snipe-IT group memberships.
     *
     * Policy:
     *  - Every claimed group is recorded in `oidc_groups` so an admin can pick
     *    it in the UI (groups appear after the first login that presents them).
     *  - Only admin-enabled groups (sync_enabled, with a Snipe-IT group already
     *    created) are synced. Membership sync is NON-destructive: it never
     *    touches Snipe-IT groups that OIDC doesn't manage (e.g. manual ones).
     *  - Group-based superuser is left to the Snipe-IT group's own permissions
     *    (Snipe-IT derives superuser from effective group permissions).
     *    OIDC_ADMIN_GROUPS (env) stays as a break-glass that grants superuser
     *    directly, independent of any group.
     */
    private function syncGroups(User $user, array $claims): void
    {
        $map         = config('oidc.claim_map');
        // Keep non-empty claim values, but don't let a bare array_filter() drop a
        // legitimate group literally named "0" (falsy under array_filter's default).
        $claimGroups = array_values(array_filter(
            (array) ($claims[$map['groups']] ?? []),
            static fn ($v) => $v !== '' && $v !== null && $v !== false,
        ));

        // Discovery: ensure a row exists for each claimed group (new ones default
        // to not-synced) so it shows up in the admin UI, then bump last_seen_at in
        // a single query rather than one save() per group.
        foreach ($claimGroups as $name) {
            OidcGroup::firstOrCreate(['name' => $name]);
        }
        if ($claimGroups) {
            OidcGroup::whereIn('name', $claimGroups)->update(['last_seen_at' => now()]);
        }

        // Managed = admin-enabled groups that have a Snipe-IT group; claimed =
        // those the user actually presented this login.
        $managed    = OidcGroup::where('sync_enabled', true)->whereNotNull('snipe_group_id')->get();
        $managedIds = $managed->pluck('snipe_group_id')->map(fn ($id) => (int) $id)->all();
        $claimedIds = $managed->whereIn('name', $claimGroups)
            ->pluck('snipe_group_id')->map(fn ($id) => (int) $id)->values()->all();

        // Non-destructive: add the claimed memberships, then remove only the
        // OIDC-managed groups the user is no longer claimed in. Manually-assigned
        // (non-managed) groups are never touched.
        if ($claimedIds) {
            $user->groups()->syncWithoutDetaching($claimedIds);
        }
        $toDetach = array_values(array_diff($managedIds, $claimedIds));
        if ($toDetach) {
            $user->groups()->detach($toDetach);
        }

        // Break-glass: env-listed groups grant superuser directly. Grant-only —
        // we never revoke a superuser flag an admin may have set by hand.
        $adminGroups = config('oidc.admin_groups');
        if (array_intersect($claimGroups, $adminGroups)) {
            $perms = json_decode((string) $user->permissions, true) ?: [];
            if (($perms['superuser'] ?? null) !== '1') {
                $perms['superuser'] = '1';
                $user->permissions = json_encode($perms);
                $this->saveOrThrow($user, 'syncGroups:breakglass');
            }
        }
    }
}
