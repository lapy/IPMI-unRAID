<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_check.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_fan_profiles.php';

/* fan control settings */
$fancfg_file = "$plg_path/fan.cfg";
$fancfg = ipmi_load_fan_config(false);
$fanctrl    = htmlspecialchars((string)ipmi_array_get($fancfg, 'FANCONTROL', 'disable'));
$fanpoll    = intval(ipmi_array_get($fancfg, 'FANPOLL', 6));
$hddpoll    = intval(ipmi_array_get($fancfg, 'HDDPOLL', 18));
$hddignore  = htmlspecialchars((string)ipmi_array_get($fancfg, 'HDDIGNORE', ''));
$harddrives = htmlspecialchars((string)ipmi_array_get($fancfg, 'HARDDRIVES', 'enable'));
$fan_schema_version = intval(ipmi_array_get($fancfg, 'SCHEMA_VERSION', IPMI_FAN_CONFIG_SCHEMA_VERSION));
$range      = 64;

$fanip = ($netsvc === 'enable')
    ? htmlspecialchars((string)ipmi_array_get($fancfg, 'FANIP', 'None'))
    : htmlspecialchars($ipaddr);

/* board info */
#if($board === 'ASRock' || $board === 'ASRockRack'){
switch($board) {
    case 'ASRock':
    case 'ASRockRack':

    //if board is ASRock
    //check number of physical CPUs
    if ($override == 'disable') {
        $socket_count = intval(ipmi_read_lscpu_field('Socket(s)'));
        $cmd_count = ($socket_count < 2) ? 0 : 1;
    } else
        $cmd_count = $ocount;

    $board_file = "$plg_path/board.json";
    $board_file_status = file_exists($board_file);
    $board_json = ipmi_load_board_config($board, $board_model, false);
    $fancfg = ipmi_normalize_asrock_fancfg($board, $board_model, $board_json, $fancfg);
    break;
    case  'Supermicro': 
    //if board is Supermicro
    $cmd_count = 0;
    $board_file_status = true;
    #if($board_model == '9'){
      switch($smboard_model){
        case '9':
        $range = 255;
        $board_json = [ 'Supermicro' =>
                [ 'raw'   => '00 30 91 5A 3',
                  'auto'  => '00 30 45 01',
                  'full'  => '00 30 45 01 01',
                  'fans'  => [
                    'FAN1234' => '10',
                    'FANA' => '11'
                  ]
            ]
        ];
        break;
      case '12':
        $range = 64;
        $board_json = [ 'Supermicro' =>
                [ 'raw'   => '00 30 70 66 01',
                  'auto'  => '00 30 45 01',
                  'full'  => '00 30 45 01 01',
                  'fans'  => [
                    'FAN1234' => '00',
                    'FANA' => '01'
                  ]
            ]
        ];
        break;
   # }else{
      default:
        $board_json = [ 'Supermicro' =>
                [ 'raw'   => '00 30 70 66 01',
                  'auto'  => '00 30 45 01',
                  'full'  => '00 30 45 01 01',
                  'fans'  => [
                    'FAN1234' => '00',
                    'FANA' => '01'
                  ]
            ]
        ];
        break;
    }
  
    break;
  case 'Dell':
  $board_file_status = true;
    $board_json = [ 'Dell' =>
            [ 'raw'    => '00 30 30 02 FF',
              'auto'   => '00 30 30 01 01',
              'manual' => '00 30 30 01 00',
              'full'   => '00 30 30 02 FF 64',
              'fans'   => [
                'FAN1234' => '00',
                'FAN123456' => '00',
              ]
        ]
    ];
    break;
 /*   $board_json = [ 'Dell' =>
    [ 'raw'    => '00 30 30 02 FF', # + value 01-64 for %
      'auto'   => '00 30 30 01 01',
      'manual' => '00 30 30 01 00',
      'full'   => '00 30 30 02 FF 64',
      'fans'   => [
        'Fan1A' => '00',
        'Fan1B' => '00',
        'Fan2A' => '01',
        'Fan2B' => '01',
        'Fan3A' => '02',
        'Fan3B' => '02',
        'Fan4A' => '03',
        'Fan4B' => '03',
        'Fan5A' => '04',
        'Fan5B' => '04',
        'Fan6A' => '05',
        'Fan6B' => '05',
        'Fan1234' => '00',
      ]
    ]
]; */
}

// fan network options
$fanopt_args = ($netsvc === 'enable')
    ? ipmi_build_freeipmi_args($fanip, $user, $password_plain, null, false)
    : [];
$fanopts = ipmi_stringify_args($fanopt_args);

$board_schema_version = ipmi_board_json_schema_version($board_json);
$board_mapping = ipmi_board_fan_mapping_stats($board, $board_model, $board_json);
$board_profile_name = ipmi_array_get($board_mapping, 'profile', '');
$board_profile_label = ipmi_array_get($board_mapping, 'label', 'Unknown');
$board_profile_expected = intval(ipmi_array_get($board_mapping, 'expected', 0));
$board_profile_mapped = intval(ipmi_array_get($board_mapping, 'mapped', 0));
?>
