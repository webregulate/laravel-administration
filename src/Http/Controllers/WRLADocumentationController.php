<?php

namespace WebRegulate\LaravelAdministration\Http\Controllers;

use Illuminate\View\View;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

class WRLADocumentationController extends Controller
{
    /**
     * Documentation page view
     */
    public function index(Request $request): View
    {
        // Enforce documentation's runtime access rule
        if(!WRLAHelper::documentationEnabled()) {
            abort(404);
        }

        return view(WRLAHelper::getViewPath('documentation'));
    }

    /**
     * Serve static files (JS, assets, pages) from the docs/ folder.
     * Top-level .html pages (e.g. installation.html) redirect to index.html#{page}
     * so the docs SPA handles rendering correctly even on direct navigation.
     */
    public function static(Request $request, string $path): \Illuminate\Http\Response|RedirectResponse
    {
        // Protect documentation assets with the same runtime rule
        if(!WRLAHelper::documentationEnabled()) {
            abort(404);
        }

        // Security: reject path traversal attempts before any file access.
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            abort(400, 'Invalid path.');
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Redirect direct .html navigation (e.g. installation.html) to index.html#page
        // so the SPA loads the correct content via hash routing.
        if ($ext === 'html' && !str_contains($path, '/') && $path !== 'index.html') {
            return redirect(route('wrla.documentation.static', ['path' => 'index.html']) . '#' . $path);
        }

        $docsPath = realpath(__DIR__ . '/../../../docs');
        $filePath = $docsPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

        if (!file_exists($filePath) || is_dir($filePath)) {
            abort(404);
        }

        // Belt-and-suspenders: confirm resolved path is still within docs/.
        $resolved = realpath($filePath);
        if ($resolved === false || !str_starts_with($resolved, $docsPath)) {
            abort(403);
        }

        $mimeTypes = [
            'js'   => 'application/javascript',
            'css'  => 'text/css',
            'html' => 'text/html',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
        ];
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

        return response(file_get_contents($resolved), 200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
