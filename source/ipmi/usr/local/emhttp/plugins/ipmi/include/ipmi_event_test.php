<?php
/*
lun netfn cmd dir evm stype num etype state data data
ipmi-raw 00 04 02 41 04 01 30 01 09 ff ff Temp UC hi
ipmi-raw 00 04 02 41 04 01 30 01 07 ff ff Temp UNC hi
ipmi-raw 00 04 02 41 04 02 60 01 02 ff ff Volt LC lo
ipmi-raw 00 04 02 41 04 0c 53 6f 00 ff ff Mem Correct Error
*/

require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';

ipmi_require_post_request();
ipmi_require_csrf();

$array = [
    ['01 30 01 09 ff ff', 'Temperature - Upper Critical - Going High'],
    ['01 30 01 07 ff ff', 'Temperature - Upper Non Critical - Going High'],
    ['02 60 01 00 ff ff', 'Voltage Threshold - Lower Non Critical - Going Low'],
    ['02 60 01 02 ff ff', 'Voltage Threshold - Lower Critical - Going Low'],
    ['0c 53 6f 00 ff ff', 'Memory - Correctable ECC'],
];

$key = rand(0, 4);
$dir = empty($netopt_args) ? '41' : '';
$result = ipmi_run_process(
    'ipmi-raw',
    array_merge(
        ipmi_hex_tokens(trim("00 04 02 $dir 04 ".$array[$key][0])),
        $netopt_args
    )
);

if (!$result['success'])
    ipmi_json_response(false, 'Unable to inject test event.', [], [$result['text']]);

ipmi_json_response(true, 'Test event sent.', ['event' => $array[$key][1]]);
