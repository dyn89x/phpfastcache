<?php

declare(strict_types=1);

use Phpfastcache\CacheManager;
use Phpfastcache\Drivers\Apcu\Config;

require_once __DIR__ . '/../../vendor/autoload.php';

if (!extension_loaded('apcu') || !apcu_enabled()) {
    echo "SKIP: APCu must be enabled (use -d apc.enable_cli=1).\n";
    exit(2);
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$id = 'prefix-test-' . bin2hex(random_bytes(8));
$prefix = $id . '.[a]/:';
$otherPrefix = $id . '.a/:';
$config = new Config(['optPrefix' => ' ' . $prefix . ' ']);
$pool = CacheManager::getInstance('Apcu', $config);
$otherPool = CacheManager::getInstance('Apcu', new Config(['optPrefix' => $otherPrefix]));
$unprefixedKey = $id . '-unprefixed';

try {
    $check($config->getOptPrefix() === $prefix, 'Prefix must be trimmed.');
    $check((new Config())->getOptPrefix() === '', 'Default prefix must remain empty.');

    $check($pool->save($pool->getItem('shared')->set('first')->expiresAfter(60)), 'First save failed.');
    $check($otherPool->save($otherPool->getItem('shared')->set('second')->expiresAfter(60)), 'Second save failed.');
    apcu_store($unprefixedKey, 'untouched', 60);
    $check(apcu_exists($prefix . 'shared'), 'First storage key must include its prefix.');
    $check(apcu_exists($otherPrefix . 'shared'), 'Second storage key must include its prefix.');

    // Recreate pools to exercise storage reads rather than in-memory items.
    CacheManager::clearInstances();
    $pool = CacheManager::getInstance('Apcu', new Config(['optPrefix' => $prefix]));
    $otherPool = CacheManager::getInstance('Apcu', new Config(['optPrefix' => $otherPrefix]));
    $check($pool->getItem('shared')->get() === 'first', 'First namespace was overwritten.');
    $check($otherPool->getItem('shared')->get() === 'second', 'Second namespace was overwritten.');

    $stats = $pool->getStats();
    $check(strpos($stats->getInfo(), 'have 1 item(s)') !== false, 'Statistics must count only the selected prefix.');
    $size = 0;
    foreach (new APCUIterator('/^' . preg_quote($prefix, '/') . '/', APC_ITER_MEM_SIZE) as $entry) {
        $size += $entry['mem_size'];
    }
    $check($size > 0 && $stats->getSize() === $size, 'Statistics must measure only the selected prefix.');

    $check($pool->deleteItem('shared'), 'Delete failed.');
    $check(!apcu_exists($prefix . 'shared'), 'Delete must remove the prefixed key.');
    $check(apcu_exists($otherPrefix . 'shared'), 'Delete affected another namespace.');
    $pool->save($pool->getItem('again')->set('value')->expiresAfter(60));
    $check($pool->clear(), 'Clear failed.');
    $check(!apcu_exists($prefix . 'again'), 'Clear must remove keys in its namespace.');
    $check(apcu_exists($otherPrefix . 'shared'), 'Clear treated prefix characters as a regular expression.');
    $check(apcu_fetch($unprefixedKey) === 'untouched', 'Clear affected an unprefixed entry.');
    $check($pool->clear(), 'Clearing an empty namespace must succeed.');
    $check($pool->getStats()->getSize() === 0, 'Cleared namespace must have zero size.');

    $defaultPool = CacheManager::getInstance('Apcu', new Config());
    $check($defaultPool->save($defaultPool->getItem($unprefixedKey)->set('default')->expiresAfter(60)), 'Default save failed.');
    $check(apcu_exists($unprefixedKey), 'Default storage key must remain unprefixed.');
    $check($defaultPool->deleteItem($unprefixedKey), 'Default delete failed.');
    $check(!apcu_exists($unprefixedKey), 'Default delete must remove the unprefixed key.');

    echo "OK: {$checks} APCu prefix checks passed.\n";
} finally {
    $pool->clear();
    $otherPool->clear();
    apcu_delete($unprefixedKey);
    CacheManager::clearInstances();
}
