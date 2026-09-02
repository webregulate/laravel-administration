<?php

namespace WebRegulate\LaravelAdministration\Livewire\ManageableModels;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportRedirects\HandlesRedirects;
use Livewire\WithFileUploads;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;
use WebRegulate\LaravelAdministration\Enums\PageType;
use WebRegulate\LaravelAdministration\Livewire\WRLAPageComponent;

/**
 * Class ManageableModelUpsert
 *
 * This class represents a Livewire component for upserting a manageable model.
 */
class ManageableModelUpsert extends WRLAPageComponent
{
    /* Traits
    --------------------------------------------------------------------------*/
    use HandlesRedirects, WithFileUploads;

    /* Properties
    --------------------------------------------------------------------------*/

    /**
     * The class name of the manageable model.
     */
    public string $manageableModelClass;

    /**
     * Livewire fields, attach with manageable model ->setAttribute('wire:model.live', 'livewireData.key')
     */
    public array $livewireData = [];

    /**
     * Number of renders counter
     */
    public int $numberOfRenders = 0;

    /**
     * Refresh manageable field values
     */
    public bool $refreshManageableFields = false;

    /**
     * Upsert type
     */
    public PageType $upsertType;

    /**
     * Model id, null if creating a new model.
     */
    public ?int $modelId = null;

    /**
     * Id of an existing model to prefill this create form from (duplicate),
     * null when not duplicating. File/image fields are intentionally skipped.
     */
    public ?int $duplicateFromId = null;

    /**
     * Override title
     */
    public ?string $overrideTitle = null;

    /**
     * Optional route name to redirect to after a successful save instead of the
     * default edit page. Captured from the `wrla_override_redirect_route` query
     * parameter on the create/edit page.
     */
    public ?string $overrideRedirectRoute = null;

    /**
     * Optional success message used with the override redirect route. Captured
     * from the `wrla_override_success_message` query parameter.
     */
    public ?string $overrideSuccessMessage = null;

    /**
     * Success message shown inline (above the form buttons) after a successful
     * save. Kept as a livewire property so the component stays on the page instead
     * of performing a full-page redirect/refresh.
     */
    public ?string $successMessage = null;

    /* Livewire Methods / Hooks
    --------------------------------------------------------------------------*/

    public $listeners = [
        'wrla_upsert_refresh' => '$refresh',
        'deleteModel' => 'deleteModel',
    ];

    /**
     * Mount the component from a route.
     *
     * @param  string  $modelUrlAlias  The URL alias of the manageable model.
     * @param  ?int  $id  The id of the model to edit, null when creating.
     * @return \Illuminate\Http\RedirectResponse|null
     */
    public function mount(string $modelUrlAlias, ?int $id = null)
    {
        // Resolve the manageable model class from its URL alias.
        $manageableModelClass = ManageableModel::getByUrlAlias($modelUrlAlias);

        // If the manageable model reference is null, redirect to the dashboard
        if (is_null($manageableModelClass)) {
            return redirect()->route('wrla.dashboard')->with('error', "Manageable model with url alias `$modelUrlAlias` not found.");
        }

        return $this->initialise($manageableModelClass, $id === null ? PageType::CREATE : PageType::EDIT, $id);
    }

