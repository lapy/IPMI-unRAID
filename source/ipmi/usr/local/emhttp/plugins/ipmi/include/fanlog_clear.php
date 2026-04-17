<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_runtime.php';

ipmi_require_post_request();
ipmi_require_csrf();

$log = '/var/log/ipmifan';
if (!ipmi_atomic_write($log, '', false))
    ipmi_json_response(false, 'Unable to clear the fan log.', [], [$log]);

ipmi_json_response(true, 'Fan log cleared.', ['path' => $log]);
