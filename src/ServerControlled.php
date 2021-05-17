<?php

namespace Aivec\CptmClient;

use Aivec\CptmClient\Models\Provider;
use Aivec\CptmClient\Models\ProviderEndpoint;

/**
 * Consumes list of providers **from an API server**
 */
class ServerControlled extends Client
{
    const PROVIDERS_KEY_PREFIX = 'cptmc_providers_';

    /**
     * URL that returns a list of providers as a JSON string
     *
     * @var string
     */
    private $providersEndpoint;

    /**
     * Providers list update cron event name
     *
     * @var string
     */
    public $updateProvidersListEvent;

    /**
     * Set plugin/theme information for updates
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $itemUniqueId {@see Client::__construct()}
     * @param string $itemVersion {@see Client::__construct()}
     * @param string $ptpath {@see Client::__construct()}
     * @param string $providersEndpoint Should point to a URL that returns a list of providers as a JSON string
     * @return void
     */
    public function __construct($itemUniqueId, $itemVersion, $ptpath, $providersEndpoint) {
        parent::__construct($itemUniqueId, $itemVersion, $ptpath);
        $this->providersEndpoint = $providersEndpoint;
        $this->updateProvidersListEvent = 'cptmc_update_providers_' . $this->itemUniqueId;
    }

    /**
     * Returns list of providers fetched from API
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return Provider[]|null
     */
    public function getProviders() {
        $sopt = get_option(self::PROVIDERS_KEY_PREFIX . $this->itemUniqueId, null);
        if (!is_array($sopt)) {
            return null;
        }
        return self::buildProvidersFromArray($sopt);
    }

    /**
     * Fetches list of providers from the `$providersEndpoint` if not yet fetched.
     *
     * Malformed responses are rejected.
     *
     * @todo Maybe add exponential backoff handling for when `WP_Error` is returned by the providers endpoint
     *       since the current implementation will hit the endpoint on every page load as long as the providers
     *       array doesn't exist.
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string|null $endpointOverride {@see Client::init()}
     * @return void
     */
    public function init($endpointOverride = null) {
        $providers = $this->getProviders();
        if ($providers === null) {
            $this->updateProvidersList();
        }

        add_action($this->updateProvidersListEvent, [$this, 'updateProvidersList']);
        $cron = wp_next_scheduled($this->updateProvidersListEvent);
        if (!$cron) {
            // check daily for updated list of providers
            $tz = new \DateTimeZone('Asia/Tokyo');
            if (function_exists('wp_timezone')) {
                $tz = wp_timezone();
            }
            $timestamp = (new \DateTime('03:00', $tz))->add(new \DateInterval('P1D'))->getTimestamp();
            wp_schedule_event($timestamp, 'daily', $this->updateProvidersListEvent);
        }
        register_deactivation_hook($this->ptpath, [$this, 'clearCron']);

        parent::init($endpointOverride);
    }

    /**
     * Clear cron when plugin/theme using `ServerControlled` client is deactivated
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function clearCron() {
        wp_clear_scheduled_hook($this->updateProvidersListEvent);
    }

    /**
     * Fetches list of providers from API and saves it as an option
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return bool
     */
    public function updateProvidersList() {
        $response = wp_remote_get($this->providersEndpoint);
        if (is_wp_error($response)) {
            // whoops...
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $providers = json_decode($body, true);
        if (!is_array($providers)) {
            // response is malformed
            return false;
        }

        $valid = self::buildProvidersFromArray($providers);
        if ($valid === null) {
            // response is malformed
            return false;
        }

        update_option(self::PROVIDERS_KEY_PREFIX . $this->itemUniqueId, $providers);
        return true;
    }

    /**
     * Builds list of `Provider` objects given a map of providers
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param array $providers
     * @return Provider[]|null
     */
    public static function buildProvidersFromArray(array $providers) {
        $s = [];
        foreach ($providers as $identifier => $provider) {
            if (!empty($provider['productionEndpoint']) && is_array($provider['productionEndpoint'])) {
                $prodEndpoint = $provider['productionEndpoint'];
                if (!empty($prodEndpoint['siteurl']) && !empty($prodEndpoint['apiurl'])) {
                    $prod = new ProviderEndpoint($prodEndpoint['siteurl'], $prodEndpoint['apiurl']);
                    // staging
                    $staging = null;
                    if (!empty($provider['stagingEndpoint']) && is_array($provider['stagingEndpoint'])) {
                        $stagingEndpoint = $provider['stagingEndpoint'];
                        if (!empty($stagingEndpoint['siteurl']) && !empty($stagingEndpoint['apiurl'])) {
                            $staging = new ProviderEndpoint($stagingEndpoint['siteurl'], $stagingEndpoint['apiurl']);
                        }
                    }

                    $s[] = new Provider($identifier, $prod, $staging);
                }
            }
        }

        return !empty($s) ? $s : null;
    }
}