    /**
     * Initialise the component state. Shared by the route mount and subclasses
     * (e.g. the manage account page) that resolve the manageable model differently.
     *
     * @param  string  $manageableModelClass  The class name of the manageable model.
     * @param  PageType  $upsertType  The type of upsert page.
     * @param  ?int  $modelId  The id of the model to upsert, null if creating a new model.
     * @param  ?string  $overrideTitle  Optional title override.
     * @return \Illuminate\Http\RedirectResponse|null
     */
    protected function initialise(string $manageableModelClass, PageType $upsertType, ?int $modelId = null, ?string $overrideTitle = null)
    {
        // Get the manageable model and base model class
        $this->manageableModelClass = $manageableModelClass;
        $manageableModelInstance = $this->manageableModelClass::make($this->modelId, true);
        $modelClass = $manageableModelInstance::getBaseModelClass();

        // If the model class does not exist, redirect to the dashboard
        if (!class_exists($modelClass)) {
            return redirect()->route('wrla.dashboard')->with('error', "Model `$modelClass` not found while loading manageable model `$manageableModelClass`.");
        }

        // Set other properties
        $this->modelId = $modelId;
        $this->upsertType = $upsertType;
        $this->overrideTitle = $overrideTitle;

        // If creating, capture an optional source record to duplicate from (passed
        // as a query parameter by the duplicate instance action). Stored as a public
        // property so it persists across subsequent livewire renders.
        if ($this->upsertType === PageType::CREATE) {
            $duplicateFrom = request()->query('wrlaDuplicateFrom');

            if (is_numeric($duplicateFrom)) {
                $this->duplicateFromId = (int) $duplicateFrom;
            }
        }

        // Capture optional post-save redirect overrides (previously read from the
        // POST request in the controller). Stored as public props so they persist
        // across livewire renders and are available in save().
        $overrideRedirectRoute = request()->query('wrla_override_redirect_route');
        if (is_string($overrideRedirectRoute) && $overrideRedirectRoute !== '') {
            $this->overrideRedirectRoute = $overrideRedirectRoute;

            $overrideSuccessMessage = request()->query('wrla_override_success_message');
            if (is_string($overrideSuccessMessage) && $overrideSuccessMessage !== '') {
                $this->overrideSuccessMessage = $overrideSuccessMessage;
            }
        }

        // Set page type
        WRLAHelper::setCurrentPageType($this->upsertType);
        WRLAHelper::setCurrentActiveManageableModelClass($this->manageableModelClass);
        WRLAHelper::setCurrentActiveManageableModelInstance($manageableModelInstance);

        // If the user does not have permission to edit the manageable model, redirect to the dashboard
        if(!$this->manageableModelClass::getPermission(ManageableModelPermissions::ENABLED) || !$this->manageableModelClass::getPermission($this->upsertType)) {
            $formattedUpsertType = str($this->upsertType->value)->lower()->toString();
            return redirect()->route('wrla.dashboard')->with('error', "You do not have permission to {$formattedUpsertType} this manageable model.");
        }
    }

    /**
     * Set field value (Livewire method)
     *
     * @param  string  $field  Field name
     * @param  mixed  $value  Field value
     */
    public function setFieldValue(string $field, mixed $value)
    {
        $this->livewireData[$field] = $value;
        $this->refreshManageableFields = true;
    }

