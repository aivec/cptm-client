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
    const UPDATE_URL_OVERRIDE_KEY_PREFIX = 'cptmc_update_url_override_';

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
     * Selected provider option name
     *
     * @var string
     */
    public $selectedProviderOptName;

    /**
     * Update URL override option name
     *
     * @var string
     */
    public $updateUrlOverrideOptName;

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
        $this->selectedProviderOptName = self::SELECTED_PROVIDER_KEY_PREFIX . $this->itemUniqueId;
        $this->updateUrlOverrideOptName = self::UPDATE_URL_OVERRIDE_KEY_PREFIX . $this->itemUniqueId;

        $mopath = __DIR__ . '/languages/cptmc-' . get_locale() . '.mo';
        if (file_exists($mopath)) {
            load_textdomain('cptmc', $mopath);
        } else {
            load_textdomain('cptmc', __DIR__ . '/languages/cptmc-en.mo');
        }
    }

    /**
     * Checks for plugin/theme updates at the appropriate endpoint once a day
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string|null $endpointOverride Can be set to override the update endpoint URL
     * @return void
     */
    public function init($endpointOverride = null) {
        $provider = $this->getSelectedProvider();
        if ($provider === null) {
            // selecting a provider is required to build the update checker otherwise we don't know
            // where to send the request...
            return;
        }

        // handle display of errors returned by the update checker even if updates are being handled
        // outside of this plugin/theme
        add_action('wp_error_added', [$this, 'setUpdateApiErrorResponse'], 10, 4);

        if (!$provider->isEnabled()) {
            // the provider isn't enabled. Abort
            return;
        }

        // endpoint override param is given highest precedence
        if (!empty($endpointOverride)) {
            $this->buildUpdateChecker($endpointOverride);
            return;
        }

        $env = EnvironmentSwitcher\Utils::getEnv();
        if ($env === 'development') {
            // DB variable takes precedence over environment variable
            $url = get_option($this->updateUrlOverrideOptName, null);
            if (is_string($url) && !empty($url)) {
                $this->buildUpdateChecker($url);
                return;
            }

            $url = isset($_ENV['CPTM_CLIENT_UPDATE_URL']) ? (string)$_ENV['CPTM_CLIENT_UPDATE_URL'] : '';
            if (!empty($url)) {
                $this->buildUpdateChecker($url);
                return;
            }
        }

        $url = '';
        if ($env === 'staging') {
            $pendpoint = $provider->getStagingEndpoint();
            if ($pendpoint !== null) {
                $url = $pendpoint->getApiUrl();
            }
        }

        if (empty($url)) {
            $url = $provider->getProductionEndpoint()->getApiUrl();
        }

        $url = apply_filters("cptmc_filter_update_checker_url_{$this->itemUniqueId}", $url, $provider, $env, $this);
        $this->buildUpdateChecker($url);
    }

    /**
     * Sets selected provider option
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $selected
     * @return void
     */
    public function setSelectedProvider($selected) {
        $providers = $this->getProviders();
        if (!is_array($providers)) {
            return;
        }
        foreach ($providers as $provider) {
            if ($selected === $provider->getIdentifier()) {
                update_option($this->selectedProviderOptName, $selected);
                return;
            }
        }
    }

    /**
     * Sets update override URL for testing purposes
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $url
     * @return void
     */
    public function setUpdateUrlOverride($url) {
        update_option($this->updateUrlOverrideOptName, $url);
    }

    /**
     * Updates option values if they are set in `$_POST`.
     *
     * This method can be used in custom option update handling if not using any of
     * the default forms/pages that this library provides under `Aivec\CptmClient\Views\*`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function updateOptionsWithPost() {
        if (isset($_POST[$this->selectedProviderOptName])) {
            $selected = (string)$_POST[$this->selectedProviderOptName];
            $this->setSelectedProvider($selected);
        }
        if (isset($_POST[$this->updateUrlOverrideOptName])) {
            $url = (string)$_POST[$this->updateUrlOverrideOptName];
            $this->setUpdateUrlOverride($url);
        }
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
        if (is_object($data)) {
            $data = (array) $data;
        }
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
            $emessage = $json['error']['message'];
            $emessage = apply_filters(
                "cptmc_filter_update_error_message_{$this->itemUniqueId}",
                $emessage,
                $json,
                $this,
                $code,
                $message,
                $data,
                $wperror
            );
            $wperror->errors['http_404'][0] = $emessage;
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
        $env = EnvironmentSwitcher\Utils::getEnv();
        if ($env === 'development') {
            $ident = $provider->getIdentifier();

            // DB variable takes precedence over environment variable
            $url = get_option($this->updateUrlOverrideOptName, null);
            if (is_string($url) && !empty($url)) {
                return new ProviderEndpoint($url, $url, "{$url} ({$ident})");
            }

            $url = isset($_ENV['CPTM_CLIENT_UPDATE_URL']) ? (string)$_ENV['CPTM_CLIENT_UPDATE_URL'] : '';
            if (!empty($url)) {
                return new ProviderEndpoint($url, $url, "{$url} ({$ident})");
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
                $url
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
        // if ($wpdb->use_mysqli) {
            // phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_get_server_info
            $server_info = mysqli_get_server_info($wpdb->dbh);
        // } else {
            // phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysql_get_server_info
            // phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved
        //     $server_info = mysql_get_server_info($wpdb->dbh);
        // }
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
    public function getHost() {
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

        $spi = get_option($this->selectedProviderOptName, null);
        if (count($providers) === 1) {
            // no provider has been previously selected, return first index
            if ($spi === null) {
                return $providers[0];
            }
            // the selected provider is the same as the only available provider, return first index
            if ($providers[0]->getIdentifier() === $spi) {
                return $providers[0];
            }
            // a different provider that is no longer available was previously selected, return `null`
            return null;
        }

        // more than one provider exists and no provider has been selected, return `null`
        if (empty($spi)) {
            return null;
        }

        foreach ($providers as $s) {
            if ($s->getIdentifier() === $spi) {
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
