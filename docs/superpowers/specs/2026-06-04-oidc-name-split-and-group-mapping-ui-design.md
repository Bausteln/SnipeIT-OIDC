# Design: Name-splitting fix + Group-mapping admin UI

- **Date:** 2026-06-04
- **Status:** Approved (brainstorming), pending implementation plan
- **Base:** v0.4.0 (`eaa860f`)
- **Author:** brainstormed with maintainer (OberMarcLP)

This document is written in English to match the repository's existing
documentation and code-comment language. The two changes below are
independent and can land in either order.

---

## 1. Context & problem

`snipeit-oidc` is a standalone Laravel package copied into a Snipe-IT
install. It maps verified OIDC claims to a Snipe-IT `User`
(`src/Services/OidcUserResolver.php`), provisioning Just-In-Time or in
strict mode, and already syncs groups.

Two issues motivated this work:

1. **Bug — full name lands entirely in First Name.** When the IdP delivers
   a combined display name in the `given_name` claim and no `family_name`,
   the resolver writes the whole string into `first_name` and leaves
   `last_name` empty (observed: "Andrin Monn" → First Name = "Andrin Monn",
   Last Name = empty). `provision()`/`syncFromClaims()` never split a
   combined name.

2. **Feature — group mapping is name-equality only and not configurable.**
   `applyGroupMapping()` matches OIDC group names *exactly* against
   Snipe-IT group names (`Group::whereIn('name', $claimGroups)`). If the
   names differ, nothing syncs, and there is no way to choose which groups
   sync or to map differing names. The maintainer wants to manage
   permissions once at the Snipe-IT group level and have OIDC groups map
   onto them, configurable from within Snipe-IT.

## 2. Goals

- Split a combined name into First/Last when the IdP does not provide a
  separate `family_name`, without regressing correct IdPs.
- Replace implicit name-equality group sync with an explicit, admin-managed
  **OIDC-group → Snipe-IT-group** mapping, configurable through a UI inside
  Snipe-IT.
- Make the mapping table the single source of truth for which groups sync
  (it doubles as the allowlist).
- Let a mapping optionally grant Snipe-IT superuser, while keeping an ENV
  break-glass path.

## 3. Non-goals (out of scope)

- Reading the OIDC `name` claim as an additional split source (considered
  and explicitly not chosen — see §5.1; noted as a possible future
  enhancement).
- A full automated test harness for the Eloquent-coupled resolver.
- Single-logout, 2FA, or other auth concerns unrelated to these two changes.
- Syncing attributes beyond name/email/groups (department, etc.).
- Auto-creating Snipe-IT groups from claims.

## 4. Decisions (locked during brainstorming)

| Topic | Decision |
|-------|----------|
| Group config surface | Full Snipe-IT admin UI (DB-backed), built now |
| Sync semantics | Mapping table is the **sole source of truth**; replaces exact-name match; table = allowlist |
| Admin/superuser | Per-mapping `grants_superuser` checkbox **plus** `OIDC_ADMIN_GROUPS` (ENV) retained as break-glass |
| Name-split heuristic | First whitespace token = first name, remainder = last name |

## 5. Detailed design

### 5.1 Name splitting (Bug A)

Introduce a dependency-free helper and a claim-resolution step.

**`src/Support/NameSplitter.php`** — pure function, the one unit-testable
piece in this repo:

```php
public static function split(string $full): array
{
    $parts = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($parts === [])            return ['', ''];
    if (count($parts) === 1)      return [$parts[0], ''];
    $first = array_shift($parts);                 // first token = first name
    return [$first, implode(' ', $parts)];        // remainder = last name
}
```

Chosen heuristic keeps multi-word surnames ("von Monn", "van der Berg")
intact, which is the common case in the DACH/European context this install
serves.

**Resolution logic** (new private `resolveName(array $claims): array` in
`OidcUserResolver`, returns `[?string $first, string $last]`):

```
$first = trim((string)($claims[map.first_name] ?? ''));   // given_name
$last  = trim((string)($claims[map.last_name]  ?? ''));    // family_name

if ($last !== '') {
    return [$first !== '' ? $first : null, $last];         // explicit pair wins
}
if (str_contains($first, ' ')) {
    return NameSplitter::split($first);                    // combined → split
}
return [$first !== '' ? $first : null, ''];                // single token / empty
```

- `provision()` calls `resolveName()`. If the returned first name is `null`,
  the **existing** email-local-part fallback applies
  (`$email ? Str::before($email,'@') : 'OIDC User'`); `last_name` defaults to
  `''` as today.
- `syncFromClaims()` calls `resolveName()` and updates `first_name`/
  `last_name`. To avoid clobbering an admin's manual correction with a worse
  derived value, only overwrite `last_name` when the resolver produced a
  non-empty value **or** the IdP sent an explicit `family_name`. (i.e. don't
  blank an existing last name just because this login produced none.)

