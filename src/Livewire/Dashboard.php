<?php

namespace WebRegulate\LaravelAdministration\Livewire;

use Livewire\Attributes\Title;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

#[Title('Dashboard')]
class Dashboard extends WRLAPageComponent
{
    public function render()
    {
        return view(WRLAHelper::getViewPath('livewire.dashboard'));
    }
}
