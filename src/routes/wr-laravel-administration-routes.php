<?php

use Illuminate\Support\Facades\Route;
use WebRegulate\LaravelAdministration\Http\Controllers\WRLAAdminController;
use WebRegulate\LaravelAdministration\Http\Controllers\WRLAAuthController;
use WebRegulate\LaravelAdministration\Http\Controllers\WRLADocumentationController;
use WebRegulate\LaravelAdministration\Livewire\ManageableModels\ManageableModelBrowse;
use WebRegulate\LaravelAdministration\Livewire\ManageableModels\ManageableModelUpsert;
use WebRegulate\LaravelAdministration\Livewire\ManageableModels\ManageAccount;

Route::group(['namespace' => 'WebRegulate\LaravelAdministration\Http\Controllers'], function (): void {

    // Prefix routes with the base url and name
    Route::prefix(config('wr-laravel-administration.base_url', 'wr-admin'))->name('wrla.')->group(function (): void {

        // Other
        Route::get('to-frontend', fn () => redirect('/'))->name('to-frontend');
        Route::post('upload-wysiwyg-image', [WRLAAdminController::class, 'uploadWysiwygImage'])->name('upload-wysiwyg-image');

        // Auth controller
        Route::group(['controller' => WRLAAuthController::class, 'middleware' => ['wrla_is_not_admin']], function (): void {
            // Login - If wrla_auth_routes_enabled is true
            if (config('wr-laravel-administration.wrla_auth_routes_enabled')) {
                // Base Url if not logged in
                Route::get('', fn () => redirect()->route('wrla.login'));

                // Login
                Route::get('login', 'login')->name('login');
                Route::post('login', 'loginPost')->name('login.post');
            

                // Forgot / Reset password
                Route::get('forgot-password', 'forgotPassword')->name('forgot-password');
                Route::post('forgot-password', 'forgotPasswordPost')->name('forgot-password.post');
                Route::get('reset-password/{email}/{token}', 'resetPassword')->name('reset-password');
                Route::post('reset-password/{token}', 'resetPasswordPost')->name('reset-password.post');
            }
            // If wrla_auth_routes_enabled is false, redirect to the frontend 
            else {
                Route::get('', fn () => redirect('/'));
            }
        });

        // Administration controller
        Route::group(['controller' => WRLAAdminController::class, 'middleware' => ['wrla_is_admin']], function (): void {
            // Base Url if logged in
            Route::get('', fn () => redirect()->route('wrla.dashboard'));

            // Dashboard route ('wrla.dashboard') is registered from the service
            // provider AFTER custom routes, so it can be overridden by the app in
            // WRLASettings::buildRoutes().

            // Serve private filesystem files (base64-encoded path) — admin-only
            Route::get('serve-file/{disk}/{encodedPath}', 'serveFile')->name('serve-file');
        });

        // Documentation routes
        Route::group(['controller' => WRLADocumentationController::class, 'middleware' => ['wrla_is_admin']], function (): void {
            Route::get('documentation', 'index')->name('documentation');
            Route::get('documentation/static/{path}', 'static')->where('path', '.*')->name('documentation.static');
        });

        // Impersonate routes
        Route::get('impersonate/login-as/{id}', [WRLAAuthController::class, 'impersonateLoginAs'])->name('impersonate.login-as');
        Route::get('impersonate/switch-back', [WRLAAuthController::class, 'impersonateSwitchBack'])->name('impersonate.switch-back');

        // Logout
        Route::get('logout', [WRLAAuthController::class, 'logout'])->name('logout');
    });
});

// Full-page Livewire component routes.
//
// These are defined outside the controller "namespace" group above so their
// invokable component class strings are not incorrectly prefixed with the
// controller namespace. They still share the WRLA base url, "wrla." name prefix
// and admin middleware.
Route::prefix(config('wr-laravel-administration.base_url', 'wr-admin'))
    ->name('wrla.')
    ->middleware('wrla_is_admin')
    ->group(function (): void {
        // Manage account
        Route::get('manage-account', ManageAccount::class)->name('manage-account');

        // Manageable model browse & upsert
        Route::get('browse/{modelUrlAlias}', ManageableModelBrowse::class)->name('manageable-models.browse');
        Route::get('create/{modelUrlAlias}', ManageableModelUpsert::class)->name('manageable-models.create');
        Route::get('edit/{modelUrlAlias}/{id}', ManageableModelUpsert::class)->name('manageable-models.edit');
    });
