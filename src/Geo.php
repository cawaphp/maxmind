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

namespace Cawa\Maxmind;

use Cawa\Db\DatabaseFactory;
use Cawa\Maxmind\Models\Block;
use Cawa\Maxmind\Models\Location;
use Cawa\Net\Ip;

class Geo
{
    use DatabaseFactory;

    /**
     * @var Block
     */
    private $block;

    /**
     * @return Block
     */
    public function getBlock() : Block
    {
        return $this->block;
    }

    /**
     * @var Location
     */
    private $location;

    /**
     * @return Location
     */
    public function getLocation() : Location
    {
        return $this->location;
    }

    /**
     * @param string $ip
     *
     * @return $this|self|null
     */
    public static function getByIp(string $ip = null)
    {
        if (is_null($ip)) {
            $ip = Ip::get();
        }

        $db = self::db('MAXMIND');

        $sql = 'SELECT 
                    location_id,
                    location_continent,
                    location_country,
                    location_subdivision_1,
                    location_subdivision_2,
                    location_city,
                    location_metro,
                    location_timezone, 
                    block_start_ip,
                    block_end_ip,
                    block_anonymous_proxy,
                    block_satellite_provider,
                    block_postal_code,
                    block_latitude,
                    block_longitude
                FROM tbl_geo_location 
                INNER JOIN
                (
                    SELECT *
                    FROM tbl_geo_block 
                    WHERE block_start_ip >= INET_ATON(:ip) 
                    LIMIT 1
                ) AS r ON block_location_id = location_id
                AND INET_ATON(:ip) <= block_end_ip';
        if ($result = $db->fetchOne($sql, ['ip' => $ip])) {
            $return = new static();

            $return->location = new Location();
            $return->location->map($result);

            $return->block = new Block();
            $return->block->map($result);

            return $return;
        }

        return null;
    }
}
