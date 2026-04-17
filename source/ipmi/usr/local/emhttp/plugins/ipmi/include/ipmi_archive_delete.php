<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_runtime.php';

ipmi_require_post_request();
ipmi_require_csrf();

$log = ipmi_plugin_config_path('archived_events.log');
$event = intval(ipmi_array_get($_POST, 'event', 0));

if ($event !== 0)
    ipmi_json_response(false, 'Archived events can only be cleared as a whole.', [], ['unsupported_single_delete']);

if (!ipmi_atomic_write($log, '', false))
    ipmi_json_response(false, 'Unable to clear archived events.', [], [$log]);

ipmi_json_response(true, 'Archived events cleared.', ['path' => $log]);
