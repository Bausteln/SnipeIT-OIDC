# OIDC Name-Split + Group-Mapping UI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the name-split bug (full name landing in First Name) and add a DB-backed, superuser-gated admin UI inside Snipe-IT for mapping OIDC groups onto Snipe-IT groups.

**Architecture:** A dependency-free `NameSplitter` does the only unit-testable logic; the resolver gains a `resolveName()` step. Group sync is rewritten to read an authoritative `oidc_group_mappings` table (also the allowlist) instead of exact-name matching, managed through a Blade admin page gated by `EnsureSuperUser`.

**Tech Stack:** PHP 8.2+, Laravel (illuminate/* ^9–12), Eloquent, Blade (AdminLTE/Bootstrap 3 from Snipe-IT), PHPUnit (unit only), MySQL/MariaDB (Snipe-IT), Docker Compose for integration.

**Spec:** `docs/superpowers/specs/2026-06-04-oidc-name-split-and-group-mapping-ui-design.md`

---

## Verification environment (read first)

This workspace has **no PHP, no Composer, no `vendor/`**. Two surfaces verify this plan:

- **Lint + unit tests** — need a PHP 8.2+ runtime. Either install locally
  (macOS: `brew install php composer`) or rely on the CI job (it runs
  `composer validate`, `php -l`, `composer install`, and — after Task 13 —
  `vendor/bin/phpunit`). Every `php -l` / `phpunit` command below assumes this
  runtime exists. **Do not claim a test passed without seeing the output.**
- **Integration (migration, sync, UI)** — needs a running Snipe-IT. Use the
  Docker Compose setup documented in `README.md` (see Task 14). The
  Laravel-/Eloquent-coupled code (resolver, controller, views, migration)
  cannot be unit-tested in this standalone package, so it is gated by `php -l`
  plus the manual Docker checklist in Task 14.

## Scope note

The plan has two independent parts that share one branch:
**Phase 1 (Tasks 1–3)** is the name-split bug fix and can be merged on its own.
**Phase 2 (Tasks 4–13)** is the group-mapping UI. Task 14 verifies both.
If you prefer two PRs, cut after Task 3.

## File structure

**Create**
| Path | Responsibility |
|------|----------------|
| `src/Support/NameSplitter.php` | Pure `split(string): [first, last]`. No deps. |
| `tests/Unit/NameSplitterTest.php` | Unit tests for the splitter. |
| `phpunit.xml` | PHPUnit config (unit suite). |
| `database/migrations/2026_06_05_000000_create_oidc_group_mappings_table.php` | Schema for mappings. |
| `src/Models/OidcGroupMapping.php` | Eloquent model + `snipeGroup()` relation. |
| `src/Http/Middleware/EnsureSuperUser.php` | 403 unless authenticated superuser. |
| `src/Http/Controllers/OidcGroupMappingController.php` | CRUD for mappings. |
| `routes/admin.php` | Admin route definitions. |
| `resources/views/admin/index.blade.php` | Mapping admin page. |
| `resources/views/admin-nav-link.blade.php` | Optional sidebar link. |

**Modify**
| Path | Change |
|------|--------|
| `composer.json` | `require-dev` phpunit, `autoload-dev`, `scripts.test`. |
| `src/Services/OidcUserResolver.php` | `resolveName()`, use it; rewrite `applyGroupMapping()`. |
| `src/Providers/OidcServiceProvider.php` | Load migrations + admin routes + middleware. |
| `.github/workflows/ci.yml` | Add a `vendor/bin/phpunit` step. |
| `README.md` | "Group mapping via UI" section; migration note; remove the manual `applyGroupMapping()` section. |

---

## Task 1: PHPUnit scaffolding

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml`

- [ ] **Step 1: Add dev deps + autoload-dev + script to `composer.json`**

Replace the file's `"require"` block tail and add three keys so the result is:

```json
{
    "name": "bausteln/snipeit-oidc",
    "description": "OpenID Connect (OIDC) authentication plugin for Snipe-IT",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "jumbojett/openid-connect-php": "^1.0",
        "illuminate/support": "^9.0|^10.0|^11.0|^12.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Bausteln\\SnipeitOidc\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Bausteln\\SnipeitOidc\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit"
    },
    "config": {
        "platform": {
            "php": "8.2.0"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Bausteln\\SnipeitOidc\\Providers\\OidcServiceProvider"
            ]
        }
    },
    "minimum-stability": "stable"
}
```

> **Dev-env note:** pin `config.platform.php` to the project **floor** `8.2.0`
> (matching `"php": "^8.2"`), NOT a ceiling. This makes Composer resolve the same
> PHP-8.2-compatible dependency set everywhere — local dev (PHP 8.5) and every CI
> matrix job (8.2/8.3/8.4) — so the generated `vendor/composer/platform_check.php`
> gates only at `>= 8.2.0` and passes on all runners. Pinning to a ceiling like
> `8.4.99` instead pulls in deps that require PHP `>= 8.4.1` (e.g. `symfony/clock`
> 8.x via carbon) and makes `vendor/` fatal-error on the 8.2/8.3 CI runners.
> (A library's `config.platform` is ignored by downstream root projects like
> Snipe-IT — but in CI *this* repo is the root, so it applies there.)

- [ ] **Step 2: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Install dev deps**

Run: `composer validate --strict --no-check-publish && composer update --no-interaction`
Expected: composer.json is valid; `vendor/bin/phpunit` now exists.
(If no local PHP/Composer: install per the Verification-environment note, or defer running to CI.)

- [ ] **Step 4: Add `.phpunit.cache` and `vendor` to `.gitignore` if missing**

Check `.gitignore` contains `/vendor` and `/.phpunit.cache`. Append whichever is absent (one per line).

- [ ] **Step 5: Commit**

```bash
git add composer.json phpunit.xml .gitignore
git commit -m "test: add PHPUnit unit-test scaffolding"
```

---

## Task 2: NameSplitter (TDD)

**Files:**
- Create: `src/Support/NameSplitter.php`
- Test: `tests/Unit/NameSplitterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/NameSplitterTest.php`:

```php
<?php

namespace Bausteln\SnipeitOidc\Tests\Unit;

use Bausteln\SnipeitOidc\Support\NameSplitter;
use PHPUnit\Framework\TestCase;

class NameSplitterTest extends TestCase
{
    public function test_two_tokens_split_into_first_and_last(): void
    {
        $this->assertSame(['Andrin', 'Monn'], NameSplitter::split('Andrin Monn'));
    }

    public function test_multi_word_surname_stays_with_last_name(): void
    {
        $this->assertSame(['Andrin', 'von Monn'], NameSplitter::split('Andrin von Monn'));
        $this->assertSame(['Anna', 'Maria Müller'], NameSplitter::split('Anna Maria Müller'));
    }

    public function test_single_token_has_empty_last_name(): void
    {
        $this->assertSame(['Cher', ''], NameSplitter::split('Cher'));
    }

    public function test_surrounding_and_inner_whitespace_is_normalised(): void
    {
        $this->assertSame(['Andrin', 'Monn'], NameSplitter::split("  Andrin   Monn  "));
    }

    public function test_blank_input_returns_two_empties(): void
    {
        $this->assertSame(['', ''], NameSplitter::split('   '));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --testdox tests/Unit/NameSplitterTest.php`
Expected: FAIL — `Error: Class "Bausteln\SnipeitOidc\Support\NameSplitter" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `src/Support/NameSplitter.php`:

```php
<?php

namespace Bausteln\SnipeitOidc\Support;

/**
 * Split a combined display name into [first, last].
 *
 * Heuristic: the first whitespace-delimited token is the first name, the
 * remainder is the last name. Keeps multi-word surnames ("von Monn",
 * "van der Berg") intact — the common case in the DACH/European context.
 */
class NameSplitter
{
    /**
     * @return array{0: string, 1: string} [$first, $last]
     */
    public static function split(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return ['', ''];
        }
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --testdox tests/Unit/NameSplitterTest.php`
Expected: PASS — 5 passing, all assertions green.

- [ ] **Step 5: Lint**

Run: `php -l src/Support/NameSplitter.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add src/Support/NameSplitter.php tests/Unit/NameSplitterTest.php
git commit -m "feat: add NameSplitter (first token = first name, rest = last name)"
```

---

## Task 3: Wire name resolution into the resolver

**Files:**
- Modify: `src/Services/OidcUserResolver.php`

Cannot be unit-tested standalone (uses `config()` + Eloquent). Gate: `php -l`
now; behavior verified in Task 14.

- [ ] **Step 1: Import NameSplitter**

In `src/Services/OidcUserResolver.php`, add to the `use` block (after the existing `use Illuminate\Support\Str;`):

```php
use Bausteln\SnipeitOidc\Support\NameSplitter;
```

- [ ] **Step 2: Add the `resolveName()` helper**

Insert this method directly above `private function applyGroupMapping(` :

```php
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
            return [$first !== '' ? $first : null, $last];
        }

        if (str_contains($first, ' ')) {
            return NameSplitter::split($first); // [first token, remainder]
        }

        return [$first !== '' ? $first : null, ''];
    }
```

- [ ] **Step 3: Use it in `provision()`**

In `provision()`, replace these lines:

```php
        $firstName = $claims[$map['first_name']] ?? null;
        $lastName  = $claims[$map['last_name']]  ?? null;

        // Snipe-IT's ValidatingTrait enforces `first_name => required` at the
        // model level. Fall back to the email local-part so login never fails
        // on an IdP profile that's missing `given_name`. Admins can fix it in
        // the Snipe-IT UI later; the next OIDC login will sync from claims if
        // the IdP starts sending the name.
        if (! $firstName) {
            $firstName = $email ? Str::before($email, '@') : 'OIDC User';
        }
```

with:

```php
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
```

Then change the assignment line:

```php
        $user->last_name   = $lastName ?? '';
```

to:

```php
        $user->last_name   = $lastName;
```

(`$lastName` from `resolveName()` is always a string.)

- [ ] **Step 4: Use it in `syncFromClaims()`**

Replace the body of `syncFromClaims()` (the three `$user->...` assignment lines) so the method reads:

```php
    private function syncFromClaims(User $user, array $claims): void
    {
        $map = config('oidc.claim_map');

        // Keep email fresh — the IdP is the source of truth.
        $user->email = $claims[$map['email']] ?? $user->email;

        [$firstName, $lastName] = $this->resolveName($claims);
        if ($firstName) {
            $user->first_name = $firstName;
        }
        // Only overwrite last_name when we derived one or the IdP sent an
        // explicit family_name — don't blank an admin's manual correction on a
        // login that happened to produce no last name.
        $explicitLast = trim((string) ($claims[$map['last_name']] ?? '')) !== '';
        if ($lastName !== '' || $explicitLast) {
            $user->last_name = $lastName;
        }

        $this->saveOrThrow($user, 'syncFromClaims');
    }
```

- [ ] **Step 5: Lint**

Run: `php -l src/Services/OidcUserResolver.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add src/Services/OidcUserResolver.php
git commit -m "fix: split combined name into first/last when family_name is absent"
```

---

## Task 4: Migration for `oidc_group_mappings`

**Files:**
- Create: `database/migrations/2026_06_05_000000_create_oidc_group_mappings_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('oidc_group');
            // Snipe-IT's permission_groups.id is an unsigned INT (increments()).
            // If `php artisan migrate` fails on the FK with a type mismatch,
            // switch this to unsignedBigInteger to match a bigIncrements id.
            $table->unsignedInteger('snipe_group_id');
            $table->boolean('grants_superuser')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['oidc_group', 'snipe_group_id']);
            $table->index('oidc_group');
            $table->foreign('snipe_group_id')
                ->references('id')->on('permission_groups')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_group_mappings');
    }
};
```

- [ ] **Step 2: Lint**

Run: `php -l database/migrations/2026_06_05_000000_create_oidc_group_mappings_table.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_05_000000_create_oidc_group_mappings_table.php
git commit -m "feat: add oidc_group_mappings migration"
```

---

## Task 5: OidcGroupMapping model

**Files:**
- Create: `src/Models/OidcGroupMapping.php`

- [ ] **Step 1: Create the model**

```php
<?php

