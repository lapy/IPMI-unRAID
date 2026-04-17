<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_helpers.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_settings_display.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_settings_fan.php';

/* ipmi settings variables*/
$seld     = htmlspecialchars((string)ipmi_array_get($cfg, 'IPMISELD', 'disable'));
$seldpoll = intval(ipmi_array_get($cfg, 'IPMIPOLL', 60));
$local    = htmlspecialchars((string)ipmi_array_get($cfg, 'LOCAL', 'disable'));
$dash     = htmlspecialchars((string)ipmi_array_get($cfg, 'DASH', 'disable'));
$loadcfg  = (string)ipmi_array_get($cfg, 'LOADCFG', 'disable');

// check running status (single source for daemon PID checks)
$daemon_flags   = ipmi_daemon_running_flags();
$seld_run       = $daemon_flags['seld'];
$fanctrl_run    = $daemon_flags['fanctrl'];
$running        = "<span class='green'>Running</span>";
$stopped        = "<span class='orange'>Stopped</span>";
$seld_status    = ($seld_run)    ? $running : $stopped;
$fanctrl_status = ($fanctrl_run) ? $running : $stopped;

/* get sensors */
$sensors     = ipmi_sensors($ignore);
$allsensors  = ipmi_sensors();
$fansensors  = ipmi_fan_sensors($ignore);

/* check connection */
if (!empty($netopts))
    $conn = (empty($sensors)) ? 'Connection failed' : 'Connection successful';
?>
