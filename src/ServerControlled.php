<?php

namespace Aivec\CptmClient;

use Aivec\CptmClient\Models\Provider;
use Aivec\CptmClient\Models\ProviderEndpoint;
use Aivec\Plugins\EnvironmentSwitcher;

/**
 * Consumes list of providers **from an API server**
 */
class ServerControlled extends Client
{
    const PROVIDERS_KEY_PREFIX = 'cptmc_providers_';
    const PROVIDERS_URL_OVERRIDE_PREFIX = 'cptmc_providers_url_override_';
    const RETRY_STATE_KEY_PREFIX = 'cptmc_retry_state_';

    /**
     * Base retry interval in seconds (5 minutes)
     */
    const RETRY_BASE_INTERVAL = 300;

    /**
     * Maximum retry interval in seconds (24 hours)
     */
    const RETRY_MAX_INTERVAL = 86400;

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
     * Providers option name
     *
     * @var string
     */
    public $providersOptName;

    /**
     * Providers URL override option name
     *
     * @var string
     */
    public $providersUrlOverrideOptName;

    /**
     * Retry state option name for exponential backoff
     *
     * @var string
     */
    private $retryStateOptName;

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
        $this->providersOptName = self::PROVIDERS_KEY_PREFIX . $this->itemUniqueId;
        $this->providersUrlOverrideOptName = self::PROVIDERS_URL_OVERRIDE_PREFIX . $this->itemUniqueId;
        $this->retryStateOptName = self::RETRY_STATE_KEY_PREFIX . $this->itemUniqueId;
    }

    /**
     * Fetches list of providers from the `$providersEndpoint` if not yet fetched.
     *
     * Malformed responses are rejected. Uses exponential backoff to prevent
     * excessive API calls when the endpoint is unavailable.
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string|null $endpointOverride {@see Client::init()}
     * @return void
     */
    public function init($endpointOverride = null) {
        add_action('update_option_' . EnvironmentSwitcher\Utils::OPTION_KEY, function () {
            // clear providers and retry state when working environment is updated
            delete_option($this->providersOptName);
            $this->clearRetryState();
        });
        $providers = $this->getProviders();
        if ($providers === null && $this->canRetry()) {
            // update the providers list if it doesn't exist already and backoff allows retry.
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
     * Returns list of providers fetched from API
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return Provider[]|null
     */
    public function getProviders() {
        $sopt = get_option($this->providersOptName, null);
        if (!is_array($sopt)) {
            return null;
        }
        return self::buildProvidersFromArray($sopt);
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
        $endpoint = $this->providersEndpoint;
        $env = EnvironmentSwitcher\Utils::getEnv();
        if ($env === 'development') {
            $url = isset($_ENV['CPTM_CLIENT_PROVIDERS_URL']) ? (string)$_ENV['CPTM_CLIENT_PROVIDERS_URL'] : '';
            if (is_string($url) && !empty($url)) {
                $endpoint = $url;
            }

            // DB variable takes precedence over environment variable
            $url = get_option($this->providersUrlOverrideOptName, null);
            if (is_string($url) && !empty($url)) {
                $endpoint = $url;
            }
        }

        $response = wp_remote_get($endpoint);
        if (is_wp_error($response)) {
            $this->recordFailure();
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $providers = json_decode($body, true);
        if (!is_array($providers)) {
            // response is malformed
            $this->recordFailure();
            return false;
        }

        $valid = self::buildProvidersFromArray($providers);
        if ($valid === null) {
            // response is malformed
            $this->recordFailure();
            return false;
        }

        update_option($this->providersOptName, $providers);
        $this->clearRetryState();
        return true;
    }

    /**
     * Sets providers override URL for testing purposes
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $url
     * @return void
     */
    public function setProvidersUrlOverride($url) {
        update_option($this->providersUrlOverrideOptName, $url);
    }

    /**
     * Checks if a retry is allowed based on exponential backoff
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return bool
     */
    private function canRetry() {
        $state = get_option($this->retryStateOptName, null);
        if (!is_array($state)) {
            return true;
        }

        $lastFailure = isset($state['last_failure']) ? (int)$state['last_failure'] : 0;
        $failureCount = isset($state['failure_count']) ? (int)$state['failure_count'] : 0;

        if ($lastFailure === 0) {
            return true;
        }

        $interval = min(
            self::RETRY_BASE_INTERVAL * pow(2, $failureCount - 1),
            self::RETRY_MAX_INTERVAL
        );
        $nextRetry = $lastFailure + $interval;

        return time() >= $nextRetry;
    }

    /**
     * Records a failure for exponential backoff
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    private function recordFailure() {
        $state = get_option($this->retryStateOptName, null);
        $failureCount = 1;
        if (is_array($state) && isset($state['failure_count'])) {
            $failureCount = (int)$state['failure_count'] + 1;
        }

        update_option($this->retryStateOptName, [
            'last_failure' => time(),
            'failure_count' => $failureCount,
        ]);
    }

    /**
     * Clears the retry state (called on success)
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    private function clearRetryState() {
        delete_option($this->retryStateOptName);
    }

    /**
     * Updates option values if they are set in `$_POST`.
     *
     * @see Client::updateOptionsWithPost()
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function updateOptionsWithPost() {
        parent::updateOptionsWithPost();
        if (isset($_POST[$this->providersUrlOverrideOptName])) {
            $url = esc_url_raw(wp_unslash($_POST[$this->providersUrlOverrideOptName]));
            $this->setProvidersUrlOverride($url);
        }
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
            if (!is_string($identifier)) {
                continue;
            }
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
                    $enabled = isset($provider['enabled']) ? (bool)$provider['enabled'] : true;

                    $s[] = new Provider($identifier, $prod, $staging, $enabled);
                }
            }
        }

        return !empty($s) ? $s : null;
    }
}
