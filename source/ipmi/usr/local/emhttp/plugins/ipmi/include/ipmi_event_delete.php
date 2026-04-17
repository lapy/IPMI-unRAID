<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';

ipmi_require_post_request();
ipmi_require_csrf();

$cmd = '/usr/sbin/ipmi-sel --comma-separated-output --output-event-state --no-header-output --interpret-oem-data ';
$log = ipmi_plugin_config_path('archived_events.log');
$event = htmlspecialchars((string)ipmi_array_get($_POST, 'event', ''));
$archive = intval(ipmi_array_get($_POST, 'archive', 0));
$options = '';

if ($netsvc === 'enable') {
    if ($event) {
        $id = explode('_', $event);
        $event = ipmi_array_get($id, 1, '');
        $options = ' -h '.escapeshellarg(long2ip(intval(ipmi_array_get($id, 0, 0))));
    } else
        $options = ' -h '.escapeshellarg($ipaddr);

    $options .= ' -u '.escapeshellarg($user).' -p '.escapeshellarg(base64_decode($password)).' --always-prefix --session-timeout=5000 --retransmission-timeout=1000 ';
}

if ($archive) {
    $append = $event ? '--display='.intval($event).$options : $options;
    $archive_result = ipmi_run_command($cmd.$append);
    if (!empty($archive_result['text']))
        file_put_contents($log, $archive_result['text'].PHP_EOL, FILE_APPEND);
}

$delete_options = $event ? '--delete='.intval($event).$options : '--clear '.$options;
$delete_result = ipmi_run_command($cmd.$delete_options);
if (!$delete_result['success'])
    ipmi_json_response(false, 'Unable to delete IPMI events.', [], [$delete_result['text']]);

ipmi_json_response(true, 'IPMI events updated.', [
    'archived' => (bool)$archive,
    'event' => $event,
]);
