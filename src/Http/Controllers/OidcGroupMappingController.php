<?php

namespace Bausteln\SnipeitOidc\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Bausteln\SnipeitOidc\Models\OidcGroupMapping;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OidcGroupMappingController extends Controller
{
    public function index(): View
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
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
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
