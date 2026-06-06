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
