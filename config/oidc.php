<?php

/*
|--------------------------------------------------------------------------
| Snipe-IT OIDC Configuration
|--------------------------------------------------------------------------
|
| All values come from the environment so secrets never sit in git. After
| installing the plugin, copy these keys into your Snipe-IT .env file.
|
*/

return [

    // Master switch. If false, the OIDC button is hidden and routes 404.
    'enabled' => env('OIDC_ENABLED', false),

    // The discovery URL of your IdP, e.g.
    //   https://accounts.google.com
    //   https://login.microsoftonline.com/<tenant>/v2.0
    //   https://keycloak.example.com/realms/snipeit
    'provider_url' => env('OIDC_PROVIDER_URL'),

    'client_id'     => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),

    // Space-separated scopes. "openid" is mandatory.
    'scopes' => env('OIDC_SCOPES', 'openid profile email groups'),

    // Where Snipe-IT lives. Used to build the redirect_uri the IdP must allow.
    // e.g. https://snipeit.example.com  -> redirect_uri = .../oidc/callback
    'redirect_uri' => env('APP_URL') . '/oidc/callback',

    // ------------------------------------------------------------------
    //  Claim → user-field mapping
    // ------------------------------------------------------------------
    // This is the single most important section. Snipe-IT's `users` table
    // has unique constraints on `username` and `email` — pick claims that
    // are STABLE across logins (never use a display name as a key).
    'claim_map' => [
        'username'   => env('OIDC_CLAIM_USERNAME',   'preferred_username'),
        'email'      => env('OIDC_CLAIM_EMAIL',      'email'),
        'first_name' => env('OIDC_CLAIM_FIRST_NAME', 'given_name'),
        'last_name'  => env('OIDC_CLAIM_LAST_NAME',  'family_name'),
        'groups'     => env('OIDC_CLAIM_GROUPS',     'groups'),
    ],

    // ------------------------------------------------------------------
    //  Provisioning
    // ------------------------------------------------------------------
    // 'jit'      — create users on first login (Just-In-Time provisioning)
    // 'existing' — only let users in who already exist in Snipe-IT
    'provisioning' => env('OIDC_PROVISIONING', 'jit'),

    // OIDC group claim values that grant Snipe-IT admin. Comma-separated.
    'admin_groups' => array_filter(explode(',', env('OIDC_ADMIN_GROUPS', ''))),

    // Default Snipe-IT permissions for newly provisioned non-admin users.
    // Keep this conservative — Snipe-IT permissions are additive.
    'default_permissions' => [
        'view'   => '1',
        'assets' => ['view' => '1'],
    ],
];
