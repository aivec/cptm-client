<?php

namespace Aivec\CptmClient\Models;

use JsonSerializable;

/**
 * Represents the provider of a Commercial Plugin/Theme
 */
class Provider implements JsonSerializable
{
    /**
     * Key for the provider (eg. company name in all lowercase)
     *
     * @var string
     */
    private $identifier;

    /**
     * Production API endpoint details
     *
     * @var ProviderEndpoint
     */
    private $productionEndpoint;

    /**
     * Staging API endpoint details
     *
     * @var ProviderEndpoint|null
     */
    private $stagingEndpoint;

    /**
     * Set to `false` with {@see self::disable()} if you
     * do not want the cptm-client to handle updates for
     * this provider.
     *
     * @var bool
     */
    private $enabled = true;

    /**
     * Initializes a provider
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string                $identifier
     * @param ProviderEndpoint      $productionEndpoint
     * @param ProviderEndpoint|null $stagingEndpoint
     * @return void
     */
    public function __construct(
        $identifier,
        ProviderEndpoint $productionEndpoint,
        ProviderEndpoint $stagingEndpoint = null
    ) {
        $this->identifier = $identifier;
        $this->productionEndpoint = $productionEndpoint;
        $this->stagingEndpoint = $stagingEndpoint;
    }

    /**
     * Disables updates for this provider
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function disable() {
        $this->enabled = false;
    }

    /**
     * Serializes for `json_encode()`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return array
     */
    public function jsonSerialize() {
        return [
            'identifier' => $this->identifier,
            'productionEndpoint' => $this->productionEndpoint,
            'stagingEndpoint' => $this->stagingEndpoint,
        ];
    }

    /**
     * Getter for `$identifier`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string
     */
    public function getIdentifier() {
        return $this->identifier;
    }

    /**
     * Getter for `$productionEndpoint`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return ProviderEndpoint
     */
    public function getProductionEndpoint() {
        return $this->productionEndpoint;
    }

    /**
     * Getter for `$stagingEndpoint`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return ProviderEndpoint|null
     */
    public function getStagingEndpoint() {
        return $this->stagingEndpoint;
    }

    /**
     * Returns `true` if updates are enabled for this provider, `false` otherwise
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return bool
     */
    public function isEnabled() {
        return $this->enabled;
    }
}
