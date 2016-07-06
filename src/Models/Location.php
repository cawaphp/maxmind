<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types=1);

namespace Cawa\Maxmind\Models;

use Cawa\Orm\Model;

class Location extends Model
{
    /**
     * @var int
     */
    private $id;

    /**
     * @return int
     */
    public function getId() : int
    {
        return $this->id;
    }

    /**
     * @var string
     */
    private $continent;

    /**
     * @return string
     */
    public function getContinent() : string
    {
        return $this->continent;
    }

    /**
     * @var string
     */
    private $country;

    /**
     * @return string
     */
    public function getCountry() : string
    {
        return $this->country;
    }

    /**
     * @var int
     */
    private $subdivition1;

    /**
     * @return int
     */
    public function getSubdivition1() : int
    {
        return $this->subdivition1;
    }

    /**
     * @var int
     */
    private $subdivition2;

    /**
     * @return int
     */
    public function getSubdivition2() : int
    {
        return $this->subdivition2;
    }

    /**
     * @var string
     */
    private $city;

    /**
     * @return string
     */
    public function getCity() : string
    {
        return $this->city;
    }

    /**
     * @var int
     */
    private $metro;

    /**
     * @return int
     */
    public function getMetro() : int
    {
        return $this->metro;
    }

    /**
     * @var string
     */
    private $timezone;

    /**
     * @return string
     */
    public function getTimezone() : string
    {
        return $this->timezone;
    }

    /**
     * {@inheritdoc}
     */
    public function map(array $result)
    {
        $this->id = $result['location_id'];
        $this->continent = $result['location_continent'];
        $this->country = $result['location_country'];
        $this->subdivition1 = $result['location_subdivision_1'];
        $this->subdivition2 = $result['location_subdivision_2'];
        $this->city = $result['location_city'];
        $this->metro = $result['location_metro'];
        $this->timezone = $result['location_timezone'];
    }
}
