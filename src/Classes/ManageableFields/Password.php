<?php

namespace WebRegulate\LaravelAdministration\Classes\ManageableFields;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Traits\ManageableField;

class Password
{
    use ManageableField;

    /**
     * Post constructed method, called after name and value attributes are set.
     *
     * @return $this
     */
    public function postConstructed(): static
    {
        $name = $this->getAttribute('name');

        $this->validation('required_if_accepted:wrla_show_'.$name.'|string|confirmed|min:6|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/');
        $this->setAttribute('placeholder', 'Atleast 6 characters, and have atleast 1 uppercase, 1 lowercase, 1 number');
        $this->setOption('ignoreOld', true);

        // Opt out of the upsert component's automatic single wire:model binding. This field
        // renders two independent inputs (password + confirmation) and manages their values
        // itself via Alpine, so a shared livewire binding must not be applied to both.
        $this->setAttribute('wire:model', 'livewireData.'.$name);

        return $this;
    }

    /**
     * The value seeded into livewire / bound to the input. Passwords are write-only: never seed
     * the stored hash into livewireData (it would be exposed to the client and re-hashed on save).
     */
    public function getLivewireValue(): mixed
    {
        return '';
    }

    /**
     * Passwords are always seeded empty (write-only), so there is nothing to normalise. Overridden
     * to avoid rendering the field during the upsert component's first-render seeding pass.
     */
    public function prepareLivewireValue(): void
    {
    }

    /**
     * Clear this field's livewire state after a successful save so a stale value is never
     * resubmitted on a subsequent save (which would otherwise re-change the password).
     *
     * @param  array  $livewireData  The upsert component's livewire data (by reference).
     */
    public function resetLivewireAfterSave(array &$livewireData): void
    {
        $name = $this->getAttribute('name');
        $livewireData[$name] = '';
        $livewireData[$name.'_confirmation'] = '';
        $livewireData['wrla_show_'.$name] = false;
    }

    /**
     * Apply submitted value. May be overriden in special cases, such as when applying a hash to a password.
     */
    public function applySubmittedValue(Request $request, mixed $value): mixed
    {
        // On the edit page the password only changes when the user explicitly ticked the
        // "change password" checkbox — ignore any value otherwise so an abandoned entry never
        // overwrites the stored password.
        if (WRLAHelper::isEditPage() && !$request->boolean('wrla_show_'.$this->getAttribute('name'))) {
            return $this->getValue();
        }

        // A blank submission means the password was left unchanged — keep the existing value
        // rather than hashing an empty string.
        if ($value === null || $value === '') {
            return $this->getValue();
        }

        // Hash password by user data hashPassword method if available
        if(method_exists(WRLAHelper::getUserDataModelClass(), 'hashPassword')) {
            return WRLAHelper::getUserDataModelClass()::hashPassword($value);
        }
        
        return Hash::make($value);
    }

    /**
     * Render the input field.
     */
    public function render(): mixed
    {
        $name = $this->getAttribute('name');
        $confirmName = $name.'_confirmation';
        $confirmPlaceholder = 'Confirm '.strtolower((string) $this->getLabel());

        // Base input attributes with any auto-applied wire:model binding removed, and the stored
        // value (a password hash on edit) stripped so it is never exposed in the rendered HTML.
        // The password and confirmation inputs stay independent, bound via Alpine (x-model)
        // rather than sharing a single livewire binding.
        $baseAttributes = collect($this->htmlAttributes)
            ->reject(fn ($value, $key) => $key === 'value' || str_contains((string) $key, 'wire:model'))
            ->all();

        // Create page: password + confirmation, always visible.
        if (!WRLAHelper::isEditPage()) {
            $HTML = <<<'HTML'
                <div x-data="{ password: '', passwordConfirmation: '' }" class="w-full flex flex-col gap-2">
            HTML;

            $HTML .= view(WRLAHelper::getViewPath('components.forms.input-text'), [
                'label' => $this->getLabel(),
                'attributes' => new ComponentAttributeBag(array_merge($baseAttributes, [
                    'name' => $name,
                    'type' => 'password',
                    'autocomplete' => 'new-password',
                    'x-model' => 'password',
                ])),
            ])->render();

            $HTML .= view(WRLAHelper::getViewPath('components.forms.input-text'), [
                'attributes' => new ComponentAttributeBag(array_merge($baseAttributes, [
                    'name' => $confirmName,
                    'type' => 'password',
                    'autocomplete' => 'new-password',
                    'placeholder' => $confirmPlaceholder,
                    'x-model' => 'passwordConfirmation',
                ])),
            ])->render();

            return $HTML.'</div>';
        }

        // Edit page: hidden behind a "change password" checkbox. Alpine owns the field values so
        // unticking (or a successful save, via the wrla-upsert-saved event) reliably clears them,
        // and the inputs are disabled while hidden so they are excluded from the submitted form
        // data (leaving the stored password untouched).
        $HTML = <<<HTML
            <div
                x-data="{ userWantsToChange: false, password: '', passwordConfirmation: '' }"
                x-init="\$watch('userWantsToChange', value => {
                    if (value) {
                        \$nextTick(() => \$refs.passwordField?.focus());
                    } else {
                        password = '';
                        passwordConfirmation = '';
                    }
                })"
                x-on:wrla-upsert-saved.window="userWantsToChange = false"
                class="w-full"
            >
        HTML;

        $HTML .= view(WRLAHelper::getViewPath('components.forms.label'), [
            'label' => $this->getLabel(),
            'attributes' => new ComponentAttributeBag([
                'for' => $name,
                'class' => 'mb-2',
            ]),
        ])->render();

        $HTML .= '<div class="flex flex-col gap-2">';

        // Checkbox to reveal / enable the password fields.
        $HTML .= view(WRLAHelper::getViewPath('components.forms.input-checkbox'), [
            'label' => 'Change '.Str::title(str_replace('_', ' ', (string) $this->getLabel())),
            'name' => 'wrla_show_'.$name,
            'attributes' => new ComponentAttributeBag([
                'x-model' => 'userWantsToChange',
            ]),
        ])->render();

        // Password field (hidden + disabled until the checkbox is ticked).
        $HTML .= view(WRLAHelper::getViewPath('components.forms.input-text'), [
            'attributes' => new ComponentAttributeBag(array_merge($baseAttributes, [
                'name' => $name,
                'type' => 'password',
                'autocomplete' => 'new-password',
                'x-ref' => 'passwordField',
                'x-model' => 'password',
                'x-show' => 'userWantsToChange',
                'x-bind:disabled' => '! userWantsToChange',
            ])),
        ])->render();

        // Confirm password field (hidden + disabled until the checkbox is ticked).
        $HTML .= view(WRLAHelper::getViewPath('components.forms.input-text'), [
            'attributes' => new ComponentAttributeBag(array_merge($baseAttributes, [
                'name' => $confirmName,
                'type' => 'password',
                'autocomplete' => 'new-password',
                'placeholder' => $confirmPlaceholder,
                'x-model' => 'passwordConfirmation',
                'x-show' => 'userWantsToChange',
                'x-bind:disabled' => '! userWantsToChange',
            ])),
        ])->render();

        return $HTML.'</div></div>';
    }
}
