<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_config_store.php';

/* read config files */
$plg_path = IPMI_PLUGIN_CONFIG_DIR;
$cfg_file = ipmi_plugin_config_path('ipmi.cfg');
$cfg = ipmi_load_main_config();

/* ipmi network options */
$netsvc    = htmlspecialchars((string)ipmi_array_get($cfg, 'NETWORK', 'disable'));
$ipaddr    = htmlspecialchars((string)ipmi_array_get($cfg, 'IPADDR', ''));
$user      = htmlspecialchars((string)ipmi_array_get($cfg, 'USER', ''));
$password  = htmlspecialchars((string)ipmi_array_get($cfg, 'PASSWORD', ''));
$override  = htmlspecialchars((string)ipmi_array_get($cfg, 'OVERRIDE', 'disable'));
$oboard    = htmlspecialchars((string)ipmi_array_get($cfg, 'OBOARD', ''));
$omodel    = htmlspecialchars((string)ipmi_array_get($cfg, 'OMODEL', ''));
$ocount    = htmlspecialchars((string)ipmi_array_get($cfg, 'OCOUNT', '0'));
$ignore    = htmlspecialchars((string)ipmi_array_get($cfg, 'IGNORE', ''));
$dignore   = htmlspecialchars((string)ipmi_array_get($cfg, 'DIGNORE', ''));
$devignore = htmlspecialchars((string)ipmi_array_get($cfg, 'DEVIGNORE', ''));
$devs      = htmlspecialchars((string)ipmi_array_get($cfg, 'DEVS', 'enable'));
$ipmilan   = htmlspecialchars((string)ipmi_array_get($cfg, 'IPMILAN', 'LAN'));
$cfg_schema_version = intval(ipmi_array_get($cfg, 'SCHEMA_VERSION', IPMI_MAIN_CONFIG_SCHEMA_VERSION));

/* check if local ipmi driver is loaded */
$ipmi = (file_exists('/dev/ipmi0') || file_exists('/dev/ipmi/0') || file_exists('/dev/ipmidev/0')); // Thanks to ljm42

/* options for network access */
$password_plain = ipmi_decode_secret($password);
$netopt_args = ($netsvc === 'enable')
    ? ipmi_build_freeipmi_args($ipaddr, $user, $password_plain, $ipmilan, true)
    : [];
$netopts = ipmi_stringify_args($netopt_args);
?>