Behavior matrix:

| given_name | family_name | Result (first / last) |
|------------|-------------|-----------------------|
| "Andrin"   | "Monn"      | Andrin / Monn (unchanged) |
| "Andrin Monn" | (empty)  | Andrin / Monn (**fixed**) |
| "Andrin von Monn" | (empty) | Andrin / von Monn |
| (empty)    | (empty)     | email local-part / "" (unchanged fallback) |
| "Andrin Monn" | "Monn"   | Andrin Monn / Monn (explicit pair trusted) |

### 5.2 Data model (Feature B)

**Migration** `database/migrations/2026_06_04_000000_create_oidc_group_mappings_table.php`:

```
id
oidc_group        string                      -- claim value from the IdP (name or, e.g. Azure, a GUID)
snipe_group_id    FK -> permission_groups.id  -- onDelete cascade
grants_superuser  boolean  default false
enabled           boolean  default true        -- disable without deleting
timestamps
unique (oidc_group, snipe_group_id)            -- one OIDC group may map to several Snipe-IT groups via multiple rows
index  (oidc_group)
```

- `snipe_group_id` column type and the FK must match Snipe-IT's
  `permission_groups.id` type (see §7 assumptions). FK cascades on delete so
  removing a Snipe-IT group cleans up dangling mappings.
- Because `oidc_group` is free text, Azure AD installs (which emit group
  **object IDs**, not names) are supported by entering the GUID.

**Model** `src/Models/OidcGroupMapping.php`:

```php
class OidcGroupMapping extends Model
{
    protected $table = 'oidc_group_mappings';
    protected $fillable = ['oidc_group', 'snipe_group_id', 'grants_superuser', 'enabled'];
    protected $casts = ['grants_superuser' => 'bool', 'enabled' => 'bool'];

    public function snipeGroup() // belongsTo App\Models\Group on snipe_group_id
    {
        return $this->belongsTo(\App\Models\Group::class, 'snipe_group_id');
    }
}
```

### 5.3 Sync logic rewrite (`applyGroupMapping`)

```php
$map         = config('oidc.claim_map');
$claimGroups = array_values(array_filter((array)($claims[$map['groups']] ?? [])));

$mappings = OidcGroupMapping::where('enabled', true)
    ->whereIn('oidc_group', $claimGroups)
    ->get();

$groupIds = $mappings->pluck('snipe_group_id')->unique()->values()->all();

$adminGroups = config('oidc.admin_groups');                       // ENV break-glass
$isSuper = $mappings->contains(fn ($m) => $m->grants_superuser)
        || (bool) array_intersect($claimGroups, $adminGroups);

$perms = json_decode($user->permissions, true) ?: [];
if ($isSuper) { $perms['superuser'] = '1'; } else { unset($perms['superuser']); }
$user->permissions = json_encode($perms);
$this->saveOrThrow($user, 'applyGroupMapping');

$user->groups()->sync($groupIds);                                 // authoritative

$unmapped = array_diff($claimGroups, $mappings->pluck('oidc_group')->all());
if ($unmapped) {
    Log::debug('OIDC: unmapped groups from claim', [
        'user' => $user->username, 'unmapped' => array_values($unmapped),
    ]);
}
```

Net effect: only OIDC groups present in the mapping table sync; everything
else is ignored and debug-logged. `sync()` removes
Snipe-IT group memberships no longer backed by a mapping — the table is
authoritative.

### 5.4 Admin UI

**Routes** — new file `routes/admin.php`, loaded by the provider under the
`web` + `auth` middleware (the existing public `/oidc/login|callback|logout`
routes stay in `routes/web.php`):

```
GET    /oidc/admin/groups            -> OidcGroupMappingController@index
POST   /oidc/admin/groups            -> OidcGroupMappingController@store
PUT    /oidc/admin/groups/{mapping}  -> OidcGroupMappingController@update
DELETE /oidc/admin/groups/{mapping}  -> OidcGroupMappingController@destroy
```

**Gating** — `src/Http/Middleware/EnsureSuperUser.php`:
`abort_unless(optional(Auth::user())->isSuperUser(), 403)`. Applied to the
admin route group (in addition to `auth`).

**Controller** `src/Http/Controllers/OidcGroupMappingController.php`:
- `index()` — list all mappings (with `snipeGroup`), pass Snipe-IT groups
  (`\App\Models\Group::orderBy('name')->get()`) for the dropdown.
- `store(Request)` — validate: `oidc_group` required string ≤255;
  `snipe_group_id` required exists in `permission_groups,id`;
  `grants_superuser`/`enabled` booleans. Create, flash success, redirect
  back. Unique-constraint violation → friendly validation error.
- `update(Request, OidcGroupMapping)` — same validation; update row.
- `destroy(OidcGroupMapping)` — delete, flash, redirect back.

