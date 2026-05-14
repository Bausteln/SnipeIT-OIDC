<?php

use Illuminate\Support\Facades\Route;

Route::prefix('oidc')->group(function () {
    Route::get('/login',    'OidcController@redirectToProvider')->name('oidc.login');
    Route::get('/callback', 'OidcController@handleCallback')->name('oidc.callback');
    Route::get('/logout',   'OidcController@logout')->name('oidc.logout');
});
