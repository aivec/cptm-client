<?php

namespace Aivec\CptmClient;

use Aivec\CptmClient\Models\Provider;
use Aivec\CptmClient\Models\ProviderEndpoint;
use Aivec\Plugins\EnvironmentSwitcher;
use RuntimeException;

/**
 * Commercial Plugin/Theme Manager client
 */
abstract class Client
{
    const SELECTED_PROVIDER_KEY_PREFIX = 'cptmc_selected_provider_';
    const DEV_URL_OVERRIDE_KEY_PREFIX = 'cptmc_dev_url_override_';

    /**
     * Absolute path to the plugin file or theme directory
     *
     * @var string
     */
    protected $ptpath;

    /**
     * The unique identifier for the plugin/theme
     *
     * @var string
     */
    protected $itemUniqueId;

    /**
     * Plugin/theme version
     *
     * @var string
     */
    protected $itemVersion;

    /**
     * Set plugin/theme information for updates
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $itemUniqueId Unique identifier for the plugin/theme registered with
     *                             `WCEX Commercial Plugin/Theme Manager`
     * @param string $itemVersion Plugin/theme version
     * @param string $ptpath Absolute path to the plugin file or theme directory.
     * @return void
     */
    public function __construct($itemUniqueId, $itemVersion, $ptpath) {
        $this->itemUniqueId = $itemUniqueId;
        $this->itemVersion = $itemVersion;
        $this->ptpath = $ptpath;
    }

    /**
     * Checks for plugin/theme updates at the appropriate endpoint once a day
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string|null $endpointOverride Can be set to override the update endpoint URL
     * @return void
     */
    public function init($endpointOverride = null) {
        add_action('wp_error_added', [$this, 'setUpdateApiErrorResponse'], 10, 4);

        // endpoint override param is given highest precedence
        if (!empty($endpointOverride)) {
            $this->buildUpdateChecker($endpointOverride);
            return;
        }

        $env = EnvironmentSwitcher\Options::getOptions()['env'];
        if ($env === 'development') {
            // environment variable takes precedence over DB dev override URL option
            $url = isset($_ENV['CPTM_CLIENT_UPDATE_URL']) ? (string)$_ENV['CPTM_CLIENT_UPDATE_URL'] : '';
            if (!empty($url)) {
                $this->buildUpdateChecker($url);
                return;
            }

            $url = get_option(self::DEV_URL_OVERRIDE_KEY_PREFIX, null);
            if (is_string($url) && !empty($url)) {
                $this->buildUpdateChecker($url);
                return;
            }
        }

        $provider = $this->getSelectedProvider();
        if ($provider === null) {
            // if in a staging or production environment, selecting a provider is required
            return;
        }

        if ($env === 'staging') {
            $pendpoint = $provider->getStagingEndpoint();
            if ($pendpoint !== null) {
                $this->buildUpdateChecker($pendpoint->getApiUrl());
                return;
            }
        }

        $this->buildUpdateChecker($provider->getProductionEndpoint()->getApiUrl());
    }

    /**
     * Sets the message for the `WP_Error` used for a failed update attempt.
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string|int $code     Error code.
     * @param string     $message  Error message.
     * @param mixed      $data     Error data. Might be empty.
     * @param \WP_Error  $wperror The WP_Error object.
     * @return void
     */
    public function setUpdateApiErrorResponse($code, $message, $data, $wperror) {
        $body = isset($data['body']) ? (string)$data['body'] : '';
        if (empty($body)) {
            return;
        }
        $json = json_decode($body, true);
        if (empty($json)) {
            return;
        }
        if (empty($json['type']) || empty($json['cptItem']) || empty($json['error'])) {
            return;
        }
        if ($json['type'] === 'WCEXCPTM_API_ERROR') {
            if ($json['cptItem']['itemUniqueId'] !== $this->itemUniqueId) {
                return;
            }

            /*
             * http_404 is set because WordPress automatically interprets a failed download request
             * as if the file couldn't be found...
             *
             * {@see wp-admin/includes/file.php download_url()}
             */
            if (!isset($wperror->errors['http_404'])) {
                return;
            }
            if (!isset($wperror->errors['http_404'][0])) {
                return;
            }
            $wperror->errors['http_404'][0] = $json['error']['message'];
        }
    }

    /**
     * Returns the `ProviderEndpoint` for a given `Provider`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param Provider $provider
     * @return ProviderEndpoint
     */
    public function getProviderEndpoint(Provider $provider) {
        $env = EnvironmentSwitcher\Options::getOptions()['env'];
        if ($env === 'development') {
            // environment variable takes precedence over DB dev override URL option
            $url = isset($_ENV['CPTM_CLIENT_UPDATE_URL']) ? (string)$_ENV['CPTM_CLIENT_UPDATE_URL'] : '';
            if (!empty($url)) {
                return new ProviderEndpoint($url, $url);
            }

            $url = get_option(self::DEV_URL_OVERRIDE_KEY_PREFIX, null);
            if (is_string($url) && !empty($url)) {
                return new ProviderEndpoint($url, $url);
            }
        }

        if ($env === 'staging') {
            $pendpoint = $provider->getStagingEndpoint();
            if ($pendpoint !== null) {
                return $pendpoint;
            }
        }

        return $provider->getProductionEndpoint();
    }

