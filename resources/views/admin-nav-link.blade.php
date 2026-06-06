@if(config('oidc.enabled') && optional(auth()->user())->isSuperUser())
  <li>
    <a href="{{ route('oidc.admin.groups.index') }}">
      <i class="fas fa-users-cog"></i> <span>{{ __('OIDC Groups') }}</span>
    </a>
  </li>
@endif
