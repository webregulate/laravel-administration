<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseColumns;

use Carbon\Carbon;

class BrowseColumnDate extends BrowseColumnBase
{
    /**
     * Create a new instance of the class
     *
     * @param ?string  $label  Label of the column
     * @param string  $format  Date format passed to Carbon (defaults to 'd/m/Y')
     * @param array $options Date specific options: ['onEmpty' => '']
     */
    public static function make(?string $label, string $format = 'd/m/Y', array $options = ['onEmpty' => '']): static
    {
        $browseColumnDate = (new static($label))
            ->setOptions($options);

        $browseColumnDate->overrideRenderValue(function ($value) use ($format, $browseColumnDate) {
            if (empty($value)) {
                return $browseColumnDate->getOption('onEmpty') ?? '';
            }

            return Carbon::parse($value)->format($format);
        });

        return $browseColumnDate;
    }
}
