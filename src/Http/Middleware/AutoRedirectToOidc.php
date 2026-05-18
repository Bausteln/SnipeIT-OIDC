<?php

namespace Bausteln\SnipeitOidc\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Redirect anonymous visitors hitting Snipe-IT's login page straight to the
 * IdP. Off by default. Toggled via OIDC_AUTO_REDIRECT.
 *
 * Escape hatches (both keep the local login form reachable):
 *   /login?local=true     plugin-native
 *   /login?nosaml=true    matches the Snipe-IT SAML opt-out convention
 *
 * Only intercepts GET so the POST handler still works when the local form
 * is used via the escape hatch.
 */
class AutoRedirectToOidc
{
    public function handle(Request $request, Closure $next)
    {
        if (
            config('oidc.enabled') === true
            && config('oidc.auto_redirect') === true
            && $request->isMethod('GET')
            && $request->is('login')
            && ! Auth::check()
            && ! $request->boolean('local')
            && ! $request->boolean('nosaml')
        ) {
            return redirect()->route('oidc.login');
        }

        return $next($request);
    }
}
