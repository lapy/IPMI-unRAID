<?php

if (!defined('IPMI_PLUGIN_NAME')) {
    define('IPMI_PLUGIN_NAME', 'ipmi');
}

if (!defined('IPMI_PLUGIN_CONFIG_DIR')) {
    define('IPMI_PLUGIN_CONFIG_DIR', '/boot/config/plugins/'.IPMI_PLUGIN_NAME);
}

function ipmi_array_get($array, $key, $default=null) {
    return (is_array($array) && array_key_exists($key, $array)) ? $array[$key] : $default;
}

function ipmi_join_paths(...$parts) {
    $clean = [];
    foreach ($parts as $index => $part) {
        $part = (string)$part;
        if ($part === '')
            continue;

        $clean[] = ($index === 0) ? rtrim($part, '/') : trim($part, '/');
    }

    if (empty($clean))
        return '';

    return implode('/', $clean);
}

function ipmi_plugin_config_path($filename='') {
    return empty($filename) ? IPMI_PLUGIN_CONFIG_DIR : ipmi_join_paths(IPMI_PLUGIN_CONFIG_DIR, $filename);
}

function ipmi_decode_secret($value) {
    $value = (string)$value;
    if ($value === '')
        return '';

    $decoded = base64_decode($value, true);
    return ($decoded === false) ? $value : $decoded;
}

function ipmi_merge_args(...$chunks) {
    $args = [];

    foreach ($chunks as $chunk) {
        if ($chunk === null)
            continue;

        if (is_array($chunk)) {
            foreach ($chunk as $value) {
                if ($value === null || $value === '')
                    continue;
                $args[] = (string)$value;
            }
            continue;
        }

        $value = trim((string)$chunk);
        if ($value === '')
            continue;

        $args[] = $value;
    }

    return $args;
}

function ipmi_stringify_args($args) {
    $escaped = [];
    foreach (ipmi_merge_args($args) as $arg)
        $escaped[] = escapeshellarg($arg);

    return implode(' ', $escaped);
}

function ipmi_simple_tokens($value) {
    $value = trim((string)$value);
    if ($value === '')
        return [];

    return preg_split('/\s+/', $value);
}

function ipmi_hex_tokens($value) {
    return ipmi_simple_tokens($value);
}

function ipmi_ensure_directory($directory) {
    if (empty($directory) || is_dir($directory))
        return true;

    return @mkdir($directory, 0777, true);
}

function ipmi_build_backup_path($path) {
    return sprintf('%s.bak.%s', $path, date('YmdHis'));
}

function ipmi_atomic_write($path, $contents, $create_backup=true) {
    $directory = dirname($path);
    if (!ipmi_ensure_directory($directory))
        return false;

    $temp_path = tempnam($directory, 'ipmi.');
    if ($temp_path === false)
        return false;

    if (file_put_contents($temp_path, $contents) === false) {
        @unlink($temp_path);
        return false;
    }

    if ($create_backup && file_exists($path)) {
        $backup_path = ipmi_build_backup_path($path);
        @copy($path, $backup_path);
    }

    if (!@rename($temp_path, $path)) {
        @unlink($temp_path);
        return false;
    }

    return true;
}

function ipmi_read_ini_config($path, $process_sections=false) {
    if (!is_file($path))
        return [];

    $data = @parse_ini_file($path, $process_sections);
    return is_array($data) ? $data : [];
}

