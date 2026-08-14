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
     * @param array $options Date specific options: ['fallback' => 'N/A']
     */
    public static function make(?string $label, string $format = 'd/m/Y', array $options = ['fallback' => 'N/A']): static
    {
        $browseColumnDate = (new static($label))
            ->setOptions($options);

        $browseColumnDate->overrideRenderValue(function ($value) use ($format, $browseColumnDate) {
            if (empty($value)) {
                return $browseColumnDate->getOption('fallback') ?? 'N/A';
            }

            return Carbon::parse($value)->format($format);
        });

        return $browseColumnDate;
    }

    /**
     * Set the fallback value rendered when the date is empty (defaults to 'N/A')
     */
    public function setFallback(string $fallback): static
    {
        return $this->setOption('fallback', $fallback);
    }
}
