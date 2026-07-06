<?php

namespace WebRegulate\LaravelAdministration;

use Livewire\Livewire;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\ComponentAttributeBag;
use WebRegulate\LaravelAdministration\Livewire\Logs;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Livewire\FileManager;
use WebRegulate\LaravelAdministration\Commands\UpdateCommand;
use WebRegulate\LaravelAdministration\Commands\InstallCommand;
use WebRegulate\LaravelAdministration\Commands\EditUserCommand;
use WebRegulate\LaravelAdministration\Commands\DocsCommand;
use WebRegulate\LaravelAdministration\Commands\CreateNotificationCommand;
use WebRegulate\LaravelAdministration\Commands\UninstallCommand;
use WebRegulate\LaravelAdministration\Commands\CreateUserCommand;
use WebRegulate\LaravelAdministration\Commands\SiteConfigurationCommand;
use WebRegulate\LaravelAdministration\Http\Middleware\IsAdmin;
use WebRegulate\LaravelAdministration\Http\Middleware\IsNotAdmin;
use WebRegulate\LaravelAdministration\Livewire\NotificationsWidget;
use WebRegulate\LaravelAdministration\Livewire\ImportDataModal;
use WebRegulate\LaravelAdministration\Livewire\DevTools\DevToolsModal;
use WebRegulate\LaravelAdministration\Livewire\DevTools\HandleUpdateModal;
use WebRegulate\LaravelAdministration\Classes\VersionHandler\VersionHandler;
use WebRegulate\LaravelAdministration\Commands\CreateManageableModelCommand;
use WebRegulate\LaravelAdministration\Classes\NavigationItems\NavigationItem;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\SearchableValue;
use WebRegulate\LaravelAdministration\Livewire\ManageableModels\ManageableModelBrowse;
use WebRegulate\LaravelAdministration\Livewire\ManageableModels\ManageableModelUpsert;
use WebRegulate\LaravelAdministration\Livewire\ManageableModels\ManageableModelDynamicBrowseFilters;
use WebRegulate\LaravelAdministration\Livewire\MultiUploadFields\MultiImageUploads;
use WebRegulate\LaravelAdministration\Livewire\MultiUploadFields\MultiFormGroups;
use WebRegulate\LaravelAdministration\Livewire\ManageableFields\SearchSelect as SearchSelectComponent;

class WRLAServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__ . '/config/wr-laravel-administration.php', 'wr-laravel-administration');

        // Register Livewire
        $this->app->register(\Livewire\LivewireServiceProvider::class);

        // Note we register this early to make sure it is loaded before the log viewer package,
        // this also means you can override it if you wish in your Auth/App service provider boot method.
        // Log viewer auth uses condition for wrla.logs route set in WRLASettings, if does not exist then return false
        Gate::define('viewLogViewer', function ($user) {
            // Load navigation items and get wrla.logs route navigation item
            WRLAHelper::loadNavigationItems();
            $logsNavigationItem = collect(NavigationItem::$navigationItems)->firstWhere('route', 'wrla.logs');

            // If no navigation item found, return false
            if($logsNavigationItem === null) return false;

            // Check show and enabled condition enabled
            return $logsNavigationItem->checkShowCondition() && $logsNavigationItem->checkEnabledCondition();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish assets
        $this->publishableAssets();

        // Main setup - Loading migrations, model setup, routes, views, etc.
        $this->mainSetup();

        // Build custom macros
        $this->buildCustomMacros();

        // Pass variables to all routes within this package
        $this->passVariablesToViews();

        // Provide blade directives
        $this->provideBladeDirectives();

        // Handle vendor / packages booting
        $this->handleVendorBooting();

        // Log viewer
        LogViewer::auth(function ($request) {
            return $request->user()?->wrlaUserData?->isMaster() ?? false;
        });

        // Post boot calls
        $this->app->booted(function (): void {
            $this->postBootCalls();
        });
    }

    /**
     * Set publishable assets
     */
    protected function publishableAssets(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/config/wr-laravel-administration.php' => config_path('wr-laravel-administration.php'),
            __DIR__ . '/config/wire-elements-modal.php' => config_path('wire-elements-modal.php'),
        ], 'wrla-config');

        // Publish assets
        $this->publishes([
            __DIR__ . '/resources/images/logo-light.svg' => public_path('vendor/wr-laravel-administration/images/logo-light.svg'),
            __DIR__ . '/resources/images/logo-dark.svg' => public_path('vendor/wr-laravel-administration/images/logo-dark.svg'),
            __DIR__ . '/resources/images/no-image-transparent.svg' => public_path('vendor/wr-laravel-administration/images/no-image-transparent.svg'),
        ], 'wrla-assets');
    }

    /**
     * Main setup - Loading assets, routes, etc.
     */
    protected function mainSetup(): void
    {
        // Commands
        $this->commands([
            InstallCommand::class,
            UpdateCommand::class,
            CreateManageableModelCommand::class,
            CreateNotificationCommand::class,
            CreateUserCommand::class,
            EditUserCommand::class,
            UninstallCommand::class,
            DocsCommand::class,
            SiteConfigurationCommand::class,
        ]);

        // Custom logging channels
        app('config')->set('logging.channels.smtp', [
            'driver' => 'daily',
            'path' => storage_path('logs/smtp/smtp.log'),
            'level' => 'debug',
        ]);

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // Auto relation resolving
        $this->autoModelsSetup();

        // Load middleware
        $this->app['router']->aliasMiddleware('wrla_is_admin', IsAdmin::class);
        $this->app['router']->aliasMiddleware('wrla_is_not_admin', IsNotAdmin::class);

        // Define gates
        $this->defineGates();

        // Get version information
        VersionHandler::buildLocalAndRemotePackageInformation();

        // Apply custom middleware
        $this->applyCustomMiddleware();

        // Load routes
        Route::middleware('web')->group(function (): void {
            $this->loadRoutesFrom(__DIR__ . '/routes/wr-laravel-administration-routes.php');

            // Load custom routes from WRLASettings if exists
            if(class_exists(\App\WRLA\WRLASettings::class) && method_exists(\App\WRLA\WRLASettings::class, 'buildCustomRoutes')) {
                Route::prefix(config('wr-laravel-administration.base_url', 'wr-admin'))->group(function (): void {
                    Route::group(['middleware' => ['wrla_is_admin']], function (): void {
                        \App\WRLA\WRLASettings::buildCustomRoutes();
                    });
                });
            }
        });

        // Find all classes that extend ManageableModel and register them
        WRLAHelper::registerManageableModels();

        // Register validation rules
        $this->registerValidationRules();

        // Configure rate limiting for routes - Set in wr-laravel-administration.rate_limiting config
        $this->configureRateLimiting(Request::capture());

        // Load views
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'wr-laravel-administration');

        // Livewire component registering and asset injection
        Livewire::component('wrla.manageable-models.dynamic-browse-filters', ManageableModelDynamicBrowseFilters::class);
        Livewire::component('wrla.manageable-models.browse', ManageableModelBrowse::class);
        Livewire::component('wrla.manageable-models.upsert', ManageableModelUpsert::class);
        Livewire::component('wrla.notifications-widget', NotificationsWidget::class);
        Livewire::component('wrla.import-data-modal', ImportDataModal::class);
        Livewire::component('wrla.dev-tools.dev-tools-modal', DevToolsModal::class);
        Livewire::component('wrla.dev-tools.handle-update-modal', HandleUpdateModal::class);
        Livewire::component('wrla.wire-elements-modal', \LivewireUI\Modal\Modal::class);
        Livewire::component('wrla.manageable-fields.searchable-value', SearchableValue::class);
        Livewire::component('wrla.manageable-fields.search-select', SearchSelectComponent::class);
        Livewire::component('wrla.file-manager', FileManager::class);
        Livewire::component('wrla.logs', Logs::class);
        Livewire::component('wrla.multi-upload-fields.multi-image-uploads', MultiImageUploads::class);
        Livewire::component('wrla.multi-upload-fields.multi-form-groups', MultiFormGroups::class);
        Livewire::forceAssetInjection();

        // Load custom blade directives
        Blade::component('wr-laravel-administration::components.modals.modal-layout', 'wrla-modal-layout');
        Blade::component('wr-laravel-administration::components.forms.multi-upload-fields.image-uploader', 'wrla-image-uploader');
    }

    /**
     * Configure rate limiting for applicable routes.
     */
    protected function configureRateLimiting(Request $request)
    {
        // Get the rate limiting configuration
        $rateLimitingConfig = config('wr-laravel-administration.rate_limiting');
        $prefix = 'wrla.';

        // Refresh route name lookups
        Route::getRoutes()->refreshNameLookups();
        $routes = Route::getRoutes()->getRoutesByName();

        // Loop through each route and apply rate limiting if configured
        foreach ($routes as $routeName => $route) {
            // Check if the route name starts with the specified prefix and if it exists in the rate limiting configuration
            if (str_starts_with($routeName, $prefix) && array_key_exists($routeName, $rateLimitingConfig)) {
                // Get the rate limit configuration item for the route
                $rateLimitConfigItem = $rateLimitingConfig[$routeName];

                // Build the rate limiter for the route
                WRLAHelper::buildRateLimiter($request, $routeName, $rateLimitConfigItem);

                // Apply the throttle middleware to the route
                $route->middleware("throttle:{$routeName}");
            }
        }
    }

    /**
     * Define gates
     */
    protected function defineGates(): void
    {
        Gate::define('wrla-admin', fn(mixed $user) => $user->isAdmin());
    }

    /**
     * Register custom validation rules
     */
    protected function registerValidationRules(): void
    {
        Validator::extend('wrla_no_change', function ($attribute, $value, $parameters, $validator) {
            // Parameter 0 is the table name, 1 is the id, 2 is the column name
            $tableName = $parameters[0];
            $id = $parameters[1];
            $column = $parameters[2];
            $jsonDotNotation = false; // Note that we must pass the column name in the format 'column->key1->key2' if using json notation

            // Check if column uses wrla json notation
            if(str($column)->contains('->')) {
                [$column, $jsonDotNotation] = WRLAHelper::parseJsonNotation($column);
            }

            // Use query builder to get the original value
            $originalValue = DB::table($tableName)->where('id', $id)->value($column);

            // If using json notation, get the value from the json column
            if($jsonDotNotation !== false) {
                $originalValue = data_get(json_decode($originalValue), $jsonDotNotation);
            }

            // Add message to validator
            $validator->addReplacer('wrla_no_change', function ($message, $attribute, $rule, $parameters) use ($originalValue) {
                // If originonal value is a boolean, convert to string
                if(is_bool($originalValue)) {
                    $originalValue = $originalValue ? 'true' : 'false';
                }

                return str_replace(':origional_value', $originalValue, $message);
            });

            // Check if value has changed, if type passed then check strict comparison
            return $originalValue === $value;
        }, "':attribute' cannot be changed from it's original value: :origional_value.");
    }

    /**
     * Pass variables to all views within this package
     */
    protected function passVariablesToViews(): void
    {
        // Share variables with all views within this package
        view()->composer(['wr-laravel-administration::*', '*wrla.*'], function ($view): void {
            // Theme data
            $view->with('WRLAThemeData', (object)WRLAHelper::getCurrentThemeData());

            // Share WRLAHelper class
            $view->with('WRLAHelper', WRLAHelper::class);

            // Current user data (which has relationship with current ->user)
            $view->with('user', once(function() {
                $user = WRLAHelper::getUserDataModelClass()::getCurrentUser();
                $user?->wrlaUserData?->attachUser($user);
                return $user;
            }));
        });
    }

    /**
     * Provide blade directives
     */
    protected function provideBladeDirectives(): void
    {
        // Theme view directive
        Blade::directive('themeView', function ($expression) {
            // Remove string quotes from the expression
            $viewPath = trim($expression, " \t\n\r\0\x0B'\"");

            // First check whether a theme specific view exists
            $fullViewPath = WRLAHelper::getViewPath($viewPath, true);

            // If not then fall back to the default view
            if($fullViewPath === false) {
                $fullViewPath = WRLAHelper::getViewPath($viewPath, false);
            }

            // If still false, throw error
            throw_if(
                $fullViewPath === false,
                new \Exception("@themeView error, args passed: $expression, The view '$viewPath' does not exist within the current theme or the default theme. Full view path: $fullViewPath")
            );

            // Display the view
            return "<?php echo view('{$fullViewPath}')->render(); ?>";
        });

        // Theme component attempts to load a theme specific component first, then falls back to the default component if doesn't exist
        Blade::directive('themeComponent', function ($expression) {
            // Split first argument from the rest (component path)
            $args = explode(',', $expression, 2);

            // Remove string quotes from the first argument
            $componentPath = 'components.' . trim($args[0], " \t\n\r\0\x0B'\"");

            // First check whether a theme specific comoponent exists
            $fullComponentPath = WRLAHelper::getViewPath($componentPath, true);

            // If not then fall back to the default component
            if($fullComponentPath === false) {
                $fullComponentPath = WRLAHelper::getViewPath($componentPath, false);
            }

            // If still false, throw error
            throw_if(
                $fullComponentPath === false,
                new \Exception("@themeComponent error, args passed: $expression, The component '$componentPath' does not exist within the current theme or the default theme. Full component path: $fullComponentPath")
            );

            // Display the component with the provided attributes
            return "<?php echo view('{$fullComponentPath}', {$args[1]})->render(); ?>";
        });
    }

    /**
     * Build custom macros
     */
    private function buildCustomMacros(): void
    {
        // Array to attribute bag macro
        Arr::macro('toAttributeBag', fn(array $attributes) => new ComponentAttributeBag($attributes));

        // Array prepend to all keys recursively macro
        Arr::macro('prependKeysRecursive', function (array $array, string $prefix) {
            return array_reduce(array_keys($array), function ($carry, $key) use ($array, $prefix) {
                $newKey = $prefix . $key;
                $carry[$newKey] = is_array($array[$key]) ? Arr::prependKeysRecursive($array[$key], $prefix) : $array[$key];
                return $carry;
            }, []);
        });
    }

    /**
     * Auto models setup
     */
    protected function autoModelsSetup(): void
    {
        // Add wrlaUserData relationship to the user model
        WRLAHelper::getUserModelClass()::resolveRelationUsing('wrlaUserData', function ($user) {
            // Get wrlaUserData model class
            $wrlaUserDataClass = WRLAHelper::getUserDataModelClass();

            return $user->hasOne($wrlaUserDataClass, 'user_id', 'id')
                // If user data is not found, create a new instance.
                ->withDefault(function($wrlaUserData, $user) use ($wrlaUserDataClass) {
                    // If user id is empty, return empty relationship instance
                    if(empty($user->id)) {
                        return new $wrlaUserDataClass();
                    }

                    // Get the build user id function from configuration
                    $userId = WRLAHelper::buildUserId($user);

                    if(!empty($user->id)) {
                        $existingWrlaUserData = $wrlaUserDataClass::where('user_id', $userId)->first();

                        // Return existing data if present
                        if (!empty($existingWrlaUserData)) {
                            return $existingWrlaUserData;
                        }
                    } else {
                        throw new \RuntimeException($wrlaUserDataClass . '::where("user_id", ' . $userId . ') does not return any data, user id is empty.');
                    }

                    // Populate default user data from configuration.
                    foreach (config('wr-laravel-administration.default_user_data') as $key => $value) {
                        $wrlaUserData->{$key} = is_array($value) ? json_encode($value) : $value;
                    }
                    
                    // Set user_id
                    $wrlaUserData->user_id = !empty($user->id) ? $userId : 0;
                    
                    // Save newly created default data for the user (if user exists, but no wrlaUserData)
                    if(!empty($user->id)) {
                        // Check whether wrlaUserData already exists
                        $wrlaUserDataExists = !empty($existingWrlaUserData);

                        // If not, save the new instance
                        if(!$wrlaUserDataExists) {
                            $wrlaUserData->save();
                        }
                    }

                    return $wrlaUserData;
                });
        });
    }

    /**
     * Handle vendor / packages booting
     */
    protected function handleVendorBooting(): void
    {
        // Currently does nothing
    }

    /**
     * Apply custom middleware
     */
    protected function applyCustomMiddleware(): void
    {
        // Apply config middleware.prepend and middleware.append arrays to the global middleware stack
        $middlewarePrepend = config('wr-laravel-administration.middleware.prepend', []);
        $middlewareAppend = config('wr-laravel-administration.middleware.append', []);

        // Prepend middleware
        foreach ($middlewarePrepend as $middleware) {
            $this->app['router']->prependMiddlewareToGroup('web', $middleware);
        }

        // Append middleware
        foreach ($middlewareAppend as $middleware) {
            $this->app['router']->appendMiddlewareToGroup('web', $middleware);
        }
    }

    /**
     * Post boot calls
     */
    protected function postBootCalls(): void
    {
        // Run mainSetup on all manageable models.
        foreach(WRLAHelper::$globalManageableModelData as $className => $value) {
            $className::mainSetup();
        }
    }
}
