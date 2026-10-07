<?php

namespace WebRegulate\LaravelAdministration\Classes\DatabaseSchema;

use AlbertoArena\Truss\Http\Controllers\AssetController as TrussAssetController;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetController
{
    public function __construct(private readonly TrussAssetController $controller) {}

    public function __invoke(string $file): Response|BinaryFileResponse
    {
        $response = ($this->controller)($file);

        if ($file !== 'mermaid-definition.js') {
            return $response;
        }

        $javascript = str_replace(
            '${column.name}',
            '${String(column.name).replace(/^(?=[0-9])/, "_")}',
            (string) file_get_contents($response->getFile()->getPathname()),
        );

        return new Response($javascript, Response::HTTP_OK, [
            'Content-Type' => 'text/javascript',
            'Cache-Control' => $response->headers->get('Cache-Control'),
        ]);
    }
}