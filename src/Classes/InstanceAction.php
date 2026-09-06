<?php

namespace WebRegulate\LaravelAdministration\Classes;

use Illuminate\Support\Arr;
use Illuminate\Contracts\View\View;

class InstanceAction
{
    public mixed $manageableModelInstance;
    public string $text = '';
    public ?string $icon = null;
    public ?string $color = null;
    public mixed $action = null;
    public array $attributes = [];
    public array $additonalAttributes = [];
    /**
     * Conditions that must ALL evaluate to true for this instance action to be enabled.
     * Each entry may be a bool or a callable that returns a bool. If the array is empty
     * the action is always enabled.
     * @var array<int, bool|callable>
     */
    public array $enableConditions = [];
    public ?string $actionKey = null; // Only used if action is callable

    /**
     * Multi action handler (callable taking an array of selected ids). Only used when the manageable
     * model has multi selection enabled and this action is rendered in the browse multi action toolbar.
     */
    public mixed $multiAction = null;

    /**
     * Multi action key, used to call the registered multi action handler via Livewire.
     */
    public ?string $multiActionKey = null;

    /**
     * Optional confirmation message shown before running the multi action.
     */
    public ?string $multiActionConfirm = null;

    /**
     * Optional text override for the browse multi action toolbar button.
     */
    public ?string $multiActionText = null;

    /**
     * Optional conditions that must evaluate to true for this action to be shown in the
     * browse multi action toolbar for the current selection.
     *
     * Each entry may be a bool or a callable taking the selected ids and returning a bool.
     *
     * @var array<int, bool|callable>
     */
    public array $multiActionEnableConditions = [];


    /**
     * Create instance action button
     * @param mixed $manageableModelInstance
     * @param string $text
     * @param mixed $icon
     * @param mixed $color
        * @param null|callable|string $action URL string or callable using the setAction return contract
     * @param null|bool|callable $enableCondition An initial enable condition (bool or callable returning bool)
     * @param null|array $additonalAttributes
     * @return InstanceAction
     */
    public static function make(mixed $manageableModelInstance, string $text, ?string $icon = null, ?string $color = null, null|callable|string $action = null, null|bool|callable $enableCondition = null, ?array $additonalAttributes = null): InstanceAction
    {
        // New static instance action
        $instanceAction = new static();

        // Set up instance action properties
        $instanceAction->manageableModelInstance = $manageableModelInstance;
        $instanceAction->text = $text;
        $instanceAction->icon = $icon;
        $instanceAction->color = $color;

        if(!is_null($action)) {
            $instanceAction->setAction($action);
        }

        if(!is_null($additonalAttributes)) {
            $instanceAction->setAdditionalAttributes($additonalAttributes);
        }
        
        if(!is_null($enableCondition)) {
            $instanceAction->requireCondition($enableCondition);
        }

        // Return instance action
        return $instanceAction;
    }

    /**
     * Set action
        *
      * A string action is an href. A callable receives the model and may return:
      * - A message string, shown as an info alert.
      * - An alert array, e.g. ['type' => 'danger', 'message' => 'Failed', 'ttl' => 10000, 'url' => '/details'].
      *   Only message is required; type defaults to info, ttl to config, and url to no link.
      * - A redirect, file response, or null.
      *
      * Alert types: success, danger, warning, info.
      *
      * @param callable|string $action
     * @return InstanceAction
     */
    public function setAction(callable|string $action): static
    {
        if(is_callable($action)) {
            $this->actionKey = $this->registerInstanceAction($action);
        }

        $this->action = $action;
        return $this;
    }

    /**
     * Set additional attributes
     * @param array $additonalAttributes
     * @return InstanceAction
     */
    public function setAdditionalAttributes(array $additonalAttributes): static
    {
        $this->additonalAttributes = $additonalAttributes;
        return $this;
    }

