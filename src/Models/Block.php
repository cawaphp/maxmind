<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types = 1);

namespace Cawa\Maxmind\Models;

use Cawa\Orm\Model;

class Block extends Model
{
    /**
     * @var bool
     */
    private $anonymousProxy;

    /**
     * @return bool
     */
    public function isAnonymousProxy() : bool
    {
        return $this->anonymousProxy;
    }

    /**
     * @var bool
     */
    private $satelliteProvider;

    /**
     * @return bool
     */
    public function isSatelliteProvider() : bool
    {
        return $this->satelliteProvider;
    }

    /**
     * @var string
     */
    private $postalCode;

    /**
     * @return string
     */
    public function getPostalCode() : string
    {
        return $this->postalCode;
    }

    /**
     * @var float
     */
    private $latitude;

    /**
     * @return float
     */
    public function getLatitude() : float
    {
        return $this->latitude;
    }

    /**
     * @var float
     */
    private $longitude;

    /**
     * @return float
     */
    public function getLongitude() : float
    {
        return $this->longitude;
    }

    /**
     * @param array $result
     */
    public function map(array $result)
    {
        $this->anonymousProxy = (bool) $result['block_anonymous_proxy'] ;
        $this->satelliteProvider = (bool) $result['block_satellite_provider'];
        $this->postalCode = $result['block_postal_code'];
        $this->latitude = $result['block_latitude'];
        $this->longitude = $result['block_longitude'];
    }
}
