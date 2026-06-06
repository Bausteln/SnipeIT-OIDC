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