function ipmi_read_json_config($path) {
    if (!is_file($path))
        return [];

    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '')
        return [];

    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function ipmi_json_pretty($data) {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function ipmi_read_pidfile($pidfile) {
    if (!is_file($pidfile))
        return 0;

    $pid = intval(trim((string)@file_get_contents($pidfile)));
    return ($pid > 0) ? $pid : 0;
}

function ipmi_is_pid_running($pid) {
    $pid = intval($pid);
    if ($pid < 1)
        return false;

    if (function_exists('posix_kill'))
        return @posix_kill($pid, 0);

    return is_dir('/proc/'.$pid);
}

function ipmi_is_service_running($pidfile) {
    $pid = ipmi_read_pidfile($pidfile);
    if ($pid < 1)
        return false;

    if (ipmi_is_pid_running($pid))
        return true;

    @unlink($pidfile);
    return false;
}

function ipmi_write_pidfile($pidfile, $pid=null) {
    $pid = ($pid === null) ? getmypid() : intval($pid);
    return ipmi_atomic_write($pidfile, $pid.PHP_EOL, false);
}

function ipmi_stop_service($pidfile) {
    $pid = ipmi_read_pidfile($pidfile);
    if ($pid < 1)
        return false;

    exec('kill '.intval($pid), $output, $return_var);
    @unlink($pidfile);
    return ($return_var === 0);
}

function ipmi_run_command($command, $capture_stderr=true) {
    $output = [];
    $return_var = 0;

    if ($capture_stderr && strpos($command, '2>') === false)
        $command .= ' 2>&1';

    exec($command, $output, $return_var);

    return [
        'command' => $command,
        'output' => $output,
        'text' => implode(PHP_EOL, $output),
        'exit_code' => $return_var,
        'success' => ($return_var === 0),
    ];
}

function ipmi_run_process($binary, $args=[], $capture_stderr=true) {
    return ipmi_run_command(ipmi_stringify_args(ipmi_merge_args([$binary], $args)), $capture_stderr);
}

function ipmi_last_token($value) {
    $tokens = ipmi_simple_tokens($value);
    if (empty($tokens))
        return '';

    return (string)$tokens[count($tokens) - 1];
}

function ipmi_build_freeipmi_args($host, $user, $password, $driver='LAN', $always_prefix=false) {
    $args = [];

    if ($always_prefix)
        $args[] = '--always-prefix';

    if (trim((string)$host) !== '') {
        $args[] = '-h';
        $args[] = (string)$host;
    }

    if (trim((string)$user) !== '') {
        $args[] = '-u';
        $args[] = (string)$user;
    }

    if ((string)$password !== '') {
        $args[] = '-p';
        $args[] = (string)$password;
    }

    $args[] = '--session-timeout=5000';
    $args[] = '--retransmission-timeout=1000';

    if ($driver !== null && trim((string)$driver) !== '') {
        $args[] = '-D';
        $args[] = (string)$driver;
    }

    return $args;
}

function ipmi_parse_key_value_output($text, $separator=':') {
    $values = [];
    $lines = preg_split("/\r?\n/", (string)$text);

    foreach ($lines as $line) {
        $parts = explode($separator, $line, 2);
        if (count($parts) !== 2)
            continue;

        $key = trim($parts[0]);
        if ($key === '')
            continue;

        $values[$key] = trim($parts[1]);
    }

    return $values;
}

function ipmi_read_lscpu_field($field) {
    $result = ipmi_run_process('/usr/bin/lscpu');
    if (!$result['success'])
        return '';

    $values = ipmi_parse_key_value_output($result['text']);
    return ipmi_array_get($values, $field, '');
}

function ipmi_parse_dmidecode_records($text) {
    $records = [];
    $record = [];
    $lines = preg_split("/\r?\n/", (string)$text);

    foreach ($lines as $line) {
        if (preg_match('/^Handle /', $line)) {
            if (!empty($record))
                $records[] = $record;
            $record = [];
            continue;
        }

        if (preg_match('/^\s*([^:]+):\s*(.*)$/', $line, $matches))
            $record[trim($matches[1])] = trim($matches[2]);
    }

    if (!empty($record))
        $records[] = $record;

    return $records;
}

function ipmi_read_dmidecode_records($type) {
    $result = ipmi_run_process('dmidecode', ['-t', (string)$type]);
    if (!$result['success'])
        return [];

    return ipmi_parse_dmidecode_records($result['text']);
}

function ipmi_read_dmi_field($type, $field, $record_index=0) {
    $records = ipmi_read_dmidecode_records($type);
    if (empty($records))
        return '';

    if ($record_index < 0)
        $record_index = count($records) + intval($record_index);

    if (!array_key_exists($record_index, $records))
        return '';

    return trim((string)ipmi_array_get($records[$record_index], $field, ''));
}

function ipmi_parse_dynamix_config() {
    if (!function_exists('parse_plugin_cfg'))
        return [];

    $config = parse_plugin_cfg('dynamix', true);
    return is_array($config) ? $config : [];
}

function ipmi_get_display_preferences() {
    $config = ipmi_parse_dynamix_config();
    $display = ipmi_array_get($config, 'display', []);

    return is_array($display) ? $display : [];
}

function ipmi_validate_csrf_token($token) {
    $token = trim((string)$token);
    if ($token === '')
        return false;

    if (function_exists('csrf_token'))
        return hash_equals((string)csrf_token(), $token);

    if (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && !empty($GLOBALS['var']['csrf_token']))
        return hash_equals((string)$GLOBALS['var']['csrf_token'], $token);

    return true;
}

function ipmi_require_post_request() {
    if (strtoupper((string)ipmi_array_get($_SERVER, 'REQUEST_METHOD', 'GET')) !== 'POST')
        ipmi_json_response(false, 'POST is required for this endpoint.', [], ['invalid_method']);
}

function ipmi_require_csrf() {
    $token = ipmi_array_get($_POST, 'csrf_token', '');
    if (!ipmi_validate_csrf_token($token))
        ipmi_json_response(false, 'CSRF token is missing or invalid.', [], ['invalid_csrf']);
}

function ipmi_json_response($ok, $message='', $data=[], $errors=[]) {
    if (!headers_sent())
        header('Content-Type: application/json');

    echo json_encode([
        'ok' => (bool)$ok,
        'message' => (string)$message,
        'data' => is_array($data) ? $data : ['value' => $data],
        'errors' => array_values(is_array($errors) ? $errors : [$errors]),
    ]);
    exit($ok ? 0 : 1);
}