namespace Bausteln\SnipeitOidc\Models;

use App\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One OIDC-group -> Snipe-IT-group mapping row. The table is the authoritative
 * source of truth for group sync (it also acts as the allowlist).
 */
class OidcGroupMapping extends Model
{
    protected $table = 'oidc_group_mappings';

    protected $fillable = [
        'oidc_group',
        'snipe_group_id',
        'grants_superuser',
        'enabled',
    ];

    protected $casts = [
        'grants_superuser' => 'bool',
        'enabled'          => 'bool',
    ];

    public function snipeGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'snipe_group_id');
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/Models/OidcGroupMapping.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Models/OidcGroupMapping.php
git commit -m "feat: add OidcGroupMapping model"
```

---

## Task 6: Rewrite `applyGroupMapping()` to be table-driven

**Files:**
- Modify: `src/Services/OidcUserResolver.php`

- [ ] **Step 1: Swap the import**

In `src/Services/OidcUserResolver.php`, remove:

```php
use App\Models\Group;
```

and add (alongside the other `Bausteln\SnipeitOidc\...` import from Task 3):

```php
use Bausteln\SnipeitOidc\Models\OidcGroupMapping;
```

- [ ] **Step 2: Replace the whole `applyGroupMapping()` method**

Replace the existing method (its docblock and body) with:

```php
    /**
     * Map OIDC group claims onto Snipe-IT groups + the superuser flag.
     *
     * Policy: the oidc_group_mappings table is the single source of truth.
     * Only OIDC groups present (and enabled) in the table sync; everything
     * else is ignored. Superuser is granted by any matched mapping flagged
     * grants_superuser, OR by the OIDC_ADMIN_GROUPS env break-glass list.
     */
    private function applyGroupMapping(User $user, array $claims): void
    {
        $map         = config('oidc.claim_map');
        $claimGroups = array_values(array_filter((array) ($claims[$map['groups']] ?? [])));

        $mappings = OidcGroupMapping::query()
            ->where('enabled', true)
            ->whereIn('oidc_group', $claimGroups)
            ->get();

        $groupIds = $mappings->pluck('snipe_group_id')->unique()->values()->all();

        $adminGroups = config('oidc.admin_groups');
        $isSuper = $mappings->contains(fn (OidcGroupMapping $m) => $m->grants_superuser)
            || (bool) array_intersect($claimGroups, $adminGroups);

        $perms = json_decode($user->permissions, true) ?: [];
        if ($isSuper) {
            $perms['superuser'] = '1';
        } else {
            unset($perms['superuser']);
        }
        $user->permissions = json_encode($perms);
        $this->saveOrThrow($user, 'applyGroupMapping');

        // Authoritative: detaches Snipe-IT groups no longer backed by a mapping.
        $user->groups()->sync($groupIds);

        $unmapped = array_diff($claimGroups, $mappings->pluck('oidc_group')->all());
        if ($unmapped) {
            Log::debug('OIDC: unmapped groups from claim', [
                'user'     => $user->username,
                'unmapped' => array_values($unmapped),
            ]);
        }
    }
