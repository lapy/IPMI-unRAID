<?php

require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_runtime.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_fan_profiles.php';

if (!defined('IPMI_MAIN_CONFIG_SCHEMA_VERSION')) {
    define('IPMI_MAIN_CONFIG_SCHEMA_VERSION', 2);
}

if (!defined('IPMI_FAN_CONFIG_SCHEMA_VERSION')) {
    define('IPMI_FAN_CONFIG_SCHEMA_VERSION', 2);
}

if (!defined('IPMI_BOARD_CONFIG_SCHEMA_VERSION')) {
    define('IPMI_BOARD_CONFIG_SCHEMA_VERSION', 2);
}

function ipmi_main_config_defaults() {
    return [
        'SCHEMA_VERSION' => IPMI_MAIN_CONFIG_SCHEMA_VERSION,
        'NETWORK' => 'disable',
        'IPADDR' => '',
        'USER' => '',
        'PASSWORD' => '',
        'OVERRIDE' => 'disable',
        'OBOARD' => '',
        'OMODEL' => '',
        'OCOUNT' => '0',
        'IGNORE' => '',
        'DIGNORE' => '',
        'DEVIGNORE' => '',
        'DEVS' => 'enable',
        'IPMILAN' => 'LAN',
        'IPMISELD' => 'disable',
        'IPMIPOLL' => '60',
        'LOCAL' => 'disable',
        'DASH' => 'disable',
        'LOADCFG' => 'disable',
    ];
}

function ipmi_fan_config_defaults() {
    return [
        'SCHEMA_VERSION' => IPMI_FAN_CONFIG_SCHEMA_VERSION,
        'FANCONTROL' => 'disable',
        'FANPOLL' => '6',
        'HDDPOLL' => '18',
        'HDDIGNORE' => '',
        'HARDDRIVES' => 'enable',
        'FANIP' => 'None',
    ];
}

function ipmi_ini_normalize($config, $defaults) {
    $config = is_array($config) ? $config : [];
    $normalized = $defaults;

    foreach ($config as $key => $value)
        $normalized[$key] = $value;

    $normalized['SCHEMA_VERSION'] = (string)ipmi_array_get($defaults, 'SCHEMA_VERSION', 1);
    return $normalized;
}

function ipmi_ini_to_string($config) {
    $lines = [];
    foreach ($config as $key => $value)
        $lines[] = $key.'='.$value;

    return implode(PHP_EOL, $lines).PHP_EOL;
}

function ipmi_load_main_config() {
    $path = ipmi_plugin_config_path('ipmi.cfg');
    $config = ipmi_read_ini_config($path);
    $defaults = ipmi_main_config_defaults();
    $normalized = ipmi_ini_normalize($config, $defaults);

    if ($normalized !== $config)
        ipmi_atomic_write($path, ipmi_ini_to_string($normalized));

    return $normalized;
}

function ipmi_load_fan_config() {
    $path = ipmi_plugin_config_path('fan.cfg');
    $config = ipmi_read_ini_config($path);
    $defaults = ipmi_fan_config_defaults();
    $normalized = ipmi_ini_normalize($config, $defaults);

    if ($normalized !== $config)
        ipmi_atomic_write($path, ipmi_ini_to_string($normalized));

    return $normalized;
}

function ipmi_save_board_config($board_json) {
    $path = ipmi_plugin_config_path('board.json');
    return ipmi_atomic_write($path, ipmi_json_pretty($board_json));
}

function ipmi_board_json_schema_version($board_json) {
    return intval(ipmi_array_get($board_json, 'schema_version', 0));
}

function ipmi_board_json_entries($board_json) {
    if (!is_array($board_json))
        return [];

    $entries = [];
    foreach ($board_json as $key => $value) {
        if (is_array($value))
            $entries[$key] = $value;
    }

    return $entries;
}

function ipmi_normalize_board_config($board, $board_model, $board_json) {
    $entries = ipmi_board_json_entries($board_json);
    $entries = ipmi_normalize_asrock_board_json($board, $board_model, $entries);

    return array_merge(['schema_version' => IPMI_BOARD_CONFIG_SCHEMA_VERSION], $entries);
}

function ipmi_load_board_config($board, $board_model) {
    $path = ipmi_plugin_config_path('board.json');
    $board_json = ipmi_read_json_config($path);

    if (empty($board_json))
        return [];

    $normalized = ipmi_normalize_board_config($board, $board_model, $board_json);
    if ($normalized !== $board_json)
        ipmi_save_board_config($normalized);

    return $normalized;
}

function ipmi_validate_board_config($board_json) {
    $errors = [];
    $entries = ipmi_board_json_entries($board_json);

    if (empty($entries))
        return ['board.json must contain at least one board entry.'];

    foreach ($entries as $board_key => $entry) {
        foreach (['raw', 'auto', 'full'] as $required_key) {
            if (!isset($entry[$required_key]) || trim((string)$entry[$required_key]) === '')
                $errors[] = $board_key.': missing required key '.$required_key.'.';
        }

        if (!isset($entry['fans']) || !is_array($entry['fans']) || empty($entry['fans']))
            $errors[] = $board_key.': fans must be a non-empty object.';
    }

    return $errors;
}

