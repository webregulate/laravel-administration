<?php

namespace WebRegulate\LaravelAdministration\Traits;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use WebRegulate\LaravelAdministration\Enums\PageType;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Classes\BrowseFilter;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;

trait ManageableField
{
    /**
     * Attributes of the form component.
     */
    public $htmlAttributes;

    /**
     * Static livewire $fields array.
     */
    public static array $livewireFields = [];

    /**
     * Options
     */
    public array $options = [
        'containerClass' => null,
        'label' => 'wrla::from_field_name',
        'beginGroup' => false,
        'endGroup' => false,
        'active' => true, // If false, this field will not be displayed, submitted / validated
    ];

    /**
     * Validation rule
     */
    public string $validationRules = '';

    /**
     * Inline validation rules
     */
    public array $inlineValidationRules = [];

    /**
     * Filter key values
     */
    public static array $browseFilterValues = [];

    /**
     * Static field index
     */
    public static $fieldIndex = 0;

    /**
     * Show on pages
     */
    public array $showOnPages = [
        PageType::CREATE,
        PageType::EDIT,
        // UpsertType::BROWSE,
    ];

    /**
     * Manageable model instance
     */
    public ?ManageableModel $manageableModel = null;

    /**
     * Callable to apply submitted value. May be overriden in special cases, such as when applying a hash to a password.
     */
    private mixed $applySubmittedValueCallable = null;

    /**
     * FormComponent constructor.
     */
    public function __construct(?string $name, ?string $value, ?ManageableModel $manageableModel = null)
    {
        // Check if name has . (relationship) or -> (json seperator) in it we need to get the appropriate value
        if(WRLAHelper::isBrowseColumnRelationship($name ?? '')) {
            $value = $manageableModel->getInstanceRelationValue($name);
        }
        elseif(str_contains($name ?? '', '->')) {
            $value = $manageableModel->getInstanceJsonValue($name);
        }
        
        // Get parent class of this trait
        $parentClass = get_class($this);

        // Get default validation rules if exists
        $defaultValidationRules = config("wr-laravel-administration.default_validation_rules.$parentClass");
        if(!empty($defaultValidationRules)) {
            $this->validation($defaultValidationRules);
        }

        // Increment field index
        self::$fieldIndex++;

        // Get name
        $name = $this->buildNameAttribute($name ?? '');

        // Set base attributes
        $this->htmlAttributes = [
            'name' => $name,
            'value' => $value ?? '',
        ];

        // Set manageable model
        $this->manageableModel = $manageableModel;

        // Handle livewire setup (if applicable)
        $this->handleLivewireSetup();

        // Set manageable models attributes if applicable
        $this->setManageableModelValue();

        // Run post constructed method
        $this->postConstructed();
    }

    /**
     * Set active, must be the last method called in the chain as returns null if not active.
     */
    public function setActive(bool|callable $active): ?static
    {
        // If callable, call it with $this as parameter
        if(is_callable($active)) {
            $active = $active($this);
        }

        // Set active option
        $this->options['active'] = $active;

        return $this->options['active'] ? $this : null;
    }

    /**
     * Build name attribute. We need this because PHP converts dots to underscores in request input, so we need to
     * convert the . to a special reversable key.
     */
    private static function buildNameAttribute(string $name): string
    {
        // If empty, return unique name
        if(empty($name)) {
            return 'wrla_field_'.self::$fieldIndex;
        }

        return str_replace('.', WRLAHelper::WRLA_REL_DOT, $name);
    }

