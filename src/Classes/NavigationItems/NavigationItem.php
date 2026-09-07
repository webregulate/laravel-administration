<?php

namespace WebRegulate\LaravelAdministration\Classes\NavigationItems;

use App\Models\UserData;
use Illuminate\Support\Arr;
use Illuminate\View\ComponentAttributeBag;
use WebRegulate\LaravelAdministration\Classes\ManageableModel;
use WebRegulate\LaravelAdministration\Classes\UpsertModal;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

class NavigationItem
{
    /**
     * Navigation items to be passed to views. Setup in config/wr-laravel-administration.php and finalized in WRLAServiceProvider.php
     *
     * @var array
     */
    public static $navigationItems = [];

    // Index total
    public static $indexTotal;

    // Index
    public int $index = 0;

    // Show on condition callback
    private $showOnConditionCallback = null;

    // Enable on condition callback
    private $enableOnConditionCallback = null;

    // Open in new tab
    public bool $openInNewTab = false;

    // Additional HTML attributes applied to the navigation link
    public array $attributes = [];

    /**
     * Constructor
     */
    public function __construct(
        public ?string $route = null,
        public ?array $routeData = null,
        public string $name = '',
        public string $icon = 'fa fa-question',
        public array $children = [],
        public ?string $url = null,
    ) {
        // Increment index
        static::$indexTotal++;

        // Set index
        $this->index = static::$indexTotal;
    }

    /**
     * Make (static constructor)
     *
     * @param  ?string  $url  Optional direct URL. When set, overrides $route and $routeData entirely.
     */
    public static function make(?string $route = null, ?array $routeData = null, string $name = '', string $icon = 'fa fa-question', array $children = [], ?string $url = null): static
    {
        // Replace any classes (strings) by calling getNavigationItem() method on each
        foreach ($children as $key => $child) {
            if (is_string($child)) {
                $children[$key] = $children[$key]::getNavigationItem();
            }
        }

        return new static($route, $routeData, $name, $icon, $children, $url);
    }

    /**
     * Make divider (NavigationItemDivider static constructor)
     */
    public static function makeDivider(string $title, string $icon = ''): NavigationItemDivider
    {
        return new NavigationItemDivider($title, $icon);
    }

    /**
     * Make manageable models (NavigationItemsManageableModels static import)
     *
     * @return NavigationItem[]
     */
    public static function makeManageableModels(... $models): array
    {
        return NavigationItemsManageableModels::import($models);
    }

    /**
     * Has children
     */
    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    /**
     * Get Url
     */
    public function getUrl(): string
    {
        if ($this->url !== null) {
            return $this->url;
        }

        if (empty($this->route)) {
            return '#';
        }

        return route($this->route, $this->routeData ?? []);
    }

    /**
     * Is active
     */
    public function isActive(): bool
    {
        if ($this->url !== null) {
            return request()->url() === $this->url;
        }

        return WRLAHelper::isCurrentRouteWithParameters($this->route, $this->routeData ?? []);
    }

    /**
     * Is child active
     */
    public function isChildActive(): bool
    {
        foreach ($this->children as $child) {
            if ($child->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Show on condition. If false will be: not shown and disabled
     *
     * @return $this
     */
    public function showOnCondition(callable $callback): static
    {
        $this->showOnConditionCallback = $callback;

        return $this;
    }

    /**
     * Check show condition
     */
    public function checkShowCondition(): bool
    {
        if ($this->showOnConditionCallback === null) {
            return true;
        }

        return call_user_func($this->showOnConditionCallback);
    }

    /**
     * Enable on condition, callback must return true or a string with the reason why not enabled (appears as tooltip)
     *
     * @return $this
     */
    public function enableOnCondition(callable $callback): static
    {
        $this->enableOnConditionCallback = $callback;

        return $this;
    }

    /**
     * Check enabled condition, returns true or a string with the reason why not enabled (appears as tooltip)
     */
    public function checkEnabledCondition(): true|string
    {
        if ($this->enableOnConditionCallback === null) {
            return true;
        }

        return call_user_func($this->enableOnConditionCallback, UserData::getCurrentUserData());
    }

    /**
     * Open in new tab
     *
     * @return $this
     */
    public function openInNewTab(bool $openInNewTab = true): static
    {
        $this->openInNewTab = $openInNewTab;

        return $this;
    }

    /**
     * Open a manageable model's create, edit, or duplicate form in a modal.
     *
     * @param  class-string<ManageableModel>  $manageableModelClass
     */
    public function openUpsertModal(string $manageableModelClass, ?int $modelId = null, ?int $duplicateFrom = null): static
    {
        $this->attributes = array_merge(
            $this->attributes,
            UpsertModal::make($manageableModelClass, $modelId, $duplicateFrom)->attributes(preventNavigation: true),
        );

        return $this;
    }

    /**
     * Set additional HTML attributes on the navigation link.
     */
    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * Get the navigation link attributes as an escaped Blade attribute bag.
     */
    public function getAttributes(): ComponentAttributeBag
    {
        return Arr::toAttributeBag($this->attributes);
    }

    /**
     * Render the navigation item
     */
    public function render(): string
    {
        return $this->name;
    }
}
