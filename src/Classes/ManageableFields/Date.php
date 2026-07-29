<?php

namespace WebRegulate\LaravelAdministration\Classes\ManageableFields;

use Illuminate\View\ComponentAttributeBag;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Traits\ManageableField;

class Date
{
    use ManageableField {
        make as protected makeBase;
    }

    /**
     * Field type constants
     */
    public const TYPE_DATE = 'date';
    public const TYPE_TIME = 'time';
    public const TYPE_DATETIME = 'datetime';

    /**
     * Make the field, setting the input type and default validation based on the given type.
     *
     * @param string  $type  One of Date::TYPE_DATE, Date::TYPE_TIME, Date::TYPE_DATETIME
     * @param array $options Date specific options: ['defaultNow' => true]
     */
    public static function make(
        ?ManageableModel $manageableModel = null,
        ?string $column = null,
        string $type = self::TYPE_DATE,
        ?array $options = ['defaultNow' => true],
    ): static {
        $manageableField = static::makeBase($manageableModel, $column, $options)
            ->when($options['defaultNow'] ?? false, function ($field) use ($type) {
                $field->default(match ($type) {
                    self::TYPE_DATE => now()->format('Y-m-d'),
                    self::TYPE_DATETIME => now()->format('Y-m-d\TH:i'),
                    self::TYPE_TIME => now()->format('H:i'),
                    default => now()->format('Y-m-d'),
                });
            });

        // Map the type to the HTML input type and default validation rule
        [$inputType, $validationRules] = match ($type) {
            self::TYPE_TIME => ['time', 'date_format:H:i'],
            self::TYPE_DATETIME => ['datetime-local', 'date'],
            default => ['date', 'date'],
        };

        $manageableField->setAttribute('type', $inputType);
        $manageableField->validation($validationRules);

        return $manageableField;
    }

    /**
     * Render the input field.
     */
    public function render(): mixed
    {
        return view(WRLAHelper::getViewPath('components.forms.input-text'), [
            'label' => $this->getLabel(),
            'options' => $this->options,
            'attributes' => new ComponentAttributeBag(array_merge($this->htmlAttributes, [
                'name' => $this->getName(),
                'value' => $this->getValue(),
                'type' => $this->getAttribute('type') ?? 'date',
            ])),
        ])->render();
    }
}
