<?php

namespace WebRegulate\LaravelAdministration\Classes;

abstract class ConfiguredModeBasedHandler
{
    /**
     * Current mode
     */
    protected ?string $mode;

    /**
     * Mode configuration
     */
    protected array $currentConfiguration;

    /**
     * Base configuration path
     */
    abstract public function baseConfigurationPath(): string;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Get all configuration for the given base path
        $baseConfiguration = config('wrla.'.$this->baseConfigurationPath());

        // Get the current mode
        $this->mode = $baseConfiguration['current'] ?? null;

        // If mode is empty, or mode does not exist in configuration, return
        if (empty($this->mode) || !isset($baseConfiguration[$this->mode])) {
            $this->mode = null;
            return;
        }

        // Get mode configuration
        $this->currentConfiguration = $baseConfiguration[$this->mode] ?? [];
    }

    /**
     * Get current mode.
     */
    public function getCurrentMode(): ?string
    {
        return $this->mode;
    }

    /**
     * Get current mode configuration.
     */
    public function getCurrentConfiguration(?string $dottedPath = null, ?string $default = null): mixed
    {
        if ($dottedPath !== null) {
            return data_get($this->currentConfiguration, $dottedPath, $default);
        }
        
        return $this->currentConfiguration;
    }

    /**
     * Feature is enabled
     */
    public function isEnabled(): bool
    {
        return $this->getCurrentMode() !== null;
    }
}