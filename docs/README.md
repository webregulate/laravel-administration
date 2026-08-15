# WRLA Documentation

This directory contains the local documentation for WebRegulate Laravel Administration.

## Serving the Documentation

### Method 1: Using the Artisan Command (Recommended)

The cross-platform way to serve the documentation:

```bash
php artisan wrla:docs
```

You can also specify a custom port:

```bash
php artisan wrla:docs --port=3000
```

This command works on:
- **Windows**: Uses `start` to open browser
- **macOS**: Uses `open` to open browser  
- **Linux**: Uses `xdg-open` to open browser

### Method 2: Using the Scripts Directly

For manual serving, you can use the platform-specific scripts:

#### Windows
```cmd
serve.bat
```

#### Linux/macOS
```bash
./serve.sh
```

Both scripts will:
1. Start a PHP development server on port 8888
2. Open the documentation in your default browser
3. Serve the documentation files from this directory

## Stopping the Server

Press `Ctrl+C` in the terminal where the server is running.

## Port Configuration

The default port is 8888. If the port is already in use, you can either:
- Use a different port with `php artisan wrla:docs --port=3000`
- Stop the process using port 8888 and try again

## Contributing / Development Setup

After cloning the repository, activate the pre-commit hook so that `docs/versions.json` is
automatically kept in sync with the latest `VersionUpdate` class:

```bash
git config core.hooksPath .githooks
```

This only needs to be done once per clone. The hook fires before every commit and prepends a new
entry to `docs/versions.json` if a new `Versions/Version_*.php` file has been added with a higher
version number. You should then also create the corresponding `docs/pages/versions/v{version}.html`
release notes page.