<?php

namespace Aivec\CptmClient\Views;

use Aivec\CptmClient\Client;
use Aivec\CptmClient\ServerControlled;
use Aivec\Plugins\EnvironmentSwitcher;

/**
 * Methods for creating a settings page for provider selection
 */
class SettingsPage
{
    const PAGE_PREFIX = 'cptmc_settings_page_';

    /**
     * `Client` instance
     *
     * @var Client
     */
    public $client;

    /**
     * Plugin/theme name
     *
     * @var string
     */
    public $ptname;

    /**
     * Settings page slug
     *
     * @var string
     */
    private $page;

    /**
     * Injects client
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param Client $client
     * @param string $ptname Name of the plugin/theme. Displayed in the settings tab and at the top of the settings page
     * @return void
     */
    public function __construct(Client $client, $ptname) {
        $this->client = $client;
        $this->ptname = $ptname;
        $this->page = self::PAGE_PREFIX . $this->client->getItemUniqueId();
    }

    /**
     * Creates settings page
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function createProviderSelectSettingsPage() {
        add_action('admin_menu', [$this, 'registerSettingsPage']);
        add_action('admin_init', [$this, 'registerSetting']);
    }

    /**
     * Registers `cptmc_selected_provider_<itemUniqueId>` option name
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function registerSetting() {
        register_setting($this->page, $this->client->selectedProviderOptName);
        register_setting($this->page, $this->client->updateUrlOverrideOptName);
        if (!($this->client instanceof ServerControlled)) {
            return;
        }
        register_setting($this->page, $this->client->providersUrlOverrideOptName);
    }

    /**
     * Adds settings page
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function registerSettingsPage() {
        add_options_page(
            $this->ptname,
            $this->ptname,
            'manage_options',
            $this->page,
            [$this, 'addSettingsPage']
        );
    }

    /**
     * Adds `<ptname> Settings` page
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function addSettingsPage() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        ?>
        <div class="wrap">
            <h1><?php echo $this->ptname . ' ' . __('Settings', 'cptmc'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields($this->page); ?>
                <table class="form-table" role="presentation">
                    <?php $this->selectProviderSection(); ?>
                    <?php $this->providersUrlOverrideSection(); ?>
                    <?php $this->updateUrlOverrideSection(); ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Adds radio buttons for selecting a provider
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function selectProviderSection() {
        $selected = $this->client->getSelectedProvider();
        if ($selected !== null) {
            $selected = $selected->getIdentifier();
        }
        $providers = $this->client->getProviders();
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Please choose your provider', 'cptmc'); ?></th>
            <td>
                <fieldset>
                    <?php
                    if (empty($providers)) {
                        ?>
                        <p><?php esc_html_e('No providers available', 'cptmc'); ?></p>
                        <?php
                    } else {
                        foreach ($providers as $provider) {
                            ?>
                            <label>
                                <input
                                    name="<?php echo $this->client->selectedProviderOptName; ?>"
                                    type="radio"
                                    id="cpt_provider_<?php echo esc_attr($this->client->getItemUniqueId() . $provider->getIdentifier()); ?>"
                                    value="<?php echo esc_attr($provider->getIdentifier()); ?>"
                                    <?php echo $selected === $provider->getIdentifier() ? 'checked' : ''; ?>
                                />
                                <?php echo $this->client->getProviderEndpoint($provider)->getDisplayText(); ?>
                            </label>
                            <br />
                            <?php
                        }
                    }
                    ?>
                </fieldset>
            </td>
        </tr>
        <?php
    }

    /**
     * Adds the providers URL override settings section if in a development environment
     * and using a `ServerControlled` client instance
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function providersUrlOverrideSection() {
        if (!($this->client instanceof ServerControlled)) {
            return;
        }
        if (EnvironmentSwitcher\Utils::getEnv() !== 'development') {
            return;
        }
        $optname = $this->client->providersUrlOverrideOptName;
        ?>
        <tr>
            <th scope="row">
                <label for="<?php echo $optname; ?>">
                    <?php esc_html_e('Testing Providers URL', 'cptmc'); ?>
                </label>
            </th>
            <td>
                <input
                    type="text"
                    name="<?php echo $optname; ?>"
                    id="<?php echo $optname; ?>"
                    aria-describedby="tagline-description-1"
                    value="<?php form_option($optname); ?>"
                    class="regular-text"
                />
                <p class="description" id="tagline-description-1">
                    <?php esc_html_e('This field only applies to a testing environment', 'cptmc'); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * Adds the update URL override settings section if in a development environment
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return void
     */
    public function updateUrlOverrideSection() {
        if (EnvironmentSwitcher\Utils::getEnv() !== 'development') {
            return;
        }
        $optname = $this->client->updateUrlOverrideOptName;
        ?>
        <tr>
            <th scope="row">
                <label for="<?php echo $optname; ?>">
                    <?php esc_html_e('Testing Update URL', 'cptmc'); ?>
                </label>
            </th>
            <td>
                <input
                    type="text"
                    name="<?php echo $optname; ?>"
                    id="<?php echo $optname; ?>"
                    aria-describedby="tagline-description-2"
                    value="<?php form_option($optname); ?>"
                    class="regular-text"
                />
                <p class="description" id="tagline-description-2">
                    <?php esc_html_e('This field only applies to a testing environment', 'cptmc'); ?>
                </p>
            </td>
        </tr>
        <?php
    }
}
