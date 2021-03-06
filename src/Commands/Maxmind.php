<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Cawa\Maxmind\Commands;

use Cawa\Console\Command;
use Cawa\Db\DatabaseFactory;
use Cawa\Db\TransactionDatabase;
use Cawa\HttpClient\Adapter\AbstractClient;
use Cawa\HttpClient\HttpClient;
use Cawa\HttpClient\HttpClientFactory;
use Cawa\Maxmind\Geo;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Maxmind extends Command
{
    use DatabaseFactory;
    use HttpClientFactory;

    const TYPE_BLOCK = 'BLOCK';
    const TYPE_LOCATION = 'LOCATION';

    /**
     *
     */
    protected function configure()
    {
        $this->setName('maxmind:load')
            ->setDescription('Load maxmind lite database')
            ->addOption('db', 'd', InputOption::VALUE_REQUIRED, 'Database alias')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $db = $input->getOption('db');

        if (!$db) {
            $db = Geo::class;
        }

        $db = self::db($db);

        if (!($zipFile = $this->download())) {
            return 1;
        }

        if (!$this->normalize($zipFile)) {
            return 1;
        }

        if (!$this->load($db)) {
            return 1;
        }

        @unlink($zipFile);

        return 0;
    }

    /**
     * @return string
     */
    private function download()
    {
        $client = self::httpClient(self::class);

        // file download
        $this->output->writeln('Downloading database');

        $progress = new ProgressBar($this->output, 100);
        $progress->setMessage('0', 'currentsize');
        $progress->setMessage('???', 'totalsize');
        $progress->setFormat(
            '<comment>[%bar%]</comment> %currentsize:6s% mo/%totalsize:6s% mo ' .
            '<info>%percent:3s%%</info> %elapsed:6s%/%estimated:-6s%'
        );
        if ($this->output->isVerbose()) {
            $progress->start();
        }

        $client->getClient()
            ->setOption(AbstractClient::OPTIONS_TIMEOUT, false)
            ->setOption(AbstractClient::OPTIONS_ACCEPT_ENCODING, false)
            ->setProgress(function (
                $resource,
                $download_size,
                $downloaded,
                $upload_size,
                $uploaded
            ) use ($progress) {
                if ($download_size > 0 && $this->output->isVerbose()) {
                    $progress->setMessage((string) round($downloaded / 1024 / 1024, 3), 'currentsize');
                    $progress->setMessage((string) round($download_size / 1024 / 1024, 3), 'totalsize');

                    $progress->setProgress((int) floor($downloaded * 100 / $download_size));
                }
            });

        $zipFile = $client->get('http://geolite.maxmind.com/download/geoip/database/GeoLite2-City-CSV.zip');

        if ($this->output->isVerbose()) {
            $progress->finish();
            $this->output->write("\n");
        }

        if (strpos($zipFile->getBody(), "\n") === false) {
            $this->output->writeln(sprintf("<error>Invalid zip with content '%s'</error>", $zipFile->getBody()));

            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'maxmind');
        file_put_contents($tmp, $zipFile->getBody());
        $this->output->writeln(sprintf("Created tmp file with csv at '%s'", $tmp), OutputInterface::VERBOSITY_VERBOSE);

        return $tmp;
    }

    /**
     * @param string $zipFile
     *
     * @return bool
     */
    private function normalize(string $zipFile)
    {
        $zip = new \ZipArchive();
        $error = $zip->open($zipFile);

        if ($error !== true) {
            $reflection = new \ReflectionClass('ZipArchive');

            foreach ($reflection->getConstants() as $key => $value) {
                if (strpos($key, 'ER_') === 0 && $value == $error) {
                    $error = $key;
                    break;
                }
            }

            $this->output->writeln(sprintf("<error>Invalid zip '%s'</error>", $error));

            return false;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $file = $zip->statIndex($i);

            if (stripos($file['name'], '-Locations-') !== false) {
                $this->readfile($zip, $file, self::TYPE_LOCATION);
            } elseif (stripos($file['name'], '-Blocks-IPv4.') !== false) {
                $this->readfile($zip, $file, self::TYPE_BLOCK);
            }
        }

        return true;
    }

    const LOCATION_COLUMNS = [
        0 => 'location_id',
        2 => 'location_continent',
        4 => 'location_country',
        6 => 'location_subdivision_1',
        8 => 'location_subdivision_2',
        10 => 'location_city',
        11 => 'location_metro',
        12 => 'location_timezone',
    ];

    const BLOCK_COLUMNS = [
        0 => 'block_start_ip',
        1 => 'block_end_ip',
        2 => 'block_network',
        3 => 'block_location_id',
        4 => 'block_registered_country_location_id',
        5 => 'block_represented_country_location_id',
        6 => 'block_anonymous_proxy',
        7 => 'block_satellite_provider',
        8 => 'block_postal_code',
        9 => 'block_latitude',
        10 => 'block_longitude',
    ];

    /**
     * @var array
     */
    private $langFile = [];

    /**
     * @var array
     */
    private $location = [];

    /**
     * @var array
     */
    private $block = [];

    /**
     * @param \ZipArchive $zip
     * @param array $file
     * @param string $type
     *
     * @return bool
     */
    private function readfile(\ZipArchive $zip, array $file, string $type) : bool
    {
        $this->output->writeln('Reading ' . $file['name']);

        $lang = null;
        if ($type == self::TYPE_LOCATION) {
            preg_match('`Locations-([a-zA-Z-]+).csv`', $file['name'], $matches);
            $lang = $matches[1];
        }

        $progress = new ProgressBar($this->output, 100);
        $progress->setMessage((string) 0, 'currentsize');
        $progress->setMessage((string) round($file['size'] / 1024 / 1024, 3), 'totalsize');
        $progress->setFormat(
            '<comment>[%bar%]</comment> %currentsize:7s% mo/%totalsize:7s% mo ' .
            '<info>%percent:3s%%</info> %elapsed:6s%/%estimated:-6s%'
        );
        if ($this->output->isVerbose()) {
            $progress->start();
        }

        $handle = $zip->getStream($file['name']);
        $cols = [];

        while (($row = fgetcsv($handle, null, ',')) !== false) {
            $current = ftell($handle);

            if ($this->output->isVerbose()) {
                $progress->setMessage((string) round(ftell($handle) / 1024 / 1024, 3), 'currentsize');
                $progress->setProgress((int) floor($current * 100 / $file['size']));
            }

            // avoid first line
            if (sizeof($cols) == 0) {
                $cols = $row;
            } else {
                $item = [];

                // block, add ip num
                if ($type == self::TYPE_BLOCK) {
                    list($ip, $len) = explode('/', $row[0]);

                    if (($min = ip2long($ip)) !== false) {
                        $max = ($min | (1 << (32 - $len)) - 1);
                    }

                    array_unshift($row, $min, $max);

                    if (empty($row[6])) {
                        $row[6] = 0;
                    }

                    if (empty($row[7])) {
                        $row[7] = 0;
                    }
                }

                // keep wanted cols
                $keys = $type == self::TYPE_LOCATION ? self::LOCATION_COLUMNS : self::BLOCK_COLUMNS;
                foreach ($keys as $index => $name) {
                    $item[] = $row[$index] === '' ? null : $row[$index];
                }

                if ($type == self::TYPE_LOCATION && $lang == 'en') {
                    $this->location[] = $item;
                } elseif ($type == self::TYPE_BLOCK) {
                    $this->block[] = $item;
                }

                if ($type == self::TYPE_LOCATION) {
                    if (!empty($row[2])) {
                        $this->langFile['continent'][$lang][$row[2]] = $row[3] ?: null;
                    }

                    if (!empty($row[4])) {
                        $this->langFile['country'][$lang][$row[4]] = $row[5] ?: null;
                    }

                    if (!empty($row[4]) && !empty($row[6])) {
                        $this->langFile['subdivision'][$lang][$row[4]]['division1'][$row[6]] = $row[7] ?: null;
                    }

                    if (!empty($row[4]) && !empty($row[6]) && !empty($row[8])) {
                        $this->langFile['subdivision'][$lang][$row[4]]['division2'][$row[6]][$row[8]] = $row[9] ?: null;
                    }
                }
            }
        }

        fclose($handle);

        if ($this->output->isVerbose()) {
            $progress->finish();
            $this->output->write("\n");
        }

        return true;
    }

    /**
     * @param TransactionDatabase $db
     *
     * @return bool
     */
    private function load(TransactionDatabase $db) : bool
    {
        $db->startTransaction();

        $db->query('DELETE FROM tbl_geo_block');
        $db->query('DELETE FROM tbl_geo_location');

        $this->loadTable($db, self::TYPE_LOCATION);
        $this->loadTable($db, self::TYPE_BLOCK);

        $db->commit();

        return true;
    }

    /**
     * @param TransactionDatabase $db
     * @param string $type
     *
     * @return bool
     */
    private function loadTable(TransactionDatabase $db, string $type) : bool
    {
        $type = strtolower($type);
        $insertSize = 5000;
        $insertTotal = sizeof($this->$type);

        $this->output->writeln('Loading ' . $type);

        $sql = 'INSERT INTO tbl_geo_' . strtolower($type);
        $sql .= '(' . implode(', ', constant('self::' . strtoupper($type) . '_COLUMNS')) . ')';
        $sql .= ' VALUES ';

        $rowFormat = function (&$col) {
            if (is_null($col)) {
                $col = 'NULL';
            } elseif (!is_numeric($col)) {
                $col = "'" . str_replace("'", "\\'", $col) . "'";
            }
        };

        $progress = new ProgressBar($this->output, 100);
        $progress->setMessage((string) 0, 'currentsize');
        $progress->setMessage((string) $insertTotal, 'totalsize');
        $progress->setFormat(
            '<comment>[%bar%]</comment> %currentsize:6s% mo/%totalsize:6s% mo ' .
            '<info>%percent:3s%%</info> %elapsed:6s%/%estimated:-6s%'
        );

        if ($this->output->isVerbose()) {
            $progress->start();
        }

        foreach (array_chunk($this->$type, $insertSize) as $i => $blocks) {
            $chunkSql = $sql;
            foreach ($blocks as $row) {
                array_walk($row, $rowFormat);
                $chunkSql .= '(' . implode(', ', $row) . '),';
            }

            if ($this->output->isVerbose()) {
                $progress->setMessage((string) ($insertSize * $i), 'currentsize');
                $progress->setProgress((int) floor($insertSize * $i * 100 / $insertTotal));
            }

            $db->query(substr($chunkSql, 0, -1));
        }

        if ($this->output->isVerbose()) {
            $progress->finish();
            $this->output->write("\n");
        }

        return true;
    }
}
