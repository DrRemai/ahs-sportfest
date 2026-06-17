<?php
declare(strict_types=1);

class Cache
{
    public static function get(string $key): mixed
    {
        if (!self::available()) return null;
        $val = apcu_fetch($key, $ok);
        return $ok ? $val : null;
    }

    public static function set(string $key, mixed $value, int $ttl = 60): bool
    {
        if (!self::available()) return false;
        return apcu_store($key, $value, $ttl);
    }

    public static function delete(string $key): bool
    {
        if (!self::available()) return false;
        return apcu_delete($key);
    }

    public static function deletePattern(string $prefix): void
    {
        if (!self::available()) return;
        $it = new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');
        foreach ($it as $item) apcu_delete($item['key']);
    }

public static function remember(string $key, int $ttl, callable $fn): mixed
{
    $cached = self::get($key);
    if ($cached !== null) {
        return $cached;
    }
    $value = $fn();
    self::set($key, $value, $ttl);
    return $value;
}

    public static function available(): bool
    {
        static $available = null;
        if ($available === null) {
            $available = extension_loaded('apcu');
        }
        return $available;
    }
}
