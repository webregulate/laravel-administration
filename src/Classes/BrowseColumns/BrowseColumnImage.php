<?php

namespace WebRegulate\LaravelAdministration\Classes\BrowseColumns;

use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

class BrowseColumnImage extends BrowseColumnBase
{
    /**
     * Create a new instance of the class
     *
     * @param null|string|callable $imagePath Path to the image including filename. If callable is provided it takes the value as a parameter and must return an image path
     */
    public static function make(?string $label, null|string|callable $imagePath, null|string|int $width = 140): static
    {
        // Build base browse column image
        $browseColumnImage = (new self($label))
            ->allowOrdering(false)
            ->renderHtml(true)
            ->columnClass('justify-center')
            ->setOptions([
                'width' => $width,
                'value' => is_string($imagePath) ? $imagePath : null,
                'maxHeight' => 90,
            ]);

        // Set override render value callback
        $browseColumnImage->overrideRenderValue = function ($value, $model) use ($browseColumnImage, $imagePath) {
            if (!is_callable($imagePath)) {
                $value = $imagePath ?? '';
            } else {
                $value = $imagePath($value, $model);
            }

            $renderedView = view(WRLAHelper::getViewPath('components.forced-aspect-image', false), [
                'src' => $value,
                'class' => $browseColumnImage->getOption('imageClass') ?? ' border border-slate-400',
                'aspect' => $browseColumnImage->getOption('aspect'),
                'maxHeight' => $browseColumnImage->getOption('maxHeight'),
                'hideIfEmpty' => true,
            ])->render();

            return <<<BLADE
                <a href="$value" target="_blank" style="width:100%;max-width:100%;">$renderedView</a>
            BLADE;
        };

        return $browseColumnImage;
    }

    /**
     * Set aspect ratio of the image
     *
     * @param  string  $aspect  Format 1/1 (width/height)
     */
    public function aspect(string $aspect): static
    {
        $this->options['aspect'] = $aspect;

        return $this;
    }

    /**
     * Set the max-height of the image. Pass an int for pixels (e.g. 120) or a CSS
     * value string (e.g. '8rem'). Pass null to remove the max-height constraint.
     * The image always keeps its aspect ratio.
     */
    public function maxHeight(null|string|int $maxHeight): static
    {
        return $this->setOption('maxHeight', $maxHeight);
    }

    /**
     * Set CSS classes applied directly to the image element.
     */
    public function imageClass(string $class): static
    {
        return $this->setOption('imageClass', $class);
    }
}