```

- [ ] **Step 3: Lint**

Run: `php -l src/Services/OidcUserResolver.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Services/OidcUserResolver.php
git commit -m "feat: drive group sync from oidc_group_mappings table"
```

---

## Task 7: EnsureSuperUser middleware

**Files:**
- Create: `src/Http/Middleware/EnsureSuperUser.php`

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace Bausteln\SnipeitOidc\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Allow only authenticated Snipe-IT superusers through. Gates the OIDC
 * group-mapping admin pages. Assumes Snipe-IT's User::isSuperUser().
 */
class EnsureSuperUser
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(optional(Auth::user())->isSuperUser(), 403);

        return $next($request);
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/Http/Middleware/EnsureSuperUser.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Http/Middleware/EnsureSuperUser.php
git commit -m "feat: add EnsureSuperUser middleware"
```

---

## Task 8: OidcGroupMappingController

**Files:**
- Create: `src/Http/Controllers/OidcGroupMappingController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace Bausteln\SnipeitOidc\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Bausteln\SnipeitOidc\Models\OidcGroupMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OidcGroupMappingController extends Controller
{
    public function index()
    {
        $mappings = OidcGroupMapping::with('snipeGroup')->orderBy('oidc_group')->get();
        $groups   = Group::orderBy('name')->get();

        return view('oidc::admin.index', compact('mappings', 'groups'));
    }

    public function store(Request $request): RedirectResponse
    {
        OidcGroupMapping::create($this->validated($request));

        return redirect()->route('oidc.admin.groups.index')->with('success', 'Mapping added.');
    }

    public function update(Request $request, OidcGroupMapping $mapping): RedirectResponse
    {
        $mapping->update($this->validated($request, $mapping->id));

        return redirect()->route('oidc.admin.groups.index')->with('success', 'Mapping updated.');
    }

    public function destroy(OidcGroupMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return redirect()->route('oidc.admin.groups.index')->with('success', 'Mapping removed.');
    }

    /**
     * Validate input and normalise the two checkboxes to real booleans.
     *
     * @return array{oidc_group: string, snipe_group_id: int, grants_superuser: bool, enabled: bool}
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $request->validate([
            'oidc_group'       => ['required', 'string', 'max:255'],
            'snipe_group_id'   => ['required', 'integer', Rule::exists('permission_groups', 'id')],
            'grants_superuser' => ['nullable', 'boolean'],
            'enabled'          => ['nullable', 'boolean'],
        ]);

        $duplicate = OidcGroupMapping::where('oidc_group', $request->input('oidc_group'))
            ->where('snipe_group_id', (int) $request->input('snipe_group_id'))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'oidc_group' => 'This OIDC group is already mapped to that Snipe-IT group.',
            ]);
        }

        return [
            'oidc_group'       => (string) $request->input('oidc_group'),
            'snipe_group_id'   => (int) $request->input('snipe_group_id'),
            'grants_superuser' => $request->boolean('grants_superuser'),
            'enabled'          => $request->boolean('enabled'),
        ];
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/Http/Controllers/OidcGroupMappingController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Http/Controllers/OidcGroupMappingController.php
git commit -m "feat: add OidcGroupMappingController (CRUD)"
```

