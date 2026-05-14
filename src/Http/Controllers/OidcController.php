<?php

namespace Bausteln\SnipeitOidc\Http\Controllers;

use App\Http\Controllers\Controller;
use Bausteln\SnipeitOidc\Services\OidcUserResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Jumbojett\OpenIDConnectClient;

class OidcController extends Controller
{
    public function redirectToProvider(Request $request)
    {
        $oidc = $this->client();

        // jumbojett throws/redirects internally. authenticate() will
        // (a) redirect to the IdP on first call, or
        // (b) return true once the IdP has redirected back with ?code=...
        $oidc->authenticate();

        // Execution only reaches here on the return leg.
        return redirect()->route('oidc.callback');
    }

    public function handleCallback(Request $request, OidcUserResolver $resolver)
    {
        try {
            $oidc = $this->client();
            $oidc->authenticate();

            $claims = (array) $oidc->getVerifiedClaims();
            // Some IdPs only return certain attributes from the UserInfo endpoint
            $userInfo = (array) $oidc->requestUserInfo();
            $claims = array_merge($userInfo, $claims);

            $user = $resolver->resolve($claims);

            if (! $user) {
                return redirect('/login')->withErrors([
                    'username' => 'OIDC login succeeded but no matching Snipe-IT user was found.',
                ]);
            }

            Auth::login($user, true);

            // Snipe-IT's intended-url handling
            return redirect()->intended('/');
        } catch (\Throwable $e) {
            Log::error('OIDC callback failed', ['exception' => $e]);
            return redirect('/login')->withErrors([
                'username' => 'OIDC authentication failed: ' . $e->getMessage(),
            ]);
        }
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Optional: redirect to IdP's end_session_endpoint for single-logout.
        return redirect('/login');
    }

    private function client(): OpenIDConnectClient
    {
        $oidc = new OpenIDConnectClient(
            config('oidc.provider_url'),
            config('oidc.client_id'),
            config('oidc.client_secret'),
        );

        $oidc->setRedirectURL(config('oidc.redirect_uri'));

        foreach (explode(' ', config('oidc.scopes')) as $scope) {
            if ($scope !== '') {
                $oidc->addScope($scope);
            }
        }

        return $oidc;
    }
}