    /**
     * Updates the selected provider if it is set in `$_POST`
     * and the provider exists
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function updateSelectedProviderIfSet() {
        if (!empty($_POST['cptmc_set_provider'][$this->itemUniqueId])) {
            $selected = (string)$_POST['cptmc_set_provider'][$this->itemUniqueId];
            $providers = $this->getProviders();
            if (!is_array($providers)) {
                return;
            }
            foreach ($providers as $provider) {
                if ($selected === $provider->getIdentifier()) {
                    update_option(self::SELECTED_PROVIDER_KEY_PREFIX . $this->itemUniqueId, $selected);
                    return;
                }
            }
        }
    }

    /**
     * Builds the update checker
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $url
     * @return void
     * @throws RuntimeException Thrown when `$ptpath` isn't valid.
     */
    public function buildUpdateChecker($url) {
        \Puc_v4_Factory::buildUpdateChecker(
            add_query_arg(
                array_merge(
                    [
                        'wcexcptm_update_action' => 'get_metadata',
                        'wcexcptm_cptitem_unique_id' => $this->itemUniqueId,
                    ],
                    $this->getAnalyticsData()
                ),
                $url . '/wp-update-server/'
            ),
            $this->ptpath
        );
    }

    /**
     * Returns array of client data for analytics
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @global string $wp_version
     * @global \wpdb $wpdb
     * @return array
     */
    public function getAnalyticsData() {
        global $wp_version, $wpdb;

        $server_info = null;
        if ($wpdb->use_mysqli) {
            // phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_get_server_info
            $server_info = mysqli_get_server_info($wpdb->dbh);
        } else {
            // phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysql_get_server_info
            // phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved
            $server_info = mysql_get_server_info($wpdb->dbh);
        }
        // phpcs:enable

        return [
            'domain' => $this->getHost(),
            'productVersion' => $this->itemVersion,
            'welcartVersion' => USCES_VERSION,
            'wordpressVersion' => $wp_version,
            'phpVersion' => phpversion(),
            'webServer' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '',
            'databaseInfo' => $server_info,
            'databaseVersion' => $wpdb->db_version(),
        ];
    }

    /**
     * Gets the host name of the current server. The host name extracted via this method
     * **MUST** be the same as the domain registered by the client.
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string
     */
    protected function getHost() {
        $possible_host_sources = ['HTTP_X_FORWARDED_HOST', 'HTTP_HOST', 'SERVER_NAME'];
        $host = '';
        foreach ($possible_host_sources as $source) {
            if (!empty($host)) {
                break;
            }
            if (empty($_SERVER[$source])) {
                continue;
            }
            $url = esc_url_raw(wp_unslash($_SERVER[$source]));
            $scheme = wp_parse_url($url, PHP_URL_SCHEME);
            if (!$scheme) {
                $url = 'http://' . $url;
            }
            $host = wp_parse_url($url, PHP_URL_HOST);
        }
        return trim($host);
    }

    /**
     * Returns selected provider, or, the first index if only one provider exists and no different provider has been
     * previously selected, `null` if only one provider exists but a different provider has been previously
     * selected, or `null` if more than one provider exists and neither is selected.
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return Provider|null
     */
    public function getSelectedProvider() {
        $providers = $this->getProviders();
        if (empty($providers)) {
            return null;
        }

        $ssi = get_option(self::SELECTED_PROVIDER_KEY_PREFIX . $this->itemUniqueId, null);
        if (count($providers) === 1) {
            // no provider has been previously selected, return first index
            if ($ssi === null) {
                return $providers[0];
            }
            // the selected provider is the same as the only available provider, return first index
            if ($providers[0]->getIdentifier() === $ssi) {
                return $providers[0];
            }
            // a different provider that is no longer available was previously selected, return `null`
            return null;
        }

        // more than one provider exists and no provider has been selected, return `null`
        if (empty($ssi)) {
            return null;
        }

        foreach ($providers as $s) {
            if ($s->getIdentifier() === $ssi) {
                return $s;
            }
        }

        // the selected provider is no longer available, return `null`
        return null;
    }

    /**
     * Getter for `$itemUniqueId`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string
     */
    public function getItemUniqueId() {
        return $this->itemUniqueId;
    }

    /**
     * Getter for `$itemVersion`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string
     */
    public function getItemVersion() {
        return $this->itemVersion;
    }

    /**
     * Returns a list of providers for the cpt item
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return Provider[]|null
     */
    abstract public function getProviders();
}