---

## Task 9: Admin routes

**Files:**
- Create: `routes/admin.php`

- [ ] **Step 1: Create the route file**

```php
<?php

use Illuminate\Support\Facades\Route;

// Controller namespace + middleware (web, auth, EnsureSuperUser) are applied by
// OidcServiceProvider when it loads this file. {mapping} resolves to an
// OidcGroupMapping via implicit route-model binding.
Route::prefix('oidc/admin')->group(function () {
    Route::get('groups',              'OidcGroupMappingController@index')->name('oidc.admin.groups.index');
    Route::post('groups',             'OidcGroupMappingController@store')->name('oidc.admin.groups.store');
    Route::put('groups/{mapping}',    'OidcGroupMappingController@update')->name('oidc.admin.groups.update');
    Route::delete('groups/{mapping}', 'OidcGroupMappingController@destroy')->name('oidc.admin.groups.destroy');
});
```

- [ ] **Step 2: Lint**

Run: `php -l routes/admin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add routes/admin.php
git commit -m "feat: add OIDC group-mapping admin routes"
```

---

## Task 10: Wire migrations, routes & middleware into the provider

**Files:**
- Modify: `src/Providers/OidcServiceProvider.php`

- [ ] **Step 1: Add the middleware import**

Add to the `use` block (after the existing `use Bausteln\SnipeitOidc\Http\Middleware\AutoRedirectToOidc;`):

