<?php
namespace Aivec\Welcart\CptmClient;

/**
 * WCEX Commercial Plugins/Themes Manager client
 */
class Client {

    /**
     * Absolute path to the plugin/theme entry file
     *
     * @var string
     */
    private $pt_file;

    /**
     * The unique identifier for the plugin/theme
     *
     * @var string
     */
    private $productUniqueId;

    /**
     * Plugin/theme version
     *
     * @var string
     */
    private $productVersion;

    /**
     * Set plugin/theme information for updates
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $pt_file Name of plugin/theme entry file *INCLUDING* absolute path
     * @param string $productUniqueId Unique identifier for the plugin/theme
     * @param string $productVersion Plugin/theme version
     */
    public function __construct($pt_file, $productUniqueId, $productVersion) {
        $this->pt_file = $pt_file;
        $this->productUniqueId = $productUniqueId;
        $this->productVersion = $productVersion;
    }

    /**
     * Returns update endpoint URL for the current environment
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string
     */
    public function getUpdateEndpoint() {
        $url = 'https://aivec.co.jp/plugin';
        $env = isset($_ENV['AVC_NODE_ENV']) ? $_ENV['AVC_NODE_ENV'] : 'prod';
        switch ($env) {
            case 'development':
                $bridgeIp = isset($_ENV['DOCKER_BRIDGE_IP']) ? $_ENV['DOCKER_BRIDGE_IP'] : '';
                $port = isset($_ENV['UPDATE_CONTAINER_PORT']) ? $_ENV['UPDATE_CONTAINER_PORT'] : '';
                if (!empty($bridgeIp) && !empty($port)) {
                    $url = 'http://' . $bridgeIp . ':' . $port;
                }
                break;
            case 'staging':
                $url = 'https://aivec.co.jp/plugin_test';
                break;
        }

        return $url;
    }

    /**
     * Checks for plugin/theme updates at the appropriate endpoint every hour
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @global string $wp_version
     * @global \wpdb $wpdb
     * @param string|null $endpointOverride Can be set to override the update endpoint URL
     * @return void
     */
    public function initUpdateChecker($endpointOverride = null) {
        global $wp_version, $wpdb;

        $url = $this->getUpdateEndpoint();
        if (is_string($endpointOverride) && !empty($endpointOverride)) {
            $url = $endpointOverride;
        }

        $server_info = null;
        if ($wpdb->use_mysqli) {
            $server_info = mysqli_get_server_info($wpdb->dbh);
        } else {
            $server_info = mysql_get_server_info($wpdb->dbh);
        }
        
        \Puc_v4_Factory::buildUpdateChecker(
            add_query_arg(
                [
                    'wcexcptm_update_action' => 'get_metadata',
                    'wcexcptm_cptitem_unique_id' => $this->productUniqueId,
                    'domain' => $this->getHost(),
                    'productVersion' => $this->productVersion,
                    'welcartVersion' => USCES_VERSION,
                    'wordpressVersion' => $wp_version,
                    'phpVersion' => phpversion(),
                    'webServer' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '',
                    'databaseInfo' => $server_info,
                    'databaseVersion' => $wpdb->db_version(),
                ],
                $url . '/wp-update-server/'
            ),
            $this->pt_file,
            '',
            1
        );
    }

    /**
     * Gets the host name of the current server. The host name extracted via this method
     * MUST be the same as the domain registered by the client.
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string the domain name
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
                $url = 'http://'.$url;
            }
            $host = wp_parse_url($url, PHP_URL_HOST);
        }
        return trim($host);
    }
}
