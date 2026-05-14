@if(config('oidc.enabled'))
    <div class="form-group" style="margin-top: 1.25rem;">
        <a href="{{ route('oidc.login') }}" class="btn btn-primary btn-block">
            <i class="fas fa-sign-in-alt"></i>
            {{ __('Login with SSO') }}
        </a>
        <p class="text-center" style="margin-top: 0.5rem; font-size: 0.85em; color: #888;">
            {{ __('Sign in with your organization account') }}
        </p>
    </div>
@endif
