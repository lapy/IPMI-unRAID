<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_check.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_config_store.php';

$usage = <<<EOF

Usage: ipmi_config.php [options]

  -c, --commit     commit saved config to the BMC
  -s, --sensors    use the sensor config
      --help       display this help and exit
      --version    output version information and exit

EOF;

if (PHP_SAPI === 'cli') {
    $shortopts = 'cs';
    $longopts = ['commit', 'sensors', 'help', 'version'];
    $args = getopt($shortopts, $longopts);

    if (array_key_exists('help', $args)) {
        echo $usage.PHP_EOL;
        exit(0);
    }

    if (array_key_exists('version', $args)) {
        echo 'IPMI Sensors Config: 2.0'.PHP_EOL;
        exit(0);
    }
} else {
    ipmi_require_post_request();
    ipmi_require_csrf();
    $args = [];
}

$arg_commit = (array_key_exists('c', $args) || array_key_exists('commit', $args));
$arg_sensors = (array_key_exists('s', $args) || array_key_exists('sensors', $args));
$config_id = intval(ipmi_array_get($_POST, 'config', -1));
$commit = (bool)ipmi_array_get($_POST, 'commit', false) || $arg_commit;

function ipmi_config_editor_response($config_file, $message='Configuration loaded.') {
    $config_text = is_file($config_file) ? file_get_contents($config_file) : '';
    ipmi_json_response(true, $message, ['config' => $config_text]);
}

if ($config_id === 2) {
    $config_file = ipmi_plugin_config_path('board.json');
    $config = str_replace("\r", '', (string)ipmi_array_get($_POST, 'ipmicfg', ''));

    if ($commit) {
        $decoded = json_decode($config, true);
        if (!is_array($decoded))
            ipmi_json_response(false, 'board.json is not valid JSON.', [], [json_last_error_msg()]);

        $normalized = ipmi_normalize_board_config($board, $board_model, $decoded);
        $errors = ipmi_validate_board_config($normalized);
        if (!empty($errors))
            ipmi_json_response(false, 'board.json validation failed.', [], $errors);

        if (!ipmi_save_board_config($normalized))
            ipmi_json_response(false, 'Unable to save board.json.', [], [$config_file]);

        ipmi_config_editor_response($config_file, 'board.json saved.');
    }

    if (!is_file($config_file)) {
        $example_board = ($board === 'ASRock' || $board === 'ASRockRack') ? $board : 'ASRockRack';
        ipmi_json_response(true, 'Using generated board.json example.', [
            'config' => ipmi_json_pretty(ipmi_get_asrock_board_json_example($example_board, $board_model)),
        ]);
    }

    ipmi_config_editor_response($config_file);
}

$cmd_suffix = ($arg_sensors || $config_id === 1) ? '-sensors' : '';
$config_file = ipmi_plugin_config_path('ipmi'.$cmd_suffix.'.config');
$binary = "/usr/sbin/ipmi{$cmd_suffix}-config";
$config = str_replace("\r", '', (string)ipmi_array_get($_POST, 'ipmicfg', ''));
$config_old = is_file($config_file) ? file_get_contents($config_file) : '';

if ($arg_commit && $config_old !== '' && $config === '')
    $config = $config_old;

if ($commit && $config !== '') {
    if (!ipmi_atomic_write($config_file, $config))
        ipmi_json_response(false, 'Unable to save the staged IPMI config.', [], [$config_file]);

    $result = ipmi_run_process($binary, array_merge(["--filename=$config_file", '--commit'], $netopt_args));
    if (!$result['success']) {
        if ($config_old !== '')
            ipmi_atomic_write($config_file, $config_old);
        ipmi_json_response(false, 'Commit to the BMC failed.', [], $result['output']);
    }

    ipmi_config_editor_response($config_file, 'Configuration committed to the BMC.');
}

$result = ipmi_run_process($binary, array_merge(["--filename=$config_file", '--checkout'], $netopt_args), false);
if (!$result['success'])
    ipmi_json_response(false, 'Unable to load configuration from the BMC.', [], $result['output']);

ipmi_config_editor_response($config_file, 'Configuration loaded from the BMC.');
