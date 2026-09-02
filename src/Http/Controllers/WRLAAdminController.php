<?php

namespace WebRegulate\LaravelAdministration\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

/**
 * Class WRLAAdminController
 *
 * This class is responsible for handling the administration routes and actions in the Laravel application.
 * It extends the base Controller class and provides methods for managing the dashboard, browsing and upserting manageable models, and logging out.
 */
class WRLAAdminController extends Controller
{
    /**
     * Upload image from wysiwyg editor
     */
    public function uploadWysiwygImage(Request $request)
    {
        return WRLAHelper::uploadWysiwygImage($request);
    }

    /**
     * Serve a file from a private storage disk (admin-only).
     * The path is base64-encoded to keep the URL clean and avoid routing issues with slashes.
     */
    public function serveFile(Request $request, string $disk, string $encodedPath)
    {
        $path = base64_decode($encodedPath, strict: true);

        // Guard against path traversal attacks.
        if ($path === false || str_contains($path, '..')) {
            abort(400, 'Invalid file path.');
        }

        $storage = Storage::disk($disk);

        if (!$storage->exists($path)) {
            abort(404);
        }

        $mimeType = $storage->mimeType($path) ?: 'application/octet-stream';

        return response($storage->get($path), 200)
            ->header('Content-Type', $mimeType)
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