    /**
     * If manageable model is not null and manageable model has an attribute with this
     * field's name, set the manageable models attribute to the value
     */
    public function setManageableModelValue(): void
    {
        // If manageable model is null, return
        if($this->manageableModel === null) return;

        // Get name to test with against manageable model
        $name = $this->getName();

        // If attribute does not exist, get all column names from table and fill out empty values
        if(!property_exists($this->manageableModel->model(), $name)) {
            $this->manageableModel->fillEmptyInstanceAttributesWithDefaults();
        }

        // Get whether column exists on this model's table
        $columnExistsInSchema = WRLAHelper::modelTableHasColumn($this->manageableModel->model(), $name);

        // If attribute is neither a real column nor a set-mutator attribute, return
        if(!$columnExistsInSchema && !WRLAHelper::modelHasSetMutator($this->manageableModel->model(), $name)) return;

        // Set manageable model property value
        $this->manageableModel->model()->setAttribute($name, $this->getValue());
    }

    /**
     * Make method (can be used in any class that extends FormComponent).
     */
    public static function make(?ManageableModel $manageableModel = null, ?string $column = null, ?array $options = null): static
    {
        // If the column uses dot notation but the first segment is an array/json-cast attribute
        // on the model (rather than a defined relationship method), rewrite the dots to '->' so
        // it is treated as nested JSON access instead of being mistakenly handled as a relationship.
        // Example: 'additional.entry_success' on a model with $casts['additional'] = 'array'
        // becomes 'additional->entry_success'.
        if (
            $manageableModel !== null
            && $column !== null
            && str_contains($column, '.')
            && !str_contains($column, '->')
        ) {
            $modelInstance = $manageableModel->model();
            $firstSegment = explode('.', $column)[0];

            if (
                $modelInstance !== null
                && !method_exists($modelInstance, $firstSegment)
                && WRLAHelper::isJsonCastAttribute($modelInstance, $firstSegment)
            ) {
                $column = str_replace('.', '->', $column);
            }
        }

        // If the column uses nested JSON notation (e.g. 'additional->entry_success'), look up
        // the parent attribute on the model and dig into the (potentially cast) value with
        // dot notation. Eloquent's __get does not resolve '->' style keys for us.
        $valueIsNestedJson = $column !== null && $manageableModel?->model() !== null && str_contains($column, '->');

        if ($valueIsNestedJson) {
            [$rootColumn, $jsonNotation] = WRLAHelper::parseJsonNotation($column);

            $rootValue = $manageableModel->model()->{$rootColumn};

            if ($rootValue instanceof \Illuminate\Support\Collection) {
                $rootValue = $rootValue->all();
            } elseif (is_string($rootValue) && $rootValue !== '') {
                $decodedRoot = json_decode($rootValue, true);
                if (is_array($decodedRoot)) {
                    $rootValue = $decodedRoot;
                }
            }

            $value = data_get($rootValue, $jsonNotation);
        } else {
            $value = $manageableModel?->model()->{$column};
        }

        $valueIsArray = is_array($value);
        if($valueIsArray) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Preserve boolean values (eg. Eloquent 'boolean' cast) as '1'/'0' before they reach the
        // constructor's ?string $value param. Otherwise PHP coerces false to '' which is then
        // indistinguishable from an unset value, causing eg. Select to wrongly fall back to its first option.
        if(is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        $manageableField = new static($column, $value, $manageableModel);

        // If value was an array (eg. Eloquent array/json cast), decode the submitted JSON string back to an
        // array on submit so the cast handles serialisation correctly and avoids double-encoding.
        if($valueIsArray) {
            $manageableField->appendApplySubmittedValue(function(Request $request, mixed $value) {
                return is_string($value) ? json_decode($value, true) : $value;
            });
        }

        if(!is_null($options)) {
            $manageableField->setOptions($options);
        }

        return $manageableField;
    }

    /**
     * Get manageable field type (eg. the class name: Text, Select, etc.)
     */
    public function getType(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    /**
     * Whether this field handles file/image uploads. Overridden by file based
     * fields (eg. File, Image, MultiImage) so consumers can skip them where
     * a raw value cannot be transferred (eg. duplicating a record).
     */
    public function isFileUploadField(): bool
    {
        return false;
    }

    /**
     * Moddeled with livewire
     */
    public function isModeledWithLivewire(): bool
    {
        foreach($this->htmlAttributes as $key => $value) {
            if(str_contains((string) $key, 'wire:model')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Make browse.filter version of the form component.
     *
     * @param string $filterAlias Must be the same as the BrowseFilter key
     */
    public static function makeBrowseFilter(?string $filterAlias = null, ?string $filterLabel = null, ?string $filterIcon = null, string $containerClass = 'flex-1'): static
    {
        return static::make(null, $filterAlias)
            ->setOptions([
                'newRow' => false,
                'containerClass' => $containerClass,
                'labelClass' => 'font-thin mb-1',
            ])
            ->setLabel($filterLabel, !empty($filterIcon) ? "$filterIcon text-slate-400 mr-1" : null)
            ->setAttributes([
                'wire:model.live.debounce.300ms' => 'filters.'.$filterAlias,
            ]);
    }

    /**
     * Make browse.filter version of the form component.
     *
     * @param callable $callback Takes $query, $table, $columns, $value
     */
    public function browseFilterApply(callable $callback): BrowseFilter
    {
        return new BrowseFilter(
            $this,

            // Apply browse filter
            $callback
        );
    }

    /**
     * Get filter value.
     */
    public function getBrowseFilterValue(string $filterAlias): mixed
    {
        return ManageableField::$browseFilterValues[$filterAlias] ?? null;
    }

    /**
     * Set static filter value.
     */
    public static function setStaticBrowseFilterValue(string $filterAlias, mixed $value): void
    {
        // Set static filter value
        ManageableField::$browseFilterValues[$filterAlias] = $value;
    }

    /**
     * Set value.
     *
     * @param mixed $value
     */
    public function setValue(mixed $value): void
    {
        // Set value attribute
        $this->setAttribute('value', $value);

        // If livewire field, set livewire field value
        if($this->isModeledWithLivewire()) {
            ManageableModel::setLivewireField($this->getName(), $value);
        }
    }

    /**
     * Get value, if option ignoreOld is set then return the value attribute, otherwise return
     * the old value if it exists in the request.
     *
     * @return string
     */
    public function getValue(): string
    {
        // Get value
        $value = $this->htmlAttributes['value'];

        // If type is date, format value as Y-m-d
        if($this->getAttribute('type') === 'date' && !empty($value)) {
            $value = Carbon::parse($value)->format('Y-m-d');
        }

        // If ignoreOld option is set, return value attribute
        if($this->options['ignoreOld'] ?? false) {
            return $value;
        }

        // Otherwise return old value if exists, and fallback to value attribute
        return old($this->getName(), $value) ?? '';
    }

    /**
     * Get name attribute.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->htmlAttributes['name'];
    }

    /**
     * Post constructed method, called after name and value attributes are set.
     *
     * @return $this
     */
    public function postConstructed(): mixed
    {
        return $this;
    }

    /**
     * Should submit method, if false then the form will not submit it's value by setting the form="none" attribute.
     *
     * @param bool $shouldSubmit
     * @return $this
     */
    public function shouldSubmit(bool $shouldSubmit): static
    {
        if(!$shouldSubmit) {
            $this->setAttribute('form', 'none');
        } else {
            $this->removeAttribute('form');
        }

        return $this;
    }

    /**
     * Pre validation method, called before validation rules are set.
     *
     * @param ?string $value
     * @return bool Return true if we have changed the value and want to force merge into request input
     */
    public function preValidation(?string $value): bool
    {
        // Return false by default so no adjustments are made to the request input
        return false;
    }

    /**
     * Add inline validation rule, note each $callable must take 1 parameter of request input value
     * object, and must return true on success, and a string message on failure.
     * Inline validation is run within the manageable model in it's runInlineValidation method.
     * Note that inline validation is run after the standard validation rule set.
     *
     * @param callable ...$callback
     * @return $this
     */
    public function inlineValidation(callable ...$callbacks): static
    {
        $this->inlineValidationRules = array_merge($this->inlineValidationRules, $callbacks);

        return $this;
    }

    /**
     * Run inline validation on the form component.
     *
     * @param true|string $result
     */
    public function runInlineValidation($requestValue): true|string
    {
        foreach($this->inlineValidationRules as $callback) {
            $result = $callback($requestValue);

            if($result !== true) {
                return $result;
            }
        }

        return true;
    }

    /**
     * Add required attribute and validation
     *
     * @return $this
     */
    public function required(): static
    {
        $this->setAttribute('required', 'required');
        $this->validation('required');

        return $this;
    }

    /**
     * Begin group
     *
     * @return $this
     */
    public function beginGroup(): static
    {
        $this->options['beginGroup'] = true;
        return $this;
    }

    /**
     * End group
     *
     * @return $this
     */
    public function endGroup(): static
    {
        $this->options['endGroup'] = true;
        return $this;
    }

    /**
     * Set option.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;
        return $this;
    }

    /*
     * Get option.
     *
     * @param string $key
     * @return mixed
     */
    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Merge or set options.
     *
     * @param ?array $options
     * @return $this
     */
    public function setOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Get options.
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Boolean HTML attributes that should be removed entirely when set to a falsy value
     * rather than rendered with a literal "false"/"0" string in the output.
     */
    protected static array $booleanHtmlAttributes = [
        'disabled',
        'readonly',
        'required',
        'checked',
        'selected',
        'multiple',
        'autofocus',
        'hidden',
        'novalidate',
        'formnovalidate',
        'open',
        'reversed',
        'default',
        'autoplay',
        'controls',
        'loop',
        'muted',
        'playsinline',
    ];

    /**
     * Set attribute.
     */
    public function setAttribute(string $key, string|bool|null $value): static
    {
        if($key == 'name') {
            $value = $this->buildNameAttribute((string) $value);
        }

        // For boolean HTML attributes, a falsy value should remove the attribute entirely
        // instead of rendering it (e.g. disabled="" still disables the field).
        if(!$value && in_array(strtolower($key), self::$booleanHtmlAttributes, true)) {
            return $this->removeAttribute($key);
        }

        $this->htmlAttributes[$key] = $value;
        return $this;
    }

    /**
     * Get attribute.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->htmlAttributes[$key] ?? null;
    }

    /**
     * Remove attribute.
     */
    public function removeAttribute(string $key): static
    {
        unset($this->htmlAttributes[$key]);
        return $this;
    }

    /**
     * Set attributes.
     */
    public function setAttributes(array $attributes = []): static
    {
        foreach($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Get attributes.
     */
    public function getAttributes(): array
    {
        return $this->htmlAttributes;
    }

    /**
     * Livewire setup. Return a key => value array of livewire fields to register their default values.
     * Note that if returns array, this manageable field will automatically add the wire:model.live attribute to the input field.
     * If not using livewire fields for this manageable model, return null.
     *
     * @return ?array Key => value array of livewire fields to register their default values.
     */
    public function livewireSetup(): ?array
    {
        return null;
    }

    /**
     * Set livewire wire:model.live attribute with the livewireData. prefix for use in
     * the livewire component and other manageable fields.
     */
    public function setLivewireModel(string $type = 'live'): static
    {
        // If $type is not empty, prepend .
        $injectType = !empty($type) ? ".$type" : '';

        // Set wire:model attribute
        $this->setAttribute("wire:model{$injectType}", "livewireData.{$this->getAttribute('name')}");

        // If first render, set livewire field
        if(ManageableModel::$numberOfRenders === 0) {
            ManageableModel::setLivewireField($this->getName(), $this->getValue());
        }

        return $this;
    }

    /**
     * Handle livewire setup
     *
     * @return void
     */
    private function handleLivewireSetup(): void
    {
        // Get livewire setup result
        $livewireSetupResult = $this->livewireSetup();

        // If null then return
        if($livewireSetupResult === null) {
            return;
        }

        // Set livewire model attribute
        $this->setLivewireModel();

        // Set each static field default if not already set
        foreach($livewireSetupResult as $key => $value) {
            if(!ManageableModel::hasLivewireField($key)) {
                ManageableModel::setLivewireField($key, $value);
            }
        }
    }

    /**
     * Set default value.
     */
    public function default(mixed $value): static
    {
        // If model is being modified rather than created, then skip setting default value
        if($this->manageableModel?->isBeingCreated() === false) {
            // Use the field's already resolved current value rather than re-reading it from the model
            // via the magic getter. The value attribute was correctly resolved during construction,
            // including relationship ('.') and nested JSON ('->') notation. Reading directly with
            // $this->model()->{$this->getName()} fails for those columns because Eloquent's magic
            // getter cannot resolve '->' / WRLA_REL_DOT notation, returning null and incorrectly
            // overriding the stored value with the default.
            $currentValue = $this->getAttribute('value');

            // We setValue here to also handle the livewire field value. Only fall back to the default
            // when no value currently exists.
            $this->setValue(($currentValue === null || $currentValue === '') ? $value : $currentValue);

            return $this;
        }

        // If the model is being prefilled from another record (duplicate), keep the
        // already populated value rather than overriding it with the default. Only
        // fall back to the default when no duplicated value exists for this field.
        if($this->manageableModel?->isDuplicating === true) {
            $currentValue = $this->getAttribute('value');

            $this->setValue(($currentValue === null || $currentValue === '') ? $value : $currentValue);

            return $this;
        }

        // Set value
        $this->setValue($value);

        // Set manageable model value
        $this->setManageableModelValue();

        return $this;
    }

    /**
     * If value is null, then set column value as empty string.
     */
    public function ifNullThenEmptyString(bool $boolean): static
    {
        $this->overrideApplySubmittedValue(function(Request $request, mixed $value) use ($boolean) {
            if($boolean && $value === null) {
                return '';
            }

            return $value;
        });

        return $this;
    }

    /**
     * Set label
     */
    public function setLabel(?string $label, ?string $icon = null): static
    {
        // If icon is set then prepend it to the label
        if($icon !== null) {
            $label = "<i class='$icon mr-0.5'></i> $label";
        }

        $this->options['label'] = $label;

        return $this;
    }

    /**
     * Get label.
     */
    public function getLabel(): ?string
    {
        // Get label option
        $label = $this->getOption('label');

        // If wrla::from_field_name then get the name and convert to label
        if($label === 'wrla::from_field_name') {
            // If name is based on a relation on json column (eg has a . or -> in it) we get the last part of the name
            return static::getLabelFromFieldName($this->htmlAttributes['name']);
        }
        // If null then return null
        elseif($label === null) {
            return null;
        }

        return $label;
    }

    /**
     * Get label from field name
     */
    public static function getLabelFromFieldName(string $fieldName): string
    {
        // If if ends with _id, remove it
        if(str($fieldName)->endsWith('_id')) {
            $fieldName = str($fieldName)->beforeLast('_id');
        }

        // If name is based on a relation on json column (eg has a . or -> in it) we get the last part of the name
        $label = str_replace(WRLAHelper::WRLA_REL_DOT, '.', $fieldName);
        $label = str($label)->afterLast('.')->afterLast('->');
        $label = $label->replace('_', ' ')->trim(' ')->ucfirst();
        return $label;
    }

    /**
     * Notes
     */
    public function notes(string $notes): static
    {
        $this->options['notes'] = $notes;

        return $this;
    }

    /**
     * Append or overwrite validation rules
     * TODO: Add allowance of array validations
     *
     * @param string $validationRules Validation rules
     * @param bool $overwrite Overwrite existing validation rules
     */
    public function validation(string $validationRules, $overwrite = false): static
    {
        if($overwrite || empty($this->validationRules)) {
            $this->validationRules = $validationRules;
        } else {
            $this->validationRules .= '|' . $validationRules;
        }

        return $this;
    }

    /**
     * Apply submitted value. May be overriden in special cases, such as when applying a hash to a password.
     */
    public function applySubmittedValue(Request $request, mixed $value): mixed
    {
        return $value;
    }

    /**
     * Replaces default applySubmittedValue method.
     * 
     * @param callable $callable Takes Request $request, mixed $value, and returns the value to be set.
     */
    public function overrideApplySubmittedValue(callable $callable): mixed
    {
        $this->applySubmittedValueCallable = $callable;
        return $this;
    }

    /**
     * Append apply submitted value
     * 
     * @param callable $callable Takes Request $request, mixed $value, and returns the value to be set. The $value parameter is the value returned from the default applySubmittedValue method or the previous appended applySubmittedValueCallable.
     */
    public function appendApplySubmittedValue(callable $callable): static
    {
        $previousCallable = $this->applySubmittedValueCallable;

        $this->applySubmittedValueCallable = function (Request $request, mixed $value) use ($previousCallable, $callable) {
            if ($previousCallable !== null) {
                $value = call_user_func($previousCallable, $request, $value);
            }
            return call_user_func($callable, $request, $value);
        };

        return $this;
    }

    /**
     * Apply submitted value final.
     */
    public function applySubmittedValueFinal(Request $request, mixed $value): mixed
    {
        // If callable is set then run it
        if($this->applySubmittedValueCallable !== null) {
            return call_user_func($this->applySubmittedValueCallable, $request, $value);
        }

        // Otherwise run the default applySubmittedValue method
        return $this->applySubmittedValue($request, $value);
    }

    /**
     * Hide from pages.
     *
     * @param PageType ...$pageTypes
     */
    public function hideFrom(...$pageTypes): static
    {
        $this->showOnPages = array_filter($this->showOnPages, fn($pageType) => !in_array($pageType, $pageTypes));

        return $this;
    }

    /**
     * Show on pages.
     *
     * @param PageType ...$pageTypes
     */
    public function showOn(...$pageTypes): static
    {
        $this->showOnPages = array_merge($this->showOnPages, $pageTypes);

        return $this;
    }

    /**
     * Show only on pages.
     *
     * @param PageType ...$pageTypes
     */
    public function showOnlyOn(...$pageTypes): static
    {
        $this->showOnPages = $pageTypes;

        return $this;
    }

    /**
     * BEING PHASED OUT, REPLACED BY ->when(condition, callback)
     * 
     * If condition is true, run callback.
     * @param callable $callback (Must take $this as a parameter and return $this)
     */
    public function onCondition(bool $isTrue, callable $callback): static
    {
        if(!$isTrue) {
            return $this;
        }

        return $callback($this);
    }

    /**
     * If $condition is true, the callback will be executed with the manageableField as the argument
     * Example: $manageableField->when($condition, function ($manageableField) { ... })
     * 
     * @param bool|callable $condition If callable, will pass the manageableField as the argument and should return a boolean
     * @param callable $callback Takes the manageableField reference as an argument so it can be modified without returning
     */
    public function when(bool|callable $condition, callable $callback): static
    {
        $manageableField = $this;

        if (is_callable($condition)) {
            $condition = $condition($manageableField);
        }

        if ($condition) {
            $callback($manageableField);
        }

        return $this;
    }

    /**
     * Is relationship field.
     */
    public function isRelationshipField(): bool
    {
        if(str($this->htmlAttributes['name'])->contains('->')) {
            return false;
        }

        if(str($this->htmlAttributes['name'])->contains('.') || str($this->htmlAttributes['name'])->contains(WRLAHelper::WRLA_REL_DOT)) {
            return true;
        }

        return false;
    }


    /**
     * Is using nested json.
     */
    public function isUsingNestedJson(): bool
    {
        if(str($this->htmlAttributes['name'])->contains('->')) {
            return true;
        }

        return false;
    }

    /**
     * Get relationship name.
     */
    public function getRelationshipName(): string
    {
        $relationshipName = str($this->htmlAttributes['name'])->before(WRLAHelper::WRLA_REL_DOT);
        return $relationshipName;
    }

    /**
     * Get relationship field name.
     */
    public function getRelationshipFieldName(): string
    {
        $fieldName = str($this->htmlAttributes['name'])->after(WRLAHelper::WRLA_REL_DOT);

        // If contains ->, get the first part
        if($fieldName->contains('->')) {
            $fieldName = $fieldName->before('->');
        }

        return $fieldName;
    }

    /**
     * Get relationship instance.
     */
    public function getRelationshipInstance(): mixed
    {
        return once(function() {
            // Get model instance, field name and relationship parts.
            $modelInstance = $this->manageableModel->model();
            $fieldName = str($this->htmlAttributes['name'])->replace(WRLAHelper::WRLA_REL_DOT, '.');
            $relationshipParts = WRLAHelper::parseBrowseColumnRelationship($fieldName);


            if($modelInstance == null) {
                throw new \RuntimeException('Model instance no longer exists for field "'.$fieldName.'" (relationship: '.implode('.', $relationshipParts).')');
            }

            // Check relation exists on model instance
            if(method_exists($modelInstance, $relationshipParts[0])) {
                // Reload and get relationship instance
                $modelInstance->load($relationshipParts[0]);
                $relationshipInstance = $modelInstance->{$relationshipParts[0]};
            }

            // Check whether dynamic relationship exists
            if(!isset($relationshipInstance) || $relationshipInstance === null) {
                // If relationshipParts has more than 1 part, we need to get the nested relationship
                if(count($relationshipParts) > 1) {
                    $potentialRelationship = $modelInstance->{$relationshipParts[0]};

                    // First check if relationship is already resolved (this can also happen if relationship is set using dynamic resolveRelationUsing)
                    if($potentialRelationship instanceof Model) {
                        $relationshipInstance = $potentialRelationship;
                    } else {
                        $relationshipInstance = $potentialRelationship()->first();
                    }
                }
            }

            // If relationship instance is not null, return it
            if($relationshipInstance != null) return $relationshipInstance;

            // Get model class from relationship and return new instance
            $relationship = $modelInstance->{$relationshipParts[0]}();
            $relationshipClass = $relationship->getRelated()::class;
            return new $relationshipClass;
        });
    }

    /**
     * Return view component.
     */
    public function renderParent(PageType $upsertType, array $fields): mixed
    {
        if(!in_array($upsertType, $this->showOnPages))
        {
            return '';
        }

        // Set static fields
        ManageableModel::setLivewireFields($fields);

        $HTML = $this->getOption('beginGroup') == true ? '<div class="w-full flex flex-col md:flex-row items-center gap-6">' : '';
        $HTML .= $this->render();
        $HTML .= $this->getOption('endGroup') == true ? '</div>' : '';

        if(empty($HTML))
        {
            return <<<HTML_WRAP
                <br />
                ---------------------<br />
                Override form component HTML in FormComponent render() method<br />
                ---------------------<br />
                <br />
            HTML_WRAP;
        }
        else
        {
            return <<<HTML
                $HTML
            HTML;
        }
    }

    /**
     * Normalise this field's value ready for livewire seeding, applying any
     * adjustments a field would otherwise make during render(). Called from the
     * upsert component's first-render seeding loop instead of render() so the
     * value can be prepared WITHOUT rendering the field's view. This matters for
     * fields that embed a nested livewire component (e.g. SearchSelect): rendering
     * such a field during the parent page component's render() would mount the
     * child component first and corrupt Livewire's full-page layout detection.
     *
     * The default falls back to render() to preserve the previous behaviour for
     * fields that rely on render()-time value adjustments and do not embed a
     * nested livewire component. Fields that DO embed one must override this to
     * perform only the value adjustment.
     */
    public function prepareLivewireValue(): void
    {
        $this->render();
    }

    /**
     * Render the input field.
     */
    public function render(): mixed
    {
        return null;
    }
}
