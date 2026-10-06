<?php

namespace WebRegulate\LaravelAdministration\Classes\ManageableFields;

use Illuminate\View\ComponentAttributeBag;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Traits\ManageableField;

class Tags
{
    use ManageableField {
        make as protected makeBase;
    }

    /**
     * Make method (can be used in any class that extends FormComponent).
     */
    public static function make(?ManageableModel $manageableModel = null, ?string $column = null, ?array $options = null): static
    {
        $manageableField = static::makeBase($manageableModel, $column);

        $manageableField->setOptions(array_merge([
            'commonTags' => [],
            'maxTags' => null,
        ], $options ?? []));

        return $manageableField;
    }

    /**
     * Render the input field.
     */
    public function render(): mixed
    {
        return view(WRLAHelper::getViewPath('components.forms.input-tags'), [
            'label' => $this->getLabel(),
            'options' => $this->options,
            'attributes' => new ComponentAttributeBag(array_merge($this->htmlAttributes, [
                'name' => $this->getAttribute('name'),
                'value' => $this->getValue(),
                'type' => $this->getAttribute('type') ?? 'text',
            ])),

        ])->render();
    }

    /**
     * Define a list of common tags that can be clicked to inject into the input field.
     */
    public function commonTags(array $tags): static
    {
        $this->options['commonTags'] = $tags;
        return $this;
    }

    /**
     * Set the maximum number of tags allowed. Pass null to allow unlimited tags.
     */
    public function maxTags(?int $max): static
    {
        $this->options['maxTags'] = $max;
        return $this;
    }
}
