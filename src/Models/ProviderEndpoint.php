<?php

namespace Aivec\CptmClient\Models;

use JsonSerializable;

/**
 * Provider endpoint meta data
 */
class ProviderEndpoint implements JsonSerializable
{
    /**
     * URL of the selling site (where the plugin/theme was acquired)
     *
     * @var string
     */
    private $siteurl;

    /**
     * Commercial Plugin/Theme Manager URL (usually the same as `$siteurl`)
     *
     * @var string
     */
    private $apiurl;

    /**
     * Optional
     *
     * Defaults to the value of `$siteurl`
     *
     * @var string
     */
    private $displaytext;

    /**
     * Sets endpoint data for a provider
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $siteurl
     * @param string $apiurl
     * @param string $displaytext Optional. Defaults to the value of `$siteurl`
     * @return void
     */
    public function __construct($siteurl, $apiurl, $displaytext = '') {
        $this->siteurl = $siteurl;
        $this->apiurl = $apiurl;
        $this->displaytext = !empty($displaytext) ? $displaytext : $siteurl;
    }

    /**
     * Serializes for `json_encode()`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return array
     */
    public function jsonSerialize() {
        return [
            'siteurl' => $this->siteurl,
            'apiurl' => $this->apiurl,
            'displaytext' => $this->displaytext,
        ];
    }

    /**
     * Getter for `$siteurl`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string
     */
    public function getSiteUrl() {
        return $this->siteurl;
    }

    /**
     * Getter for `$apiurl`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string
     */
    public function getApiUrl() {
        return $this->apiurl;
    }

    /**
     * Getter for `$displaytext`
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return string
     */
    public function getDisplayText() {
        return $this->displaytext;
    }
}
