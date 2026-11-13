<?php

namespace WebRegulate\LaravelAdministration\Classes\ManageableFields;

use Illuminate\Http\Request;
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

        // make() registers a JSON decoder only when the stored value is an array.
        // Remember that before appending the dedupe callback below.
        $storeAsJson = $manageableField->hasApplySubmittedValueCallback();

        $manageableField->setOptions(array_merge([
            'commonTags' => [],
            'maxTags' => null,
        ], $options ?? [], [
            'storeAsJson' => $storeAsJson,
        ]));

        // Run after the array/json cast decoder so a decoded list is deduped in place.
        // String columns never see that decoder and stay comma-separated.
        $manageableField->appendApplySubmittedValue(function (Request $request, mixed $value) use ($manageableField): mixed {
            return $manageableField->applySubmittedValue($request, $value);
        });

        return $manageableField;
    }

    /**
     * Render the input field.
     */
    public function render(): mixed
    {
        $tagValue = static::normaliseSubmittedTags(
            $this->getValue(),
            (bool) ($this->options['storeAsJson'] ?? false),
        );
        if (!is_string($tagValue)) {
            $tagValue = '';
        }

        return view(WRLAHelper::getViewPath('components.forms.input-tags'), [
            'label' => $this->getLabel(),
            'options' => $this->options,
            'tagValue' => $tagValue,
            'attributes' => new ComponentAttributeBag(array_merge($this->htmlAttributes, [
                'name' => $this->getAttribute('name'),
                'value' => $tagValue,
                'type' => $this->getAttribute('type') ?? 'text',
            ])),

        ])->render();
    }

    /**
     * Drop duplicate tags.
     *
     * Comma-separated strings stay comma-separated. Pass $jsonList for a JSON
     * array string (array/json casts). PHP arrays are deduped only when they are
     * lists; associative arrays are left untouched. Other types are unchanged.
     */
    public static function normaliseSubmittedTags(mixed $value, bool $jsonList = false): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                return $value;
            }

            return static::normaliseTagList($value);
        }

        if (!is_string($value)) {
            return $value;
        }

        if (trim($value) === '') {
            return '';
        }

        if ($jsonList) {
            if (!WRLAHelper::isJson($value)) {
                return $value;
            }

            $decoded = json_decode($value, true);
            if (!is_array($decoded) || !array_is_list($decoded)) {
                return $value;
            }

            $normalised = static::normaliseTagList($decoded);
            if ($normalised === $decoded) {
                return $value;
            }

            $encoded = json_encode($normalised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? $value : $encoded;
        }

        return implode(',', static::normaliseTagList(explode(',', $value)));
    }

    /**
     * Apply submitted value with duplicate tags removed.
     *
     * String columns arrive as comma-separated text. Array/json casts are decoded
     * to a list before this runs, and the list is returned still a list.
     */
    public function applySubmittedValue(Request $request, mixed $value): mixed
    {
        return static::normaliseSubmittedTags($value);
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

    /**
     * Unique tag list, first occurrence wins. Non-scalar lists are left unchanged
     * so nested JSON that is not a tag list is not rewritten.
     *
     * @param  array<mixed>  $tags
     * @return array<mixed>
     */
    private static function normaliseTagList(array $tags): array
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) && !is_int($tag) && !is_float($tag)) {
                return $tags;
            }
        }

        $unique = [];
        $seen = [];

        foreach ($tags as $tag) {
            $text = trim((string) $tag);
            if ($text === '' || isset($seen['s:'.$text])) {
                continue;
            }

            $seen['s:'.$text] = true;
            $unique[] = is_string($tag) ? $text : $tag;
        }

        return $unique;
    }
}
