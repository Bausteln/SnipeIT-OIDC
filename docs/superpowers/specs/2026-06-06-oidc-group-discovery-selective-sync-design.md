# Design: OIDC group discovery & selective sync

- **Date:** 2026-06-06
- **Status:** Approved (brainstorming), supersedes the v0.5.0 mapping-table feature
- **Base:** main @ `2352e58` (v0.5.0)

## 1. Why this replaces v0.5.0's mapping table

v0.5.0 shipped an `oidc_group_mappings` table where an admin **types** an OIDC
group name and **maps** it to an *existing* Snipe-IT group. Feedback: that isn't
what the maintainer wanted. Their actual workflow:

- There are **no Snipe-IT groups yet**.
- They want to **select** (tick from a list) which OIDC groups should sync —
  not type names.
- Selecting a group should **auto-create** the matching Snipe-IT group.
- Then they manage **permissions on that group** (their original goal: "ich will
  nur noch die Permissions der Gruppen pflegen").

## 2. Design

### 2.1 Data model — `oidc_groups` (replaces `oidc_group_mappings`)

| column | meaning |
|--------|---------|
| `id` | PK |
| `name` | OIDC group claim value, **unique** |
| `sync_enabled` | boolean, default false — the admin's tick |
| `snipe_group_id` | nullable FK → `permission_groups.id` (the auto-created group; null until created), `nullOnDelete` |
| `last_seen_at` | nullable timestamp — last login that presented this group |
| timestamps | |

A migration creates `oidc_groups` and **drops** `oidc_group_mappings`.

Model `Bausteln\SnipeitOidc\Models\OidcGroup` replaces `OidcGroupMapping`
(relation `snipeGroup()`).

### 2.2 Discovery (at login)

In the resolver, for every group in the user's claim, **upsert** a row into
`oidc_groups` (create if new with `sync_enabled = false`, update `last_seen_at`).
This is how groups appear in the UI for selection — they show up after the first
login that presents them. (A manual "add group name" path covers the cold start.)

### 2.3 Selection + auto-create (immediate)

The admin UI lists discovered groups, each with a **Sync** checkbox. When the
admin enables sync for a group, the controller **immediately** creates the
Snipe-IT group named after the OIDC group (if `snipe_group_id` is null / the group
is gone) with empty permissions, and stores `snipe_group_id`. The admin can then
open that Snipe-IT group and set its permissions right away.

Disabling sync leaves the Snipe-IT group intact (it just stops being synced); we
do not delete groups.

### 2.4 Sync at login — non-destructive

Let:
- `managedIds` = `snipe_group_id` of **all** `sync_enabled` `oidc_groups` (the
  set of groups OIDC manages),
- `claimedIds` = `snipe_group_id` of `sync_enabled` `oidc_groups` whose `name`
  is in this user's claim.

Then:
1. `$user->groups()->syncWithoutDetaching($claimedIds)` — add memberships.
2. `$user->groups()->detach(array_intersect($managedIds, currentUserGroupIds) − claimedIds)`
   — remove only OIDC-managed groups the user is no longer claimed in.

This is **non-destructive**: groups an admin assigned manually (not OIDC-managed)
are never touched. (v0.5.0 used a blanket `sync()` that clobbered manual groups —
fixed here.)

### 2.5 Superuser — via group permissions

Snipe-IT derives superuser from a user's **effective** permissions (user +
groups). So the admin simply sets the superuser permission on whichever
auto-created group should grant admin; members inherit it through membership. We
therefore **drop** the per-group `grants_superuser` flag and stop writing the
user `permissions` JSON for group-based superuser.

`OIDC_ADMIN_GROUPS` (env) is **retained** as break-glass: if the claim intersects
it, the resolver sets the user's `superuser` permission directly (independent of
any Snipe-IT group), preventing lock-out.

### 2.6 UI

`oidc::admin.index` becomes a discovered-groups list:
- Table: OIDC group name · last seen · Snipe-IT group status (created? link to its
  edit page to set permissions) · **Sync** toggle.
- "Add group manually" form (text input) for the cold start before any login.
- Empty state: "No OIDC groups discovered yet — they appear here after users log
  in, or add one manually."
- Superuser-gated (existing `EnsureSuperUser`), `@csrf` on all mutations.

## 3. Files

**Modify**
- `database/migrations/...` — new migration: create `oidc_groups`, drop
  `oidc_group_mappings`.
- `src/Models/OidcGroup.php` — new (remove `OidcGroupMapping.php`).
- `src/Services/OidcUserResolver.php` — rewrite group step: discovery +
  non-destructive selective sync; keep ENV break-glass; drop per-group superuser
  flag + permissions-JSON group logic.
- `src/Http/Controllers/OidcGroupMappingController.php` → rename to
  `OidcGroupController.php`: index (list discovered + Snipe-IT status), toggle
  sync (create group on enable), manual add, destroy (remove a discovered row).
- `routes/admin.php` — adjust routes/names.
- `resources/views/admin/index.blade.php` — discovered-groups UI.
- `resources/views/admin-nav-link.blade.php` — label unchanged ("OIDC Groups").
- `README.md` — rewrite the group section to the discover/select model.

## 4. Assumptions to verify (real Snipe-IT v8.5.0 image — Docker available)

1. `App\Models\Group::create(['name' => $name])` creates a group with no/empty
   permissions and is valid (check required/fillable fields; permissions
   nullable).
2. `User::isSuperUser()` reflects superuser granted via a group's permissions.
3. `User::groups()` supports `syncWithoutDetaching` / `detach`.
4. `permission_groups.id` is unsigned int (confirmed for the FK in v0.5.0).

These will be exercised against the real image with a database.

## 5. Out of scope
- Discovering groups via the IdP's API (we use login-observed groups + manual add).
- Deleting Snipe-IT groups when sync is disabled.
- Migrating any existing `oidc_group_mappings` rows (v0.5.0 was just tagged and
  not yet deployed; the table is dropped).