```php
use Bausteln\SnipeitOidc\Http\Middleware\EnsureSuperUser;
```

- [ ] **Step 2: Load migrations before the enabled-guard**

In `boot()`, immediately after the `$this->publishes([...], 'oidc-config');` block and **before** the `if (config('oidc.enabled') !== true) { return; }` line, insert:

```php
        // Always register migrations so the schema exists even when the plugin
        // is toggled off between deploys.
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
```

- [ ] **Step 3: Register the admin route group**

In `boot()`, directly after the existing `Route::middleware('web')->namespace(...)->group(__DIR__ . '/../../routes/web.php');` block, insert:

```php
        // Admin UI: authenticated superusers only.
        Route::middleware(['web', 'auth', EnsureSuperUser::class])
            ->namespace('Bausteln\\SnipeitOidc\\Http\\Controllers')
            ->group(__DIR__ . '/../../routes/admin.php');
```

- [ ] **Step 4: Lint**

Run: `php -l src/Providers/OidcServiceProvider.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/Providers/OidcServiceProvider.php
git commit -m "feat: load mappings migration + admin routes in provider"
```

---

## Task 11: Admin Blade view

**Files:**
- Create: `resources/views/admin/index.blade.php`

Uses the HTML5 `form=` attribute so per-row edit forms stay valid inside the
table. Extends Snipe-IT's `layouts/default` (AdminLTE/Bootstrap 3).

- [ ] **Step 1: Create the view**

