<?php

namespace Aivec\CptmClient;

use Aivec\CptmClient\Models\Provider;
use InvalidArgumentException;

/**
 * Consumes list of providers and default selections **directly as parameters**
 */
class ClientControlled extends Client
{
    /**
     * Array of providers
     *
     * @var Provider[]
     */
    public $providers;

    /**
     * Set plugin/theme information for updates
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @param string     $itemUniqueId {@see Client::__construct()}
     * @param string     $itemVersion {@see Client::__construct()}
     * @param string     $ptpath {@see Client::__construct()}
     * @param Provider[] $providers List of `Provider` instances
     * @return void
     * @throws InvalidArgumentException Thrown if `$providers` contains any invalid values.
     */
    public function __construct($itemUniqueId, $itemVersion, $ptpath, array $providers) {
        parent::__construct($itemUniqueId, $itemVersion, $ptpath);
        if (empty($providers)) {
            throw new InvalidArgumentException('providers must contain at least one Provider instance');
        }
        $index = 0;
        $idents = [];
        foreach ($providers as $provider) {
            if (!($provider instanceof Provider)) {
                throw new InvalidArgumentException(
                    "provider at index {$index} is not an instance of `Aivec\CptmClient\Models\Provider`"
                );
            }
            if (in_array($provider->getIdentifier(), $idents, true)) {
                throw new InvalidArgumentException(
                    'The unique identifier "' . $provider->getIdentifier() . '" is used more than once'
                );
            }
            $idents[] = $provider->getIdentifier();
            $index++;
        }
        $this->providers = $providers;
    }

    /**
     * Returns list of providers
     *
     * @author Evan D Shaw <evandanielshaw@gmail.com>
     * @return Provider[]|null
     */
    public function getProviders() {
        return $this->providers;
    }
}
