<?php

$path = getenv('WRLA_TEST_APP_PATH');

// Use a consuming application's installed Laravel and PHPUnit dependencies
if (!$path || !is_file($path . '/vendor/autoload.php')) {
    throw new RuntimeException('Set WRLA_TEST_APP_PATH to a Laravel application with WRLA dependencies installed.');
}

$loader = require $path . '/vendor/autoload.php';
$source = dirname(__DIR__) . '/src';
$loader->addPsr4('WebRegulate\\LaravelAdministration\\', $source, true);
$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
    // Override the installed package's optimized classmap with this checkout
    if ($file->isFile() && $file->getExtension() === 'php') {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $classes['WebRegulate\\LaravelAdministration\\' . str_replace(['/', '\\'], '\\', substr($relative, 0, -4))] = $file->getPathname();
    }
}
$loader->addClassMap($classes);
require __DIR__ . '/Fixtures/RuntimeSettings.php';
