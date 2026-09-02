<?php

namespace WebRegulate\LaravelAdministration\Livewire;

use Livewire\Component;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

/**
 * Base class for WRLA full-page Livewire components.
 *
 * Extend this instead of Livewire\Component to render a component as a full page
 * inside the WRLA admin layout. Register it against a normal route, e.g:
 *
 *     use App\Livewire\WRLA\MyPage;
 *
 *     Route::get('my-page', MyPage::class)->name('wrla.my-page');
 *
 * Set the page title with the standard Livewire #[Title] attribute for static
 * titles, or override getPageTitle() for dynamic ones.
 */
abstract class WRLAPageComponent extends Component
{
    /**
     * Livewire "rendering" lifecycle hook. Wraps the component's view in the
     * WRLA admin layout (theme-aware). The layout configuration is only consumed
     * on full-page loads and is a harmless no-op during Livewire update requests.
     */
    public function rendering($view): void
    {
        $view->extends(WRLAHelper::getViewPath('layouts.admin-layout'))
             ->section('content');

        $title = $this->getPageTitle();

        if ($title !== null) {
            $view->title($title);
        }
    }

    /**
     * Override to provide a dynamic page title. Return null to rely on the
     * #[Title] attribute (or to leave the page title unset).
     */
    protected function getPageTitle(): ?string
    {
        return null;
    }
}
