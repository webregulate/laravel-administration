<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseActions;

use WebRegulate\LaravelAdministration\Classes\BrowseAction;

class BrowseActionExportCSV
{
    public static function make(string $manageableModelClass): BrowseAction
    {
        return BrowseAction::make('Export CSV', 'fa fa-file-csv', 'primary')
            ->setAttributes([
                'x-on:click' => <<<'JS'
                    (() => {
                        const totalEl = document.querySelector('[data-wrla-browse-total]');
                        const total = totalEl ? parseInt(totalEl.getAttribute('data-wrla-browse-total'), 10) || 0 : 0;
                        let limit = null;
                        if (total > 1000) {
                            const response = window.prompt(
                                'There are ' + total + ' rows in this table, how many would you like to export?',
                                total
                            );
                            if (response === null) return;
                            const parsed = parseInt(response, 10);
                            if (isNaN(parsed) || parsed <= 0) return;
                            limit = parsed;
                        }
                        $wire.exportAsCSVAction(null, limit);
                    })();
                JS,
                'wire:target' => 'exportAsCSVAction',
                'wire:loading.attr' => 'disabled',
                'wire:loading.class' => 'opacity-80 cursor-not-allowed',
            ]);
    }
}
