<?php

function store_settings_defaults(): array
{
    return [
        'store_name' => 'سوپەرمارکێتی کوردستان',
        'store_address' => '',
        'store_phone' => '',
        'store_owner' => '',
        'store_logo' => '',
    ];
}

function load_store_settings(): array
{
    $path = dirname(__DIR__) . '/data/store-settings.json';
    if (!is_file($path)) {
        return store_settings_defaults();
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to read store settings.');
    }

    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock store settings for reading.');
    }

    $json = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if ($json === false) {
        throw new RuntimeException('Unable to read store settings.');
    }

    $stored_settings = json_decode($json, true);
    if (!is_array($stored_settings)) {
        throw new RuntimeException('Store settings file contains invalid JSON.');
    }

    $defaults = store_settings_defaults();
    foreach ($defaults as $key => $default) {
        if (!isset($stored_settings[$key]) || !is_string($stored_settings[$key])) {
            $stored_settings[$key] = $default;
        }
    }

    return array_intersect_key($stored_settings, $defaults);
}

function save_store_settings(array $settings): void
{
    $defaults = store_settings_defaults();
    $normalized_settings = [];

    foreach ($defaults as $key => $default) {
        if (!isset($settings[$key]) || !is_string($settings[$key])) {
            throw new RuntimeException('Invalid store settings value.');
        }
        $normalized_settings[$key] = $settings[$key];
    }

    $json = json_encode($normalized_settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode store settings.');
    }

    $path = dirname(__DIR__) . '/data/store-settings.json';
    if (!is_dir(dirname($path)) || !is_writable(dirname($path)) || (is_file($path) && !is_writable($path))) {
        throw new RuntimeException('Store settings location is not writable.');
    }

    $handle = fopen($path, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Unable to save store settings.');
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock store settings for writing.');
    }

    $contents = $json . PHP_EOL;
    $written = 0;
    $length = strlen($contents);
    if (!ftruncate($handle, 0) || !rewind($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        throw new RuntimeException('Unable to prepare store settings file for writing.');
    }

    while ($written < $length) {
        $bytes = fwrite($handle, substr($contents, $written));
        if ($bytes === false || $bytes === 0) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Unable to save store settings.');
        }
        $written += $bytes;
    }

    $flushed = fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if (!$flushed) {
        throw new RuntimeException('Unable to flush store settings to disk.');
    }
}
