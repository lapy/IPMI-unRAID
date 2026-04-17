<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_runtime.php';

if(getopt('h') == true)
    print_r(get_all_hdds());

function get_all_hdds(){
    $devs = array_merge(
        ipmi_read_ini_config('/var/local/emhttp/disks.ini', true),
        ipmi_read_ini_config('/var/local/emhttp/devs.ini', true)
    );

    unset($devs['flash']);
    $hdds = array();

    foreach ($devs as $dev) {
        $id = $dev['id'];
        $device = $dev['device'];
        if(!empty($id) && !empty($device))
            $hdds[$device] = $id;
    }
    ksort($hdds);
    return array_flip($hdds);
}
?>