    /**
     * Append a condition that must be true for this instance action to be enabled.
     *
     * Conditions stack: the action is only enabled when every condition added here
     * evaluates to true. Call this multiple times to layer on additional requirements.
     *
     * @param callable|bool $condition A bool, or a callable returning a bool (evaluated at render time)
     * @return InstanceAction
     */
    public function requireCondition(callable|bool $condition): static
    {
        $this->enableConditions[] = $condition;
        return $this;
    }

    /**
     * Define how this action should handle a multi selection. The given callable receives an array of
      * the selected primary keys (and an optional parameters array). It supports the same return values
      * as a normal callable action.
     *
     * This action will then be rendered in the browse multi action toolbar when the manageable model
     * has multi selection enabled (see ManageableModel::setMultiSelect).
     *
     * @param callable $action Takes (array $ids, array $parameters)
     * @param ?string $confirm Optional confirmation message shown before running the action
     * @return InstanceAction
     */
    public function multiAction(callable $action, ?string $confirm = null): static
    {
        $this->multiAction = $action;
        $this->multiActionKey = $this->manageableModelInstance->registerMultiInstanceAction($action);
        $this->multiActionConfirm = $confirm;
        return $this;
    }

    /**
     * Override the text shown for this action in the browse multi action toolbar.
     */
    public function setMultiActionText(string $text): static
    {
        $this->multiActionText = $text;
        return $this;
    }

    /**
     * Append a condition that must be true for this multi action to be shown for the current selection.
     *
     * @param callable|bool $condition A bool, or a callable taking array $selectedIds and returning a bool
     * @return InstanceAction
     */
    public function requireMultiActionCondition(callable|bool $condition): static
    {
        $this->multiActionEnableConditions[] = $condition;
        return $this;
    }

    /**
     * Whether this instance action has a multi action handler defined.
     * @return bool
     */
    public function hasMultiAction(): bool
    {
        return $this->multiAction !== null && $this->multiActionKey !== null;
    }

