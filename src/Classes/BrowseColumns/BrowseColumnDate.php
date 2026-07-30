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
     * @param array $options Date specific options: ['defaultToday' => true, 'onEmpty' => '']
     */
    public static function make(?string $label, string $format = 'd/m/Y', array $options = ['defaultToday' => true, 'onEmpty' => '']): static
    {
        $browseColumnDate = (new static($label))
            ->setOptions($options)
            ->when($options['defaultToday'] ?? false, function ($column) {
                $column->default(Carbon::now()->format('Y-m-d'));
            });

        $browseColumnDate->overrideRenderValue(function ($value) use ($format) {
            if (empty($value)) {
                return $this->getOption('onEmpty') ?? '';
            }

            return Carbon::parse($value)->format($format);
        });

        return $browseColumnDate;
    }
}
