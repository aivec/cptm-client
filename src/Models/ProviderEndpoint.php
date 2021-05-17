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
     * Sets endpoint data for a provider
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string $siteurl
     * @param string $apiurl
     * @return void
     */
    public function __construct($siteurl, $apiurl) {
        $this->siteurl = $siteurl;
        $this->apiurl = $apiurl;
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
}
