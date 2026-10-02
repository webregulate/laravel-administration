<?php

namespace WebRegulate\LaravelAdministration\Classes;

/**
 * Options for reordering a manageable model.
 */
class OrderableOptions
{
    public const MODE_MODAL = 'modal';
    public const MODE_PAGE = 'page';

    public function __construct(
        public bool $enabled = false,
        public string $orderColumn = 'sort_order',
        public ?string $labelColumn = null,
        public array $searchColumns = [],
        public int $startAt = 1,
        public int $updateChunkSize = 100,
        public string $mode = self::MODE_MODAL,
    ) {}

    /**
     * Disable reordering. This is the default for generated manageable models.
     */
    public static function disabled(): static
    {
        return new static;
    }

    /**
     * Enable reordering using the given database column.
     */
    public static function make(string $orderColumn, ?string $labelColumn = null, string $mode = self::MODE_MODAL): static
    {
        return $mode === self::MODE_MODAL
            ? static::modal($orderColumn, $labelColumn)
            : static::page($orderColumn, $labelColumn);
    }

    /**
     * Enable reordering in a modal.
     */
    public static function modal(string $orderColumn, ?string $labelColumn = null): static
    {
        return new static(
            enabled: true,
            orderColumn: $orderColumn,
            labelColumn: $labelColumn,
            searchColumns: array_values(array_filter([$labelColumn])),
            mode: self::MODE_MODAL,
        );
    }

    /**
     * Enable reordering on its dedicated page.
     */
    public static function page(string $orderColumn, ?string $labelColumn = null): static
    {
        return new static(
            enabled: true,
            orderColumn: $orderColumn,
            labelColumn: $labelColumn,
            searchColumns: array_values(array_filter([$labelColumn])),
            mode: self::MODE_PAGE,
        );
    }

    /**
     * Whether the reorder view is presented in a modal.
     */
    public function isModal(): bool
    {
        return $this->mode === self::MODE_MODAL;
    }

    /**
     * Set the column displayed for each record.
     */
    public function labelColumn(string $column): static
    {
        $this->labelColumn = $column;

        if ($this->searchColumns === []) {
            $this->searchColumns = [$column];
        }

        return $this;
    }

    /**
     * Set the columns included in the client-side reorder search.
     */
    public function searchColumns(string ...$columns): static
    {
        $this->searchColumns = array_values(array_unique($columns));

        return $this;
    }

    /**
     * Set the first persisted position (normally 0 or 1).
     */
    public function startAt(int $position): static
    {
        $this->startAt = $position;

        return $this;
    }

    /**
     * Set how many updates are issued per transaction chunk.
     */
    public function updateChunkSize(int $size): static
    {
        $this->updateChunkSize = max(1, $size);

        return $this;
    }
}
