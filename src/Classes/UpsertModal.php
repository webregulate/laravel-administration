<?php

namespace WebRegulate\LaravelAdministration\Classes;

use Illuminate\View\ComponentAttributeBag;

class UpsertModal
{
    protected ?string $modelUrlAlias = null;

    /**
     * @param  class-string<ManageableModel>  $manageableModelClass
     */
    public function __construct(
        protected string $manageableModelClass,
        protected ?int $modelId = null,
        protected ?int $duplicateFrom = null,
    ) {}

    /**
     * @param  class-string<ManageableModel>  $manageableModelClass
     */
    public static function make(string $manageableModelClass, ?int $modelId = null, ?int $duplicateFrom = null): static
    {
        return new static($manageableModelClass, $modelId, $duplicateFrom);
    }

    /**
     * Override the URL alias passed to the modal component.
     */
    public function withModelUrlAlias(?string $modelUrlAlias): static
    {
        $this->modelUrlAlias = $modelUrlAlias;

        return $this;
    }

    /**
     * Data consumed by the wrlaOpenUpsertModal JavaScript helper.
     */
    public function data(): array
    {
        $upsertOptions = $this->manageableModelClass::getUpsertOptions();

        return [
            'modelUrlAlias' => $this->modelUrlAlias ?? $this->manageableModelClass::getUrlAlias(),
            'id' => $this->modelId,
            'duplicateFrom' => $this->duplicateFrom,
            'maxWidth' => $upsertOptions->getModalSize(),
            'maxWidthClass' => $upsertOptions->getModalSizeClass(),
        ];
    }

    /**
     * JavaScript handler for buttons or links that open the upsert modal.
     */
    public function onclick(bool $preventNavigation = false): string
    {
        $prefix = $preventNavigation
            ? "if (!this.hasAttribute('href')) return; event.preventDefault(); "
            : '';

        $data = $this->data();
        $modalData = implode(', ', [
            'modelUrlAlias: '.$this->javascriptString($data['modelUrlAlias']),
            'id: '.($data['id'] ?? 'null'),
            'duplicateFrom: '.($data['duplicateFrom'] ?? 'null'),
            'maxWidth: '.$this->javascriptString($data['maxWidth']),
            'maxWidthClass: '.$this->javascriptString($data['maxWidthClass']),
        ]);

        return $prefix.'window.wrlaOpenUpsertModal(this, { '.$modalData.' });';
    }

    protected function javascriptString(string $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );

        return "'".substr($encoded, 1, -1)."'";
    }

    /**
     * Attributes suitable for package action classes and other button-like elements.
     */
    public function attributes(bool $preventNavigation = false): array
    {
        return [
            'onclick' => $this->onclick($preventNavigation),
        ];
    }

    /**
     * Escaped attributes suitable for direct rendering on an anchor element.
     */
    public function linkAttributes(): ComponentAttributeBag
    {
        return new ComponentAttributeBag($this->attributes(preventNavigation: true));
    }

    /**
     * Escaped attributes suitable for direct rendering on a button element.
     */
    public function buttonAttributes(): ComponentAttributeBag
    {
        return new ComponentAttributeBag($this->attributes());
    }
}