**View** `resources/views/admin/index.blade.php`:
- `@extends('layouts/default')`, render inside `@section('content')` so it
  picks up Snipe-IT's AdminLTE chrome.
- A table of existing mappings: OIDC group, Snipe-IT group, superuser badge,
  enabled toggle, edit/delete actions (each mutating control in its own
  `<form>` with `@csrf` and method spoofing for PUT/DELETE).
- An "add mapping" form: text input (OIDC group), `<select>` of Snipe-IT
  groups, two checkboxes (superuser, enabled).
- Show `session('success')` / validation errors via Snipe-IT's standard
  alert partials where available, falling back to plain Bootstrap markup.

### 5.5 Discoverability, config & docs

- **Nav link (optional):** ship `resources/views/admin-nav-link.blade.php`
  (a single sidebar `<li>` linking to `/oidc/admin/groups`, wrapped in an
  `isSuperUser()` check). README documents adding
  `@include('oidc::admin-nav-link')` to Snipe-IT's sidebar partial — the
  same opt-in patch pattern as the login button. The direct URL is also
  documented so the feature works without patching.
- **Config:** `OIDC_ADMIN_GROUPS` is retained (break-glass). No new ENV is
  required for the mapping (it lives in the DB). The `claim_map.groups`
  entry is unchanged.
- **README:** add a "Group mapping via UI" section; add a migration note
  (`php artisan migrate` after install/upgrade); remove the
  "Group mapping (USER DECISION REQUIRED)" section that instructed editing
  `applyGroupMapping()` by hand (now UI-driven).

### 5.6 Provider changes (`OidcServiceProvider`)

- `$this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');`
  (currently absent — without it the table is never created).
- Load `routes/admin.php` under `Route::middleware(['web', 'auth'])` with
  the `EnsureSuperUser` middleware applied and the controller namespace set.
- Keep existing `loadViewsFrom(..., 'oidc')` (covers the new admin views).
- Gate admin route registration behind `config('oidc.enabled') === true`,
  consistent with the existing routes.

## 6. Files

**Create**
- `database/migrations/2026_06_04_000000_create_oidc_group_mappings_table.php`
- `src/Models/OidcGroupMapping.php`
- `src/Support/NameSplitter.php`
- `src/Http/Controllers/OidcGroupMappingController.php`
- `src/Http/Middleware/EnsureSuperUser.php`
- `routes/admin.php`
- `resources/views/admin/index.blade.php`
- `resources/views/admin-nav-link.blade.php`
- `tests/NameSplitterTest.php`, `phpunit.xml`

**Modify**
- `src/Services/OidcUserResolver.php` — `resolveName()`, use it in
  `provision()`/`syncFromClaims()`, rewrite `applyGroupMapping()`.
- `src/Providers/OidcServiceProvider.php` — migrations, admin routes,
  middleware.
- `README.md` — UI section, migration note, remove manual-hook section.
- `composer.json` — add `phpunit/phpunit` under `require-dev`; optional
  `test` script.

## 7. Assumptions to verify against target Snipe-IT (v8) during implementation

1. `App\Models\Group` uses table `permission_groups`; confirm `id` column
   type for the FK.
2. `User::groups()` is a belongsToMany whose pivot detaches correctly via
   `sync()` (pivot table `users_groups`).
3. `User::isSuperUser()` exists and reflects the `superuser` permission.
4. `users.permissions` is a JSON string (already relied upon in current
   code — effectively confirmed).
5. `layouts/default` is the authenticated master layout exposing a
   `content` section and AdminLTE styling.
6. Snipe-IT's `web` group provides session + CSRF; `auth` middleware is
   registered under that name.

Any mismatch is surfaced and resolved in the plan, not silently worked
around.

## 8. Risks & rollout

- **Breaking change for existing installs.** Because the table is now the
  sole source of truth, after upgrading **no groups sync until mappings are
  created**. This is the intended, chosen semantics. README must call this
  out prominently; superuser break-glass via `OIDC_ADMIN_GROUPS` (ENV) and
  the local admin login keep an upgrader from being locked out while they
  populate mappings.
- **Migration on deploy.** The documented Docker/k8s images already run
  `php artisan migrate` on boot; manual installs must run it once.
- **UI coupling to Snipe-IT internals.** Mitigated by the assumptions list
  (§7) being verified first.

## 9. Testing

- `NameSplitterTest` (PHPUnit, no DB): covers single token, two tokens,
  multi-word surname, surrounding/inner whitespace, empty string.
- Sync logic and UI: verified manually in the existing Docker-Compose
  environment (create groups, add mappings, log in via IdP, confirm
  membership + superuser + that unmapped groups don't sync, and that a
  removed mapping detaches on next login).
- CI keeps `composer validate` + `php -l`; add a `phpunit` step scoped to
  `tests/` so the pure unit test runs.
```
