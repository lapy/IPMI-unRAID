<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_config_store.php';

ipmi_require_post_request();
ipmi_require_csrf();

$tool = (string)ipmi_array_get($_POST, 'tool', '');

if ($tool === 'connection_test') {
    global $netopt_args, $netsvc, $ipmi;

    if ($netsvc !== 'enable' && !$ipmi)
        ipmi_json_response(false, 'No local IPMI device and network access is disabled.', [], ['no_path']);

    $args = ($netsvc === 'enable') ? $netopt_args : [];
    $result = ipmi_run_process('ipmi-sensors', array_merge(['-f'], $args));

    if (!$result['success'])
        ipmi_json_response(false, 'Could not reach the BMC with the current settings.', [], array_filter([$result['text']]));

    ipmi_json_response(true, 'BMC responded to a sensor query.', ['detail' => 'ipmi-sensors completed successfully.']);
}

if ($tool === 'diag_download') {
    $work = @tempnam(sys_get_temp_dir(), 'ipmi-diag.');
    if ($work === false)
        ipmi_json_response(false, 'Unable to create a temporary directory.', [], []);

    @unlink($work);
    if (!@mkdir($work, 0700, true))
        ipmi_json_response(false, 'Unable to prepare diagnostics bundle.', [], []);

    $redact = function ($path, $target_name) use ($work) {
        if (!is_readable($path))
            return;

        $text = (string)file_get_contents($path);
        if ($target_name === 'ipmi.cfg')
            $text = preg_replace('/^PASSWORD=.*$/m', 'PASSWORD=(redacted)', $text);

        @file_put_contents(ipmi_join_paths($work, $target_name), $text);
    };

    $redact(ipmi_plugin_config_path('ipmi.cfg'), 'ipmi.cfg');
    $redact(ipmi_plugin_config_path('fan.cfg'), 'fan.cfg');
    $redact(ipmi_plugin_config_path('board.json'), 'board.json');

    $bundle = $work.'.tar.gz';
    $cmd = 'tar czf '.escapeshellarg($bundle).' -C '.escapeshellarg($work).' . 2>&1';
    ipmi_run_command($cmd, true);

    if (!is_file($bundle) || !filesize($bundle)) {
        @unlink($bundle);
        ipmi_remove_tree($work);
        ipmi_json_response(false, 'Diagnostics archive could not be created.', [], []);
    }

    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="ipmi-diagnostics-'.gmdate('Ymd-His').'.tar.gz"');
    header('Content-Length: '.filesize($bundle));
    readfile($bundle);
    @unlink($bundle);
    ipmi_remove_tree($work);
    exit(0);
}

if ($tool === 'board_backup_list') {
    $pattern = ipmi_plugin_config_path('board.json.bak.*');
    $files = glob($pattern) ?: [];
    rsort($files);
    $names = array_map('basename', array_slice($files, 0, 20));
    ipmi_json_response(true, 'ok', ['files' => $names]);
}

if ($tool === 'board_backup_restore') {
    $name = basename((string)ipmi_array_get($_POST, 'backup', ''));
    if ($name === '' || !preg_match('/^board\\.json\\.bak\\.\\d{14}$/', $name))
        ipmi_json_response(false, 'Invalid backup selection.', [], ['invalid_backup']);

    $src = ipmi_plugin_config_path($name);
    if (!is_readable($src))
        ipmi_json_response(false, 'That backup file is not readable.', [], [$name]);

    $dest = ipmi_plugin_config_path('board.json');
    if (!@copy($src, $dest))
        ipmi_json_response(false, 'Unable to restore board.json.', [], [$dest]);

    ipmi_json_response(true, 'Restored '.$name.' to board.json.', ['file' => $name]);
}

ipmi_json_response(false, 'Unknown tool.', [], ['unknown_tool']);
