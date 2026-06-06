<?php

namespace Bausteln\SnipeitOidc\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Bausteln\SnipeitOidc\Models\OidcGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OidcGroupController extends Controller
{
    public function index(): View
    {
        $groups = OidcGroup::with('snipeGroup')->orderBy('name')->get();

        return view('oidc::admin.index', compact('groups'));
    }

    /**
     * Manually add an OIDC group name — for the cold start, before any login
     * has surfaced it.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        OidcGroup::firstOrCreate(['name' => $data['name']]);

        return redirect()->route('oidc.admin.groups.index')->with('success', 'Group added.');
    }

    /**
     * Toggle sync for a discovered group. Enabling it creates the matching
     * Snipe-IT group immediately (with empty permissions) so the admin can
     * configure that group's permissions right away.
     */
    public function toggle(Request $request, OidcGroup $group): RedirectResponse
    {
        if ($request->boolean('sync_enabled')) {
            if (! $group->snipe_group_id || ! Group::find($group->snipe_group_id)) {
                $group->snipe_group_id = Group::firstOrCreate(['name' => $group->name])->id;
            }
            $group->sync_enabled = true;
            $message = 'Sync enabled — Snipe-IT group ready. Set its permissions next.';
        } else {
            $group->sync_enabled = false;
            $message = 'Sync disabled.';
        }
        $group->save();

        return redirect()->route('oidc.admin.groups.index')->with('success', $message);
    }

    public function destroy(OidcGroup $group): RedirectResponse
    {
        $group->delete();

        return redirect()->route('oidc.admin.groups.index')->with('success', 'Group removed from the list.');
    }
}
