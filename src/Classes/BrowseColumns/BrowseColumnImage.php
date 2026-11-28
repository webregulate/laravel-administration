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
                'objectFit' => 'contain',
            ]);

        // Set override render value callback
        $browseColumnImage->overrideRenderValue = function ($value, $model) use ($browseColumnImage, $imagePath) {
            if (!is_callable($imagePath)) {
                $value = $imagePath ?? '';
            } else {
                $value = $imagePath($value, $model);
            }

            $colWidth = $browseColumnImage->getOption('width') ?? 140;
            $colWidthPx = is_int($colWidth) ? "{$colWidth}px" : $colWidth;

            $renderedView = view(WRLAHelper::getViewPath('components.forced-aspect-image', false), [
                'src' => $value,
                'class' => $browseColumnImage->getOption('imageClass') ?? ' border border-slate-400',
                'aspect' => $browseColumnImage->getOption('aspect'),
                'maxHeight' => $browseColumnImage->getOption('maxHeight'),
                'objectFit' => $browseColumnImage->getOption('objectFit') ?? 'contain',
                'hideIfEmpty' => true,
            ])->render();

            $mobileWidth = $browseColumnImage->getOption('mobileWidth');
            $class = '';
            $sizing = 'width:'.e($colWidthPx).';max-width:'.e($colWidthPx).';';

            if ($mobileWidth !== null) {
                $class = 'max-md:[--wrla-browse-image-width:var(--wrla-browse-image-mobile-width)]';
                $mobileWidthPx = is_int($mobileWidth) ? "{$mobileWidth}px" : $mobileWidth;
                $width = 'var(--wrla-browse-image-width,'.e($colWidthPx).')';
                $sizing = '--wrla-browse-image-mobile-width:'.e($mobileWidthPx).";width:{$width};max-width:{$width};";
            }

            return '<a href="'.e($value).'" target="_blank" class="'.$class.'" style="display:block;'.$sizing.'">'.$renderedView.'</a>';
        };

        return $browseColumnImage;
    }

    public function mobileWidth(string|int $width): static
    {
        return $this->setOption('mobileWidth', $width);
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

    /**
     * Set the object-fit CSS property for the image. Use 'contain', 'cover', 'fill', etc.
     * Pass the alias 'fit' for 'contain'.
     */
    public function objectFit(string $objectFit): static
    {
        return $this->setOption('objectFit', $objectFit);
    }
}
