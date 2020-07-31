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
     * The name of the plugin/theme folder
     *
     * @var string
     */
    private $pt_slug;

    /**
     * Set plugin/theme information for updates
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $pt_file name of plugin/theme entry file *INCLUDING* absolute path
     * @param string $pt_slug name of the plugin/theme folder
     */
    public function __construct($pt_file, $pt_slug) {
        $this->pt_file = $pt_file;
        $this->pt_slug = $pt_slug;
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
     * @param string|null $endpointOverride Can be set to override the update endpoint URL
     * @return void
     */
    public function initUpdateChecker($endpointOverride = null) {
        $url = $this->getUpdateEndpoint();
        if (is_string($endpointOverride) && !empty($endpointOverride)) {
            $url = $endpointOverride;
        }
        \Puc_v4_Factory::buildUpdateChecker(
            add_query_arg(
                [
                    'public_update_action' => 'get_metadata',
                    'public_update_slug' => $this->pt_slug,
                ],
                $url . '/wp-update-server/'
            ),
            $this->pt_file,
            $this->pt_slug,
            1
        );
    }
}