```blade
@extends('layouts/default')

@section('title')
    OIDC Group Mappings
@parent
@stop

@section('content')
<div class="row">
  <div class="col-md-12">

    @if (session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
      <div class="alert alert-danger">
        <ul style="margin:0; padding-left:1.2em;">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <div class="box box-default">
      <div class="box-header with-border"><h2 class="box-title">Add mapping</h2></div>
      <div class="box-body">
        <form method="POST" action="{{ route('oidc.admin.groups.store') }}" class="form-inline">
          @csrf
          <div class="form-group">
            <label for="oidc_group">OIDC group</label>
            <input type="text" name="oidc_group" id="oidc_group" class="form-control"
                   value="{{ old('oidc_group') }}" placeholder="e.g. kc-it-admins" required>
          </div>
          <div class="form-group">
            <label for="snipe_group_id">Snipe-IT group</label>
            <select name="snipe_group_id" id="snipe_group_id" class="form-control" required>
              <option value="">— select —</option>
              @foreach ($groups as $group)
                <option value="{{ $group->id }}" @selected(old('snipe_group_id') == $group->id)>{{ $group->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="checkbox">
            <label><input type="checkbox" name="grants_superuser" value="1" @checked(old('grants_superuser'))> Grants superuser</label>
          </div>
          <div class="checkbox">
            <label><input type="checkbox" name="enabled" value="1" @checked(old('enabled', true))> Enabled</label>
          </div>
          <button type="submit" class="btn btn-primary">Add</button>
        </form>
      </div>
    </div>

    <div class="box box-default">
      <div class="box-header with-border"><h2 class="box-title">Mappings</h2></div>
      <div class="box-body table-responsive">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>OIDC group</th><th>Snipe-IT group</th>
              <th>Superuser</th><th>Enabled</th><th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
          @forelse ($mappings as $mapping)
            <tr>
              <td>
                <form id="edit-{{ $mapping->id }}" method="POST" action="{{ route('oidc.admin.groups.update', $mapping) }}">
                  @csrf
                  @method('PUT')
                </form>
                <input form="edit-{{ $mapping->id }}" type="text" name="oidc_group"
                       class="form-control" value="{{ $mapping->oidc_group }}" required>
              </td>
              <td>
                <select form="edit-{{ $mapping->id }}" name="snipe_group_id" class="form-control" required>
                  @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected($mapping->snipe_group_id == $group->id)>{{ $group->name }}</option>
                  @endforeach
                </select>
              </td>
              <td><input form="edit-{{ $mapping->id }}" type="checkbox" name="grants_superuser" value="1" @checked($mapping->grants_superuser)></td>
              <td><input form="edit-{{ $mapping->id }}" type="checkbox" name="enabled" value="1" @checked($mapping->enabled)></td>
              <td class="text-right" style="white-space:nowrap;">
                <button form="edit-{{ $mapping->id }}" type="submit" class="btn btn-sm btn-default">Save</button>
                <form method="POST" action="{{ route('oidc.admin.groups.destroy', $mapping) }}"
                      style="display:inline;" onsubmit="return confirm('Remove this mapping?');">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="5"><em>No mappings yet — OIDC groups will not sync until you add one.</em></td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
@stop
```

- [ ] **Step 2: Commit** (Blade is not checked by `php -l`; verified in Task 14)

```bash
git add resources/views/admin/index.blade.php
git commit -m "feat: add OIDC group-mapping admin view"
```

---

## Task 12: Optional sidebar nav link

**Files:**
- Create: `resources/views/admin-nav-link.blade.php`

- [ ] **Step 1: Create the partial**

```blade
@if(config('oidc.enabled') && optional(auth()->user())->isSuperUser())
  <li>
    <a href="{{ route('oidc.admin.groups.index') }}">
      <i class="fas fa-users-cog"></i> <span>{{ __('OIDC Groups') }}</span>
    </a>
  </li>
@endif
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/admin-nav-link.blade.php
git commit -m "feat: add optional OIDC Groups sidebar nav link"
```

---

## Task 13: CI — run unit tests

**Files:**
- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1: Add a phpunit step**

In `.github/workflows/ci.yml`, directly after the `Resolve dependencies` step (the `composer install ...` one), append:

```yaml
      - name: Unit tests
        run: vendor/bin/phpunit --testdox
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run phpunit unit tests"
```

---

## Task 14: README + integration verification

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Replace the manual group-mapping section**

In `README.md`, find the section beginning `### Group mapping (USER DECISION REQUIRED)` (around line 96) that instructs implementing `applyGroupMapping()` by hand. Replace that section's prose with:

