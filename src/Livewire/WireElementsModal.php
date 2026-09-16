<?php

namespace WebRegulate\LaravelAdministration\Livewire;

use Illuminate\View\View;

class WireElementsModal extends \LivewireUI\Modal\Modal
{
    public function render(): View
    {
        return view(
            'wr-laravel-administration::livewire.wire-elements-modal',
            parent::render()->getData(),
        );
    }
}