<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseActions;

use WebRegulate\LaravelAdministration\Classes\BrowseAction;

class BrowseActionURL
{
    public static function make(string $url, string $text = 'View', string $icon = 'fa-solid fa-eye', string $color = 'secondary'): BrowseAction
    {
        return BrowseAction::make($text, $icon, $color)
            ->setHref($url);
    }
}