    /**
     * Whether this multi action should be shown for the given selection.
     *
     * @param array $selectedIds
     * @return bool
     */
    public function shouldShowForSelection(array $selectedIds): bool
    {
        if (! $this->hasMultiAction()) {
            return false;
        }

        foreach ($this->multiActionEnableConditions as $condition) {
            $result = is_callable($condition)
                ? (bool) $condition($selectedIds)
                : (bool) $condition;

            if (! $result) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render the multi action button for the browse multi action toolbar. The button calls the
     * Livewire callMultiInstanceAction method which runs the handler against the currently selected ids.
     * @return string|View
     */
    public function renderMultiActionButton(): View|string
    {
        if (! $this->hasMultiAction()) {
            return '';
        }

        $buttonText = $this->multiActionText ?? $this->text;

        $confirmJs = '';
        if (! empty($this->multiActionConfirm)) {
            $message = addslashes($this->multiActionConfirm);
            $confirmJs = <<<JS
                if(!confirm(`{$message}`)) {
                    event.stopImmediatePropagation();
                    return;
                }
            JS;
        }

        $attributes = [
            'type' => 'button',
            'x-on:click' => <<<JS
                {$confirmJs}
                \$wire.callMultiInstanceAction(
                    '{$this->multiActionKey}',
                    window.wrla.instanceAction.parameters ?? {}
                );

                window.wrla.instanceAction.parameters = {};
            JS,
            'title' => $buttonText,
        ];

        return view(WRLAHelper::getViewPath('components.forms.button'), [
            'text' => $buttonText,
            'icon' => $this->icon ?? 'fa fa-cog',
            'color' => $this->color ?? 'primary',
            'size' => 'small',
            'attributes' => Arr::toAttributeBag($attributes),
        ]);
    }

    /**
     * Applies x-on:click additional attribute to confirm action from user before executing
     * @param string $message
     * @return InstanceAction
     */
    public function confirm(string $message): static
    {
        // General escaping for JavaScript string
        $message = addslashes($message);

        $this->additonalAttributes['x-on:click'] = $this->additonalAttributes['x-on:click'] ?? '';
        $this->additonalAttributes['x-on:click'] .= <<<JS
            if(!confirm(`{$message}`)) {
                event.stopImmediatePropagation();
            }
        JS;

        return $this;
    }

    /**
     * Prompt user and pass as ['input' => 'user input'] parameter to action
     * @param string $message
     * @param bool $allowEmpty If true, allows empty input; if false, user must provide input or cancel
     * @param ?string $default Optional default value pre-filled in the prompt
     * @return InstanceAction
     */
    public function ask(string $message, bool $allowEmpty = false, ?string $default = null): static
    {
        // General escaping for JavaScript string
        $message = addslashes($message);
        $defaultJs = is_null($default) ? "''" : '`'.addslashes($default).'`';

        $allowEmptyJs = $allowEmpty ? 'true' : 'false';

        $this->additonalAttributes['onclick'] = $this->additonalAttributes['onclick'] ?? '';
        $this->additonalAttributes['onclick'] .= <<<JS
            let input = prompt(`{$message}`, {$defaultJs});

            if(input === null || (!{$allowEmptyJs} && input.trim() === '')) {
                event.stopImmediatePropagation();
                return;
            }

            window.wrla.instanceAction.parameters = { input: input };

            window.buttonSignifyLoading(this, () => new Promise((resolve) => {
                Livewire.on('instanceActionCompleted', () => {
                    resolve();
                });
            }));
        JS;

        return $this;
    }

    /**
     * If
     * 
     * @param callable $callback Must take and return InstanceAction
     */
    public function if(callable|bool $condition, callable $callback): static
    {
        if(is_callable($condition) ? call_user_func($condition) : $condition) {
            return $callback($this);
        }

        return $this;
    }

    /**
     * Register instance action on manageable model instance and get action key
     * @return string
     */
    public function registerInstanceAction($action): string
    {
        // Register the action with the manageable model instance and return action key
        return $this->manageableModelInstance->registerInstanceAction($action);
    }

    /**
     * Render the instance action button
     * @throws \Exception
     * @return string|View
     */
    public function render(): View|string
    {
        // Get instance id
        $instanceId = $this->manageableModelInstance->model()->id;

        // Attributes
        if (is_string($this->action)) {
            $attributes = ['href' => $this->action];
        } elseif (is_callable($this->action)) {
            // Found that window.wrla.instanceAction.parameters was empty using wire:click
            // $attributes = ['wire:click' => <<<JS
            //     callManageableModelAction('{$instanceId}', '{$this->actionKey}', window.wrla.instanceAction.parameters ?? {});
            //     window.wrla.instanceAction.parameters = {};
            // JS];

            $this->additonalAttributes['x-on:click'] = $this->additonalAttributes['x-on:click'] ?? '';
            $this->additonalAttributes['x-on:click'] .= <<<JS
                \$wire.callManageableModelAction(
                    '{$instanceId}',
                    '{$this->actionKey}',
                    window.wrla.instanceAction.parameters ?? {}
                );

                window.wrla.instanceAction.parameters = {};
            JS;
        } elseif(!is_null($this->action)) {
            throw new \Exception('Action must be a string URL, callable, or null');
        }

        // Every enable condition must evaluate to true, otherwise return empty view.
        // Each condition may be a bool or a callable returning a bool. An empty array
        // means the action is always enabled.
        foreach ($this->enableConditions as $condition) {
            $passes = is_callable($condition) ? call_user_func($condition) : $condition;
            if (!$passes) {
                return '';
            }
        }

        // If additional attributes do not contain 'title', set title from text
        if (empty($this->additonalAttributes['title'])) {
            $this->additonalAttributes['title'] = $this->text;
        }

        // Return button view
        return view(WRLAHelper::getViewPath('components.forms.button'), [
            'text' => $this->text,
            'icon' => $this->icon ?? 'fa fa-cog',
            'color' => $this->color ?? 'primary',
            'size' => 'small',
            'attributes' => Arr::toAttributeBag(array_merge($this->additonalAttributes ?? [], $attributes ?? [])),
        ]);
    }
}