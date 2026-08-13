<?php

namespace WebRegulate\LaravelAdministration\Classes;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;

class BrowseAction
{
    public string $text = '';
    public ?string $icon = null;
    public ?string $color = null;
    public string $size = 'small';
    public ?string $href = null;
    public array $attributes = [];

    /**
     * Position: 'left' or 'right'.
     */
    public string $position = 'right';

    /**
     * Conditions that must ALL evaluate to true for this action to render.
     * @var array<int, bool|callable>
     */
    public array $enableConditions = [];

    /**
     * The manageable model class this browse action belongs to (set when context is needed).
     */
    public ?string $manageableModelClass = null;

    public static function make(string $text, ?string $icon = null, ?string $color = null, string $position = 'right'): static
    {
        $browseAction = new static();
        $browseAction->text = $text;
        $browseAction->icon = $icon;
        $browseAction->color = $color ?? 'primary';
        $browseAction->position = $position;

        return $browseAction;
    }

    /**
     * Set the position of this browse action ('left' or 'right').
     */
    public function setPosition(string $position): static
    {
        $this->position = $position;
        return $this;
    }

    /**
     * Shorthand to place on the left side.
     */
    public function left(): static
    {
        $this->position = 'left';
        return $this;
    }

    /**
     * Shorthand to place on the right side.
     */
    public function right(): static
    {
        $this->position = 'right';
        return $this;
    }

    /**
     * Set href link for the button.
     */
    public function setHref(string $href): static
    {
        $this->href = $href;
        return $this;
    }

    /**
     * Set the button size.
     */
    public function setSize(string $size): static
    {
        $this->size = $size;
        return $this;
    }

    /**
     * Set additional HTML attributes.
     */
    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * Merge additional HTML attributes.
     */
    public function mergeAttributes(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    /**
     * Append a condition that must be true for this browse action to render.
     */
    public function requireCondition(callable|bool $condition): static
    {
        $this->enableConditions[] = $condition;
        return $this;
    }

    /**
     * Conditional callback - only executes callback if condition passes.
     */
    public function if(callable|bool $condition, callable $callback): static
    {
        $passes = is_callable($condition) ? call_user_func($condition) : $condition;
        if ($passes) {
            return $callback($this);
        }
        return $this;
    }

    /**
     * Add a confirmation dialog before the action.
     */
    public function confirm(string $message): static
    {
        $message = addslashes($message);
        $this->attributes['x-on:click'] = $this->attributes['x-on:click'] ?? '';
        $this->attributes['x-on:click'] .= <<<JS
            if(!confirm(`{$message}`)) { event.stopImmediatePropagation(); }
        JS;
        return $this;
    }

    /**
     * Set the manageable model class context.
     */
    public function setManageableModelClass(string $manageableModelClass): static
    {
        $this->manageableModelClass = $manageableModelClass;
        return $this;
    }

    /**
     * Render the browse action button.
     */
    public function render(): View|string
    {
        // Every enable condition must pass
        foreach ($this->enableConditions as $condition) {
            $passes = is_callable($condition) ? call_user_func($condition) : $condition;
            if (!$passes) {
                return '';
            }
        }

        $attributes = $this->attributes;

        if ($this->href !== null) {
            $attributes['href'] = $this->href;
        }

        return view(WRLAHelper::getViewPath('components.forms.button'), [
            'text' => $this->text,
            'icon' => $this->icon ?? 'fa fa-cog',
            'color' => $this->color ?? 'primary',
            'size' => $this->size,
            'attributes' => Arr::toAttributeBag($attributes),
        ]);
    }
}
