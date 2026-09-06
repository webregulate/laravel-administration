<?php

namespace WebRegulate\LaravelAdministration\Classes;

/**
 * Value object describing how a manageable model's upsert (create/edit) view is
 * presented and behaves after save. Passed to ManageableModel::setUpsertOptions().
 *
 * Use the directly callable static constructors as entry points:
 *
 *     UpsertOptions::default();              // mode and options from config
 *     UpsertOptions::page();                 // full page upsert
 *     UpsertOptions::modal();                // modal upsert (default size from config)
 *     UpsertOptions::modal('4xl');           // modal upsert with an explicit size
 *     UpsertOptions::modal()->returnToBrowseAfterSave(false); // override the after-save behaviour
 *
 * When setUpsertOptions() is never called, the model falls back to UpsertOptions::fromConfig().
 */
class UpsertOptions
{
    public const MODE_PAGE = 'page';
    public const MODE_MODAL = 'modal';

    /**
     * Tailwind max-width classes per size key. Mirrors the protected
     * $maxWidths map in wire-elements/modal ModalComponent so the class can be
     * resolved server side and passed as a per-open modal attribute.
     */
    protected const MODAL_SIZE_CLASSES = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-md md:max-w-lg',
        'xl' => 'sm:max-w-md md:max-w-xl',
        '2xl' => 'sm:max-w-md md:max-w-xl lg:max-w-2xl',
        '3xl' => 'sm:max-w-md md:max-w-xl lg:max-w-3xl',
        '4xl' => 'sm:max-w-md md:max-w-xl lg:max-w-3xl xl:max-w-4xl',
        '5xl' => 'sm:max-w-md md:max-w-xl lg:max-w-3xl xl:max-w-5xl',
        '6xl' => 'sm:max-w-md md:max-w-xl lg:max-w-3xl xl:max-w-5xl 2xl:max-w-6xl',
        '7xl' => 'sm:max-w-md md:max-w-xl lg:max-w-3xl xl:max-w-5xl 2xl:max-w-7xl',
    ];

    /**
     * @param  string  $mode  One of self::MODE_PAGE | self::MODE_MODAL.
     * @param  ?string  $modalSize  Modal size key (only relevant in modal mode).
     * @param  bool  $returnToBrowseAfterSave  Whether a successful save returns to the browse page.
     */
    public function __construct(
        public string $mode,
        public ?string $modalSize = null,
        public bool $returnToBrowseAfterSave = false,
    ) {}

    /**
     * Build options from the wr-laravel-administration.upsert config, using the
     * configured default mode. This is the fallback when a model never calls
     * setUpsertOptions().
     */
    public static function fromConfig(): static
    {
        $mode = config('wr-laravel-administration.upsert.mode', self::MODE_PAGE);

        return $mode === self::MODE_MODAL ? static::modal() : static::page();
    }

    /**
     * Use the default mode and options from configuration.
     */
    public static function default(): static
    {
        return static::fromConfig();
    }

    /**
     * Alias for fromConfig(): the config driven default.
     */
    public static function make(): static
    {
        return static::fromConfig();
    }

    /**
     * Full page upsert view (the standard WRLA upsert page).
     */
    public static function page(): static
    {
        return new static(
            self::MODE_PAGE,
            null,
            (bool) config('wr-laravel-administration.upsert.page.return_to_browse_after_save', false),
        );
    }

    /**
     * Modal upsert view (hosts the upsert component inside a wire-elements modal).
     *
     * @param  ?string  $size  Modal size key, defaults to the configured modal size.
     */
    public static function modal(?string $size = null): static
    {
        return new static(
            self::MODE_MODAL,
            $size ?? config('wr-laravel-administration.upsert.modal.size', '6xl'),
            (bool) config('wr-laravel-administration.upsert.modal.return_to_browse_after_save', true),
        );
    }

    /**
     * Override whether a successful save returns to the browse page (page mode
     * redirects, modal mode closes). When false the upsert view stays open and
     * surfaces the result inline.
     */
    public function returnToBrowseAfterSave(bool $return = true): static
    {
        $this->returnToBrowseAfterSave = $return;

        return $this;
    }

    /**
     * Whether the upsert view is presented as a modal.
     */
    public function isModal(): bool
    {
        return $this->mode === self::MODE_MODAL;
    }

    /**
     * Whether a successful save should return to the browse page.
     */
    public function shouldReturnToBrowseAfterSave(): bool
    {
        return $this->returnToBrowseAfterSave;
    }

    /**
     * The modal size key (falls back to 6xl if unset).
     */
    public function getModalSize(): string
    {
        return $this->modalSize ?? '6xl';
    }

    /**
     * The Tailwind max-width class string for the current modal size.
     */
    public function getModalSizeClass(): string
    {
        $size = $this->getModalSize();

        return self::MODAL_SIZE_CLASSES[$size] ?? self::MODAL_SIZE_CLASSES['6xl'];
    }
}
