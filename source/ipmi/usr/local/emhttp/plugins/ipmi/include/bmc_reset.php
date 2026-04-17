<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';

ipmi_require_post_request();
ipmi_require_csrf();

$sensor_check = ipmi_run_process('ipmi-sensors', array_merge(['-f'], $netopt_args));
$reset = ipmi_run_process('bmc-device', array_merge(['--cold-reset'], $netopt_args));

if (!$sensor_check['success'] || !$reset['success']) {
    $errors = array_filter([$sensor_check['text'], $reset['text']]);
    ipmi_json_response(false, 'BMC reset failed.', [], $errors);
}

ipmi_json_response(true, 'BMC reset requested successfully.');
