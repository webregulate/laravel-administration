<?php

use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\Image;
use WebRegulate\LaravelAdministration\Classes\ManageableFields\ImageCroppable;

return [

    /* ------------------------------------------------------------------------
        STRUCTURAL CONFIGURATION
    --------------------------------------------------------------------------*/

    // Base URL for the administration panel, e.g. 'wr-admin' will result in 'http://example.com/wr-admin'
    'base_url' => 'wr-admin',

    // Model definitions
    'models' => [
        'user' => \App\Models\User::class,
        'wrla_user_data' => \App\Models\UserData::class,
    ],

    // Middleware to prepend or append to each request within WRLA. Use array of class strings such as Middleware::class
    'middleware' => [
        'prepend' => [],
        'append' => [],
        'commands' => [],
    ],


    /*-------------------------------------------------------------------------
        SECURITY CONFIGURATION
    --------------------------------------------------------------------------*/

    // WRLA auth routes enabled (Disable to switch off WRLA's login, forgot password, reset password, etc.)
    'wrla_auth_routes_enabled' => true,

    // Captcha configuration (used for login and forgot password)
    'captcha' => [
        // Use 'turnstile' for CloudFlare Turnstile, or null to disable
        'current' => null,

        // Turnstile
        'turnstile' => [
            'site_key' => env('CLOUDFLARE_TURNSTILE_SITEKEY', ''),
            'secret_key' => env('CLOUDFLARE_TURNSTILE_SECRET', ''),
        ],
    ],

    // Multi factor authentication (MFA) configuration
    'mfa' => [
        // Supports:
        // null - to disable MFA
        // 'pragmarx/google2fa' To use:
        //      1. run: composer require 'pragmarx/google2fa-qrcode' 'bacon/bacon-qr-code';
        //      2. Setup related env variables in your .env, names shown within the pragmarx/google2fa configuration below
        'current' => null,

        // 'pragmarx/google2fa'
        'pragmarx/google2fa' => [
            'title' => '{app.name}' // Must not be empty. You can use any config string here, e.g. '{app.name}' will be replaced with the value from your .env file
        ]
    ],

    // Rate limiting for wrla. routes
    // Note: each key is bound to middleware 'throttle:route_name' in routes automatically (Within WRLAServicesProvider.php)
    'rate_limiting' => [
        'login.post' => [
            'rule' => 'input:email ip',
            'max_attempts' => 5,
            'decay_minutes' => 10,
            'message' => 'Too many login requests. Please try again in :decay_minutes minutes.',
        ],
        'forgot-password.post' => [
            'rule' => 'input:email ip',
            'max_attempts' => 2,
            'decay_minutes' => 10,
            'message' => 'Too many forgot password requests. Please try again in :decay_minutes minutes.',
        ],
        'reset-password.post' => [
            'rule' => 'input:email ip',
            'max_attempts' => 2,
            'decay_minutes' => 10,
            'message' => 'Too many reset password requests. Please try again in :decay_minutes minutes.',
        ],
    ],

    // Default validation rules for manageable fields
    'default_validation_rules' => [
        Image::class => 'nullable|image|max:4096|mimes:jpg,jpeg,png,gif,webp,svg|extensions:jpg,jpeg,png,gif,webp,svg',
        ImageCroppable::class => 'nullable|image|max:4096|mimes:jpg,jpeg,png,gif,webp,svg|extensions:jpg,jpeg,png,gif,webp,svg',
    ],

    // Default manageable model permissions, must be a boolean or function that returns a boolean.
    // Can be overriden in a manageable model's mainSetup method.
    'default_manageable_model_permissions' => [
        ManageableModelPermissions::ENABLED->value => fn($wrlaUserData) => $wrlaUserData?->isAdmin() ?? false,
        ManageableModelPermissions::CREATE->value => true,
        ManageableModelPermissions::BROWSE->value => true,
        ManageableModelPermissions::EDIT->value => true,
        ManageableModelPermissions::DELETE->value => true,
        ManageableModelPermissions::RESTORE->value => true,
    ],

    // Developer tooling configuration
    'developer' => [
        // Callback (or bool) for enabling developer tools, takes wrlaUserData and must return boolean.
        // IMPORTANT: keep this null by default. When null, WRLA falls back to the legacy
        // `enable_developer_tools` key below, which keeps the in-UI version/update button accessible
        // on applications that haven't migrated their published config yet (backwards compatibility).
        // EG. use: fn($wrlaUserData) => $wrlaUserData?->isMaster() to enable for master users only.
        'enable' => null,

        // Composer behaviour for the wrla:update command
        'composer' => [
            // App environments that should run `composer update --no-dev`
            'no_dev' => ['production'],
        ],

        // How the web dev-tools "Update WRLA" modal runs updates:
        //  'live'     - run in the background and stream the console output to the modal as it happens
        //  'blocking' - run synchronously and show the full output once finished
        'update' => [
            'mode' => 'live',
        ],
    ],

    // Documentation configuration
    'documentation' => [
        // Whether the documentation page is accessible at all
        'enabled' => true,
        // Whether to show the documentation link in the top bar. Accepts bool or Closure taking wrlaUserData and returning bool.
        'show_link' => fn($wrlaUserData) => $wrlaUserData?->isMaster() ?? false,
    ],


    /*-------------------------------------------------------------------------
        GENERAL CONFIGURATION
    --------------------------------------------------------------------------*/

    // How the page title should be displayed
    'title_template' => '{page_title} - WebRegulate Admin',

    // Logging
    'logging' => [
        'wrla-errors' => [
            'enabled' => true,
            'config' => [
                'driver' => 'daily',
                'path' => storage_path('logs/wrla-errors/wrla-errors.log'),
                'level' => 'error',
                'days' => 14,
            ],
        ],
        'wrla-info' => [
            'enabled' => true,
            'config' => [
                'driver' => 'daily',
                'path' => storage_path('logs/wrla-info/wrla-info.log'),
                'level' => 'info',
                'days' => 14,
            ],
        ],
        'wrla-events' => [
            'enabled' => true,
            'config' => [
                'driver' => 'daily',
                'path' => storage_path('logs/wrla-events/wrla-events.log'),
                'level' => 'info',
                'days' => 14,
            ],
        ],
    ],

    // Company logo
    'logo' => [
        'light' => 'vendor/wr-laravel-administration/images/logo-light.svg',
        'dark' => 'vendor/wr-laravel-administration/images/logo-dark.svg',
    ],

    // Wysiwyg editors
    'wysiwyg_editors' => [
        // Supported: 'quill', 'tinymce'
        'current' => 'quill',

        // Quill - https://quilljs.com/docs/modules/toolbar
        'quill' => [
            'initialise' => <<<JS
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, 3, 4, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        ['blockquote'],
                        ['link', 'image', 'video'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'align': [] }],
                        ['clean']
                    ]
                }
            JS,
            'class' => 'min-h-48',
            'image_uploads' => [
                'filesystem' => 'public',
                'path' => 'storage/images/uploads',
            ],
        ],

        // TinyMCE - https://www.tiny.cloud/docs/tinymce/latest
        'tinymce' => [
            'apikey' => env('TINYMCE_API_KEY', ''), // Add your TinyMCE API key in your .env file
            'plugins' => 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount code paste fullscreen',
            'menubar' => 'edit view insert tools table',
            'toolbar' => 'undo redo | bold italic underline | link image media table | align | numlist bullist indent | code',
            'image_uploads' => [
                'filesystem' => 'public',
                'path' => 'storage/images/uploads',
            ],
        ],
    ],

    // Error catching, in certain locations we can catch errors and display them in the UI instead of throwing exceptions
    'catch_errors' => [
        'upsert' => true, // Catch errors during upsert operations
    ],

    // User avatar, override the default user image path with a callback function that passes the \App\Models\User model as an argument
    'user_avatar' => null,
    // 'user_avatar' => fn(\App\Models\User $user) => $user->profile_image,

    // Default user data fields (JSON)
    'default_user_data' => [
        'permissions' => ['admin' => false, 'master' => false],
        'settings' => [],
        'data' => [],
    ],

    // User groups, each must be a function that returns a Collection of users
    'user_groups' => [
        'admin' => fn() => WRLAHelper::getUserModelClass()::whereIn('id',
            WRLAHelper::getUserDataModelClass()
                ::whereJsonContains('permissions', ['admin' => true])
                ->get()
                ->pluck('user_id')
        )->get()
    ],

    // Build user_id identifier for wrlaUserData, if not set will default to simply $user->id
    'build_wrla_user_data_id' => function(mixed $user) {
        return $user->id;
    },

    // Email templates
    'email_templates' => [
        'render_mode' => 'markdown', // 'markdown', 'html', or 'text'
    ],

    // Dashboard display notifications for users / groups, use '@self' for the user's own notifications
    'dashboard' => [
        'notifications' => [
            'user_groups' => ['@self', 'admin'],
        ],
    ],

    // Browse (model listing) configuration
    'browse' => [
        // Pagination options used on the browse pages
        'pagination' => [
            // Default number of records shown per page
            'default' => 20,

            // Selectable per-page options shown in the browse pagination dropdown
            'perPage' => [
                20,
                30,
                50,
                75,
                100,
            ],
        ],
    ],

    // CSV import configuration (used by the ImportDataModal)
    'csv_imports' => [
        'chunk_size' => 500,
        'max_failed_reasons' => 10,
        'ignore_columns' => ['id'],
        'upload_rules' => [
            'required',
            'file',
            'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/octet-stream',
            'max:61440',
        ],
    ],

    // File manager configuration
    'file_manager' => [
        'enabled' => true,
        'max_characters' => 500000,
        'default_filesystem' => 'public', // Use null or empty string to display all filesystems
        'can_upload_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/svg+xml',
            'image/webp',
            'video/mp4'
        ],
        'file_systems' => [
            'public' => [
                'enabled' => true,
            ],
        ]
    ],

    // Logs configuration
    'logs' => [
        'current' => 'opcodesio/log-viewer', // 'wrla', 'opcodesio/log-viewer'

        // wrla
        'wrla' => [
            'max_characters' => 1000000,
        ],

        // opcodesio/log-viewer
        'opcodesio/log-viewer' => [
            // Configure in config/log-viewer.php
            'display_within_wrla' => true, // Display within WRLA instead of redirecting
        ],
    ],


    /*-------------------------------------------------------------------------
        THEME / STYLING CONFIGURATION
    --------------------------------------------------------------------------*/

    // Default theme (key from the 'themes' array below)
    'default_theme' => 'default',

    // Themes
    'themes' => [
        // Default
        'default' => [
            'name' => 'Default',        // Name of the theme displayed to user (if multiple themes are available)
            'path' => 'default',        // Path to the theme folder in the 'resources/views/themes/?' directory
            'default_mode' => 'light',  // Default mode for the theme (dark or light)
            'no_image_src' => '/vendor/wr-laravel-administration/images/no-image-transparent.svg',
        ]
    ],

    // Colors - These add/override tailwind's available colors in the layouts
    'colors' => [
        // Use this amazing tailwind color generator: https://uicolors.app/create to generate your color palette
        'primary' => [
            '50'  => '#eefffb',
            '100' => '#c6fff3',
            '200' => '#8effe9',
            '300' => '#4dfbdc',
            '400' => '#19e8ca',
            '500' => '#00bfa6',
            '600' => '#00a493',
            '700' => '#028376',
            '800' => '#08675f',
            '900' => '#0c554e',
            '950' => '#003432',
        ],
        'notes' => [
            '200' => '#e2f0fb',
            '300' => '#c8dae9',
            '400' => '#a3b9d1',
            '600' => '#417ece',
            '700' => '#2e5f9e',
            '800' => '#162945',
            '900' => '#1c3f6e',
        ],
        'slate' => [
            '50'  => '#f8fafc',
            '100' => '#f1f5f9',
            '200' => '#e2e8f0',
            '300' => '#cbd5e1',
            '400' => '#94a3b8',
            '500' => '#64748b',
            '550' => '#56657A',
            '600' => '#475569',
            '700' => '#334155',
            '725' => '#303d51',
            '750' => '#2a364a',
            '800' => '#1e293b',
            '850' => '#161E2E',
            '900' => '#0f172a',
            '950' => '#020617',
        ],
    ],

    // Common CSS - Be careful that this does not break the layout as this is injected into the head of the layout
    'common_css' => <<<CSS_WRAP
        /* Add your custom / override CSS here */
    CSS_WRAP,

    // Wysiwyg CSS - Be careful that this does not break the layout as this is injected into the wysiwyg editor
    'wysiwyg_css' => <<<CSS_WRAP
        /* Add your custom / override CSS here */
    CSS_WRAP,
];
