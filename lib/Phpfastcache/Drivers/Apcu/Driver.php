<?php

/**
 *
 * This file is part of Phpfastcache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt and LICENCE files.
 *
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 * @author Contributors  https://github.com/PHPSocialNetwork/phpfastcache/graphs/contributors
 */

declare(strict_types=1);

namespace Phpfastcache\Drivers\Apcu;

use DateTime;
use Phpfastcache\Cluster\AggregatablePoolInterface;
use Phpfastcache\Core\Item\ExtendedCacheItemInterface;
use Phpfastcache\Core\Pool\TaggableCacheItemPoolTrait;
use Phpfastcache\Entities\DriverStatistic;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use Phpfastcache\Exceptions\PhpfastcacheLogicException;

/**
 * Class Driver
 * @package phpFastCache\Drivers
 * @method Config getConfig() Return the config object
 */
class Driver implements AggregatablePoolInterface
{
    use TaggableCacheItemPoolTrait;

    /**
     * @param string $key
     * @return string
     */
    protected function getStorageKey(string $key): string
    {
        return $this->getConfig()->getOptPrefix() . $key;
    }

    /**
     * @param int $format
     * @return \APCUIterator
     */
    protected function getStorageIterator(int $format): \APCUIterator
    {
        $prefix = $this->getConfig()->getOptPrefix();
        $search = $prefix !== '' ? '/^' . preg_quote($prefix, '/') . '/' : null;

        return new \APCUIterator($search, $format);
    }

    /**
     * @return bool
     */
    public function driverCheck(): bool
    {
        return extension_loaded('apcu') && ini_get('apc.enabled');
    }

    /**
     * @return DriverStatistic
     */
    public function getStats(): DriverStatistic
    {
        $stats = (array) apcu_cache_info();
        $date = (new DateTime())->setTimestamp($stats['start_time']);
        $numEntries = (int) $stats['num_entries'];
        $size = (int) $stats['mem_size'];

        if ($this->getConfig()->getOptPrefix() !== '') {
            $numEntries = 0;
            $size = 0;

            foreach ($this->getStorageIterator(APC_ITER_KEY | APC_ITER_MEM_SIZE) as $entry) {
                ++$numEntries;
                $size += (int) ($entry['mem_size'] ?? 0);
            }
        }

        return (new DriverStatistic())
            ->setData(implode(', ', array_keys($this->itemInstances)))
            ->setInfo(
                sprintf(
                    "The APCU cache is up since %s, and have %d item(s) in cache.\n For more information see RawData.",
                    $date->format(DATE_RFC2822),
                    $numEntries
                )
            )
            ->setRawData($stats)
            ->setSize($size);
    }

    /**
     * @return bool
     */
    protected function driverConnect(): bool
    {
        return true;
    }

    /**
     * @param ExtendedCacheItemInterface $item
     * @return ?array<string, mixed>
     */
    protected function driverRead(ExtendedCacheItemInterface $item): ?array
    {
        $data = apcu_fetch($this->getStorageKey($item->getKey()), $success);

        if ($success === false || !is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @param ExtendedCacheItemInterface $item
     * @return bool
     * @throws PhpfastcacheInvalidArgumentException
     * @throws PhpfastcacheLogicException
     */
    protected function driverWrite(ExtendedCacheItemInterface $item): bool
    {
        return (bool) apcu_store($this->getStorageKey($item->getKey()), $this->driverPreWrap($item), $item->getTtl());
    }

    /**
     * @param string $key
     * @param string $encodedKey
     * @return bool
     */
    protected function driverDelete(string $key, string $encodedKey): bool
    {
        return (bool) apcu_delete($this->getStorageKey($key));
    }

    /**
     * @return bool
     */
    protected function driverClear(): bool
    {
        if ($this->getConfig()->getOptPrefix() !== '') {
            $keys = [];

            foreach ($this->getStorageIterator(APC_ITER_KEY) as $entry) {
                if (isset($entry['key'])) {
                    $keys[] = $entry['key'];
                }
            }

            return $keys ? apcu_delete($keys) === [] : true;
        }

        return @apcu_clear_cache();
    }
}