    /**
     * Set field values (Livewire method)
     *
     * @param  array  $fieldKeyValues  Field key values
     */
    public function setFieldValues(array $fieldKeyValues)
    {
        foreach ($fieldKeyValues as $field => $value) {
            $this->livewireData[$field] = $value;
        }
        $this->refreshManageableFields = true;
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\Contracts\View\View|string
     */
    public function render()
    {
        try {
            // Set manageable model number of renders
            ManageableModel::$numberOfRenders = $this->numberOfRenders;

            // Get manageable model and fields data
            $manageableModel = $this->manageableModelClass::make($this->modelId, true);
            ManageableModel::$livewireFields = $this->livewireData;

            // If duplicating, prefill the new model with the source record's values
            // (file/image fields are skipped) before the fields are built.
            $this->applyDuplicateValues($manageableModel);

            $manageableFields = $manageableModel->getManageableFieldsFinal();

            // Auto-bind every eligible field to livewire (wire:model) so the whole
            // form submits through the save() action. Fields that already declare
            // their own wire:model binding are left untouched.
            $this->applyLivewireModels($manageableFields);

            // Set page type
            WRLAHelper::setCurrentPageType($this->upsertType);
            WRLAHelper::setCurrentActiveManageableModelClass($this->manageableModelClass);
            WRLAHelper::setCurrentActiveManageableModelInstance($manageableModel);

            // If first render, seed the livewire field values from the model.
            if ($this->numberOfRenders === 0) {
                foreach ($manageableFields as $manageableField) {
                    // File uploads are handled as native livewire uploads; never seed
                    // a value for them (their bound property holds an UploadedFile).
                    if ($manageableField->isModeledWithLivewire() && !$manageableField->isFileUploadField()) {
                        // Prepare (normalise) the value without rendering the field. Rendering
                        // here would mount any nested livewire component (e.g. SearchSelect)
                        // before this page component's own render, which corrupts Livewire's
                        // full-page layout detection and throws a MissingLayoutException.
                        $manageableField->prepareLivewireValue();
                        $this->livewireData[$manageableField->getAttribute('name')] = $manageableField->getLivewireValue();
                    }
                }

                ManageableModel::$livewireFields = $this->livewireData;
                $manageableFields = $manageableModel->getManageableFieldsFinal();
                $this->applyLivewireModels($manageableFields);
            }

            // If force refresh manageable fields, set field values
            if ($this->refreshManageableFields) {
                foreach ($manageableFields as $manageableField) {
                    if ($manageableField->isModeledWithLivewire() && !$manageableField->isFileUploadField()) {
                        $manageableField->setAttribute('value', $this->livewireData[$manageableField->getAttribute('name')] ?? null);
                    }
                }
            }

            // Increment number of renders
            $this->numberOfRenders++;

            // Render the view
            return view(WRLAHelper::getViewPath('livewire.manageable-models.upsert'), [
                'manageableModel' => $manageableModel,
                'upsertType' => $this->upsertType,
                'usesWysiwyg' => $manageableModel->usesWysiwyg(),
                'manageableFields' => $manageableFields,
                'numberOfRenders' => $this->numberOfRenders,
                'overrideTitle' => $this->overrideTitle,
            ]);
        } catch (\Exception $e) {
            // If an error occurs, redirect to the dashboard with an error message
            redirect()->route('wrla.dashboard')->with('error', "Error loading manageable model `$this->manageableModelClass`: ".$e->getMessage());

            return '<div></div>';
        }
    }

    /**
     * Page title shown in the WRLA admin layout.
     */
    protected function getPageTitle(): ?string
    {
        return $this->overrideTitle
            ?? str($this->upsertType->value)->lower()->title()->toString().' '.$this->manageableModelClass::getDisplayName();
    }

    /**
     * Auto-bind eligible manageable fields to livewire via wire:model so the whole
     * form is submitted through {@see save()}. Fields that already declare their
     * own wire:model binding (eg. searchable / select fields with custom
     * .live.debounce bindings) and fields excluded from submission via
     * shouldSubmit(false) are left untouched.
     *
     * @param  array  $manageableFields  The manageable fields to bind.
     */
    protected function applyLivewireModels(array $manageableFields): void
    {
        foreach ($manageableFields as $manageableField) {
            // Preserve fields that already declare their own wire:model binding.
            if ($manageableField->isModeledWithLivewire()) {
                continue;
            }

            // Respect shouldSubmit(false) — such fields must not sync or submit.
            if ($manageableField->getAttribute('form') === 'none') {
                continue;
            }

            $fieldName = $manageableField->getAttribute('name');

            if ($manageableField->isFileUploadField()) {
                // Only the plain File field (non wire:ignore blade) binds via wire:model
                // for native livewire uploads. Image / croppable / multi-image fields
                // manage their own uploads via their blade JS (or a nested component),
                // so they must not receive a wire:model binding here.
                if ($manageableField->getType() === 'File') {
                    $manageableField->setAttribute('wire:model.live', 'livewireData.'.$fieldName);
                }
            } else {
                // Deferred binding: values sync to the component when save() runs.
                $manageableField->setLivewireModel('');
            }
        }
    }

    /**
     * Save the upserted model (create or edit). Replaces the previous controller
     * based POST submit: rebuilds a request from the livewire form state and runs
     * it through the existing validation + persistence pipeline.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    public function save()
    {
        $manageableModelClass = $this->manageableModelClass;

        // Clear any previous inline success message
        $this->successMessage = null;

        // Set page type and manageable model class
        WRLAHelper::setCurrentPageType($this->upsertType);
        WRLAHelper::setCurrentActiveManageableModelClass($manageableModelClass);

        // Resolve the model instance being created or edited
        if ($this->modelId === null) {
            $manageableModel = $manageableModelClass::make();
        } else {
            $manageableModel = $manageableModelClass::make($this->modelId, true);

            if ($manageableModel === null) {
                $this->addError('error', 'Model '.$manageableModelClass." with ID `{$this->modelId}` not found.");
                return null;
            }
        }

        // Catch if configured to do so in config -> catch_errors.upsert
        return WRLAHelper::catchIfConfiguredTo('upsert', function () use (&$manageableModel) {
            // Set currently active manageable model instance
            WRLAHelper::setCurrentActiveManageableModelInstance($manageableModel);

            // Get manageable fields and build a request from the livewire form state
            // so the existing upsert pipeline can run unchanged.
            $manageableFields = $manageableModel->getManageableFieldsFinal();
            $request = $this->buildRequestFromLivewireData($manageableFields);

            // Run pre validation hook on all manageable fields and merge into request
            $requestMerge = [];
            foreach ($manageableFields as $manageableField) {
                $forceMergeIntoRequest = $manageableField->preValidation($request->input($manageableField->getAttribute('name')));

                if ($forceMergeIntoRequest) {
                    $requestMerge[$manageableField->getAttribute('name')] = $manageableField->getAttribute('value');
                }
            }

            $request->merge($requestMerge);

            // Validate against the model's rules and any inline validation
            $rules = $manageableModel->getValidationRules()->toArray();
            $validator = Validator::make($request->all(), $rules);
            $inlineValidationResult = $manageableModel->runInlineValidation($request);

            // If either validator or inline validation fails, show the errors
            if ($validator->fails() || $inlineValidationResult !== true) {
                $validationErrors = $validator->errors();

                if ($inlineValidationResult !== true) {
                    foreach ($inlineValidationResult as $key => $value) {
                        $validationErrors->add($key, $value);
                    }
                }

                $this->setErrorBag($validationErrors);
                return null;
            }

            // Update only changed values on the model instance
            $result = $manageableModel->updateModelInstanceProperties($request, $manageableFields, $request->all());

            // If the result is not true, show the errors
            if ($result !== true) {
                $this->setErrorBag($result);
                return null;
            }

            // Log event
            $created = $this->modelId === null;
            WRLAHelper::logEvent(($created ? 'Created' : 'Updated')." `{$manageableModel->getUrlAlias()}` ".($created ? '' : "with ID `{$manageableModel->model()->id}`"), [
                'model_class' => $manageableModel::getBaseModelClass(),
                'instance_id' => $manageableModel->model()->id,
                'changes' => $created
                    ? $manageableModel->model()->getAttributes()
                    : WRLAHelper::getModelChangeLogInfo($manageableModel->model()),
            ]);

            // Save the model
            $manageableModel->model()->save();

            // Perform any necessary actions after updating the model instance
            $manageableModel->postUpdateModelInstance($request, $manageableModel->model());

            // Default success message
            $savedId = $manageableModel->model()->id;
            $defaultSuccessMessage = 'Saved '.$manageableModel->getDisplayName().' #'.$savedId.' successfully.';
            $defaultSuccessMessage .= $created
                ? ' <a href="'.route('wrla.manageable-models.create', ['modelUrlAlias' => $manageableModel->getUrlAlias()]).'" class="font-bold underline">Click here</a> to create another '.$manageableModel->getDisplayName(false).' record.'
                : '';

            // If an override redirect route was provided, redirect there instead
            if ($this->overrideRedirectRoute !== null) {
                $message = $this->overrideSuccessMessage ?? $defaultSuccessMessage;

                return redirect()->route($this->overrideRedirectRoute)->with('success', $message);
            }

            // Stay on the page (no full-page refresh) and surface the result inline.
            // When a new record was just created, transition the component into edit
            // mode for that record so subsequent saves update it rather than creating
            // duplicate records.
            if ($created) {
                $this->modelId = $savedId;
                $this->upsertType = PageType::EDIT;
                WRLAHelper::setCurrentPageType($this->upsertType);
            }

            // Reset any write-only fields (e.g. passwords) server-side so stale values are not
            // resubmitted on a subsequent save, and notify the client so their inputs clear too.
            foreach ($manageableFields as $manageableField) {
                $manageableField->resetLivewireAfterSave($this->livewireData);
            }
            $this->dispatch('wrla-upsert-saved');

            $this->successMessage = $defaultSuccessMessage;

            return null;

        // Catch
        }, function (Throwable $e) {
            $this->addError('error', $e->getMessage());
        });
    }

    /**
     * Rebuild an HTTP request from the current livewire form state so the existing
     * request-based upsert pipeline (validation + updateModelInstanceProperties)
     * can run unchanged.
     *
     * Livewire's TemporaryUploadedFile extends Illuminate\Http\UploadedFile, so
     * uploaded files are placed straight into the request's file bag. File fields
     * without a fresh upload are intentionally omitted so their stored value is
     * retained. Special control keys (image remove / rotation flags) are carried
     * over as normal input.
     *
     * @param  array  $manageableFields  The manageable fields for the model.
     */
    protected function buildRequestFromLivewireData(array $manageableFields): Request
    {
        $inputs = [];
        $files = [];

        // Field names that represent file uploads. Their bound value is an
        // UploadedFile when a fresh file was selected; otherwise the key must be
        // omitted so the existing stored value is retained by the pipeline.
        $fileFieldNames = [];
        foreach ($manageableFields as $manageableField) {
            if ($manageableField->isFileUploadField()) {
                $fileFieldNames[$manageableField->getAttribute('name')] = true;
            }
        }

        foreach ($this->livewireData as $key => $value) {
            // Uploaded file(s) go straight into the request's file bag.
            if ($value instanceof UploadedFile) {
                $files[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $uploaded = array_values(array_filter($value, fn ($v) => $v instanceof UploadedFile));

                if (!empty($uploaded)) {
                    $files[$key] = $uploaded;
                    continue;
                }
            }

            // A file field without a fresh upload is omitted so its stored value is retained.
            if (isset($fileFieldNames[$key])) {
                continue;
            }

            // Everything else (standard field values plus control keys the pipeline
            // understands, eg. image remove/rotation flags and multi-image state) is
            // carried over as normal request input.
            $inputs[$key] = $value;
        }

        return Request::create('', 'POST', $inputs, [], $files);
    }

    /**
     * Prefill the (create) model instance with values from the record being
     * duplicated. File/image fields are skipped because their raw stored values
     * cannot be transferred to a brand new record. Applied idempotently so the
     * prefilled values survive subsequent livewire renders.
     *
     * @param  ManageableModel  $manageableModel  The new model instance being created.
     */
    protected function applyDuplicateValues(ManageableModel $manageableModel): void
    {
        // Only relevant when creating a new record from an existing source record
        if ($this->upsertType !== PageType::CREATE || $this->duplicateFromId === null) {
            return;
        }

        // Flag the model as duplicating so manageable field ->default() calls do not
        // override the prefilled (duplicated) values.
        $manageableModel->isDuplicating = true;

        // Resolve the source manageable model (may be soft deleted)
        try {
            $sourceManageableModel = $this->manageableModelClass::make($this->duplicateFromId, true);
        } catch (\Exception $e) {
            return;
        }

        $sourceModel = $sourceManageableModel->model();

        if ($sourceModel === null) {
            return;
        }

        $sourceAttributes = $sourceModel->getAttributes();

        // Build the set of root columns to copy: every non file/image field's column.
        $columnsToCopy = [];
        foreach ($sourceManageableModel->getManageableFieldsFinal() as $manageableField) {
            if ($manageableField->isFileUploadField()) {
                continue;
            }

            // Resolve the underlying database column, stripping json '->' and relationship dot notation.
            $fieldName = str_replace(WRLAHelper::WRLA_REL_DOT, '.', $manageableField->getName());
            $rootColumn = explode('.', explode('->', $fieldName)[0])[0];

            // Only copy real, loaded columns on the source record.
            if (array_key_exists($rootColumn, $sourceAttributes)) {
                $columnsToCopy[$rootColumn] = true;
            }
        }

        // Columns that should never be carried over to a brand new record.
        $targetModel = $manageableModel->model();
        $protectedColumns = [$targetModel->getKeyName()];

        if ($targetModel->usesTimestamps()) {
            $protectedColumns[] = $targetModel->getCreatedAtColumn();
            $protectedColumns[] = $targetModel->getUpdatedAtColumn();
        }

        if (method_exists($targetModel, 'getDeletedAtColumn')) {
            $protectedColumns[] = $targetModel->getDeletedAtColumn();
        }

        // Copy each eligible value using the casted value so json/array casts are preserved.
        foreach (array_keys($columnsToCopy) as $column) {
            if (in_array($column, $protectedColumns, true)) {
                continue;
            }

            $targetModel->setAttribute($column, $sourceModel->getAttribute($column));
        }
    }

    /**
     * Delete a model.
     *
     * @param  string  $modelUrlAlias  The URL alias of the model to delete.
     * @param  int  $id  The ID of the model to delete.
     */
    public function deleteModel(string $modelUrlAlias, int $id)
    {
        // Get manageable model instance
        $manageableModel = new $this->{'manageableModelClass'}($id);

        // Check that model URL alias matches the manageable model class URL alias
        if ($modelUrlAlias != $this->manageableModelClass::getUrlAlias()) {
            $this->addError('error', 'Model URL alias does not match manageable model class URL alias.');
            return;
        }

        // Delete the model and deconstruct the response
        [$success, $message] = WRLAHelper::deleteModel($manageableModel, $id);

        // If model failed to delete, add an error
        if (! $success) {
            $this->addError('error', $message);
            return;
        }

        // Otherwise the model was deleted successfully
        session()->flash('success', $message);

        // If the user is currently on the edit page, take them back to the browse page for
        // the manageable model as the instance they were editing no longer exists.
        if (WRLAHelper::isEditPage()) {
            return redirect($this->manageableModelClass::urlBrowse());
        }
    }

    /* Methods
    --------------------------------------------------------------------------*/

    /**
     * Get manageable model instance
     */
    public function model(): ManageableModel
    {
        return new $this->manageableModelClass;
    }

    /**
     * Call manageable model action.
     */
    public function callManageableModelAction(int $instanceId, string $actionKey, array $parameters = []) {
        $result = WRLAHelper::callManageableModelAction($this, $this->manageableModelClass, $instanceId, $actionKey, $parameters);
        if (!($result instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) && !($result instanceof \Symfony\Component\HttpFoundation\StreamedResponse)) {
            $this->dispatch('instanceActionCompleted');
        }
        return $result;
    }
}