```markdown
### Group mapping (managed in the Snipe-IT UI)

Group sync is driven by a database table managed from within Snipe-IT —
no code editing required.

1. Run the plugin's migration once (creates `oidc_group_mappings`):

   ```bash
   php artisan migrate
   ```

   The documented Docker/k8s images already run `migrate` on boot.

2. As a Snipe-IT **superuser**, open **`/oidc/admin/groups`** (optionally add a
   sidebar link — see below). Add rows mapping each OIDC group claim value to a
   Snipe-IT group. Tick **Grants superuser** to make that OIDC group an admin.

**The table is the single source of truth:** only OIDC groups listed here sync,
and it doubles as the allowlist. After upgrading, **no groups sync until you add
mappings** — set permissions once on the Snipe-IT groups and every member of the
mapped OIDC group inherits them.

**Break-glass:** `OIDC_ADMIN_GROUPS` (env) still grants superuser independently
of the table, so a misconfiguration can't lock every admin out. A local admin
login remains available too.

**Azure AD:** the `groups` claim emits group **object IDs** (GUIDs), not names —
enter the GUID in the "OIDC group" field.

Optional sidebar link — patch Snipe-IT's sidebar partial once, mirroring the
login-button approach:

```blade
@include('oidc::admin-nav-link')
```
```

- [ ] **Step 2: Add a migration note to the Install section**

In `README.md`, in the numbered Install steps, after the
`php artisan vendor:publish --tag=oidc-config` line, add a new step:

```markdown
5. Run the plugin migration (creates the `oidc_group_mappings` table):

   ```bash
   php artisan migrate
   ```
```

(Renumber any following steps accordingly.)

- [ ] **Step 3: Commit the docs**

```bash
git add README.md
git commit -m "docs: document UI-driven group mapping + migration step"
```

- [ ] **Step 4: Integration verification (Docker, manual)**

Bring up the Docker Compose stack from the README's deployment section against a
Snipe-IT v8 image that vendors this branch. Then verify each item and record the
result:

1. **Migration:** container boot runs `php artisan migrate` cleanly; table
   `oidc_group_mappings` exists. If the FK errors on a type mismatch, change
   `unsignedInteger('snipe_group_id')` to `unsignedBigInteger` (Task 4) and
   re-run.
2. **Name split:** log in as an IdP user whose `given_name` holds a full name
   and no `family_name` → Snipe-IT shows First = first token, Last = remainder
   (e.g. "Andrin Monn" → "Andrin" / "Monn"). A user with proper
   `given_name`/`family_name` is unchanged.
3. **Gating:** `/oidc/admin/groups` as a non-superuser → 403; as a superuser →
   the page renders inside the Snipe-IT chrome.
4. **CRUD:** add, edit, and delete a mapping; duplicate (same OIDC group + Snipe
   group) shows the friendly validation error.
5. **Sync:** map an OIDC group to a Snipe-IT group, log in as a member → the user
   joins that Snipe-IT group; an OIDC group with **no** mapping does **not** sync
   (debug log notes it).
6. **Superuser:** a mapping with **Grants superuser** makes the member a
   superuser; removing the flag drops it on next login. `OIDC_ADMIN_GROUPS` still
   grants admin regardless of the table.
7. **Authoritative detach:** delete a mapping, log in again → the user is removed
   from the now-unmapped Snipe-IT group.

Record pass/fail for each in the PR description. Do not mark the task complete
until every item passes (fixing forward as needed).

---

## Self-review (completed by plan author)

- **Spec coverage:** §5.1 name split → Tasks 2–3; §5.2 data model → Tasks 4–5;
  §5.3 sync rewrite → Task 6; §5.4 UI (routes/controller/view/gating) → Tasks
  7–11; §5.5 discoverability/config/docs → Tasks 12, 14; §5.6 provider → Task
  10; §7 assumptions → verified in Task 14; §9 testing → Tasks 1–2, 13, 14. No
  gaps.
- **Placeholders:** none — every code/Blade/YAML step is complete.
- **Type/name consistency:** `NameSplitter::split` (Tasks 2/3); model
  `Bausteln\SnipeitOidc\Models\OidcGroupMapping` (Tasks 5/6/8/9); middleware
  `EnsureSuperUser` (Tasks 7/10); route names `oidc.admin.groups.*` (Tasks
  8/9/11/12); view `oidc::admin.index` (Tasks 8/11); table `oidc_group_mappings`
  + FK `permission_groups` (Tasks 4/5/8). Consistent throughout.
```
