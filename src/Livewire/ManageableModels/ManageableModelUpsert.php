<?php

namespace WebRegulate\LaravelAdministration\Livewire\ManageableModels;

use Livewire\Component;
use Livewire\Features\SupportRedirects\HandlesRedirects;
use Livewire\WithFileUploads;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;
use WebRegulate\LaravelAdministration\Enums\ManageableModelPermissions;
use WebRegulate\LaravelAdministration\Enums\PageType;

/**
 * Class ManageableModelUpsert
 *
 * This class represents a Livewire component for upserting a manageable model.
 */
class ManageableModelUpsert extends Component
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

    /* Livewire Methods / Hooks
    --------------------------------------------------------------------------*/

    public $listeners = [
        'wrla_upsert_refresh' => '$refresh',
        'deleteModel' => 'deleteModel',
    ];

    /**
     * Mount the component.
     *
     * @param  string  $manageableModelClass  The class name of the manageable model.
     * @param  PageType  $upsertType  The type of upsert page.
     * @param  ?int  $modelId  The id of the model to upsert, null if creating a new model.
     * @return \Illuminate\Http\RedirectResponse|null
     */
    public function mount(string $manageableModelClass, PageType $upsertType, ?int $modelId = null, ?string $overrideTitle = null)
    {
        // If the manageable model reference is null, redirect to the dashboard
        if (is_null($manageableModelClass)) {
            return redirect()->route('wrla.dashboard')->with('error', "Manageable model `$manageableModelClass` not found.");
        }

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

            // Set page type
            WRLAHelper::setCurrentPageType($this->upsertType);
            WRLAHelper::setCurrentActiveManageableModelClass($this->manageableModelClass);
            WRLAHelper::setCurrentActiveManageableModelInstance($manageableModel);

            // If first render,set default livewire field values
            $usesLivewireFields = false;
            if ($this->numberOfRenders === 0) {
                foreach ($manageableFields as $manageableField) {
                    if ($manageableField->isModeledWithLivewire()) {
                        $manageableField->render(); // This allows for fields like JSON that modify the rendered value
                        $this->livewireData[$manageableField->getAttribute('name')] = $manageableField->getValue();
                        $usesLivewireFields = true;
                    }
                }
            }

            if ($usesLivewireFields) {
                ManageableModel::$livewireFields = $this->livewireData;
                $manageableFields = $manageableModel->getManageableFieldsFinal();
            }

            // If force refresh manageable fields, set field values
            if ($this->refreshManageableFields) {
                foreach ($manageableFields as $manageableField) {
                    if ($manageableField->isModeledWithLivewire()) {
                        $manageableField->setAttribute('value', $this->livewireData[$manageableField->getAttribute('name')]);
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
