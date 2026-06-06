<?php

use Illuminate\Support\Facades\Route;

// Controller namespace + middleware (web, auth, EnsureSuperUser) are applied by
// OidcServiceProvider when it loads this file. {group} resolves to an OidcGroup
// via implicit route-model binding.
Route::prefix('oidc/admin')->group(function () {
    Route::get('groups',                'OidcGroupController@index')->name('oidc.admin.groups.index');
    Route::post('groups',               'OidcGroupController@store')->name('oidc.admin.groups.store');
    Route::patch('groups/{group}/sync', 'OidcGroupController@toggle')->name('oidc.admin.groups.toggle');
    Route::delete('groups/{group}',     'OidcGroupController@destroy')->name('oidc.admin.groups.destroy');
});
