<?php

namespace WebRegulate\LaravelAdministration\Livewire;

use Livewire\Attributes\Title;
use Illuminate\Support\Facades\Route;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

#[Title('Database Schema')]
class DatabaseSchema extends WRLAPageComponent
{
    /**
     * The Truss dashboard URL embedded in an iframe. Null when redirecting instead.
     */
    public ?string $iframeSrc = null;

    public function mount()
    {
        // Hidden entirely unless the current user may access the viewer.
        if (! WRLAHelper::databaseSchemaViewerEnabled()) {
            abort(404);
        }

        $trussUrl = Route::has('truss.index')
            ? route('truss.index')
            : url('/' . config('truss.route_prefix', 'truss'));

        // Redirect mode: hand off to the standalone Truss dashboard.
        if (config('wr-laravel-administration.database_schema_viewer.display', 'embed') === 'redirect') {
            return redirect()->to($trussUrl);
        }

        $this->iframeSrc = $trussUrl;
    }

    public function render()
    {
        return view(WRLAHelper::getViewPath('livewire.database-schema'), [
            'src' => $this->iframeSrc,
            'defaultMode' => config('wr-laravel-administration.database_schema_viewer.default_mode', 'light'),
        ]);
    }
}
