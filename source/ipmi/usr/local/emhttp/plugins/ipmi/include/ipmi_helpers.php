<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_drives.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_fan_profiles.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_fan_curve.php';
require_once '/usr/local/emhttp/plugins/dynamix/include/Helpers.php';

$action = htmlspecialchars((string)ipmi_array_get($_REQUEST, 'action', ''));
$hdd_temp = null;
$display = ipmi_get_display_preferences();
$display_unit = ipmi_array_get($display, 'unit', 'C');

function ipmi_ensure_hdd_temp_loaded() {
    global $hdd_temp;
    if ($hdd_temp === null)
        $hdd_temp = get_highest_temp();
}

function ipmi_output_helper_response($payload) {
    if (strtoupper((string)ipmi_array_get($_SERVER, 'REQUEST_METHOD', 'GET')) === 'POST') {
        ipmi_require_csrf();
        ipmi_json_response(true, 'ok', $payload);
    }

    if (!headers_sent())
        header('Content-Type: application/json');
    echo json_encode($payload);
    exit(0);
}

if (!empty($action)) {
    $state = ['Critical' => 'red', 'Warning' => 'yellow', 'Nominal' => 'green', 'N/A' => 'blue'];
    if ($action === 'ipmisensors'){
        ipmi_output_helper_response(['Sensors' => ipmi_sensors($ignore),'Network' => ($netsvc === 'enable'),'State' => $state]);
    }
    elseif($action === 'ipmievents'){
        ipmi_output_helper_response(['Events' => ipmi_events(),'Network' => ($netsvc === 'enable'),'State' => $state]);
    }
    elseif($action === 'ipmiarch'){
        ipmi_output_helper_response(['Archives' => ipmi_events(true), 'Network' => ($netsvc === 'enable'), 'State' => $state]);
    }
    elseif($action === 'ipmidash') {
        ipmi_output_helper_response(['Sensors' => ipmi_sensors($dignore), 'Network' => ($netsvc === 'enable'),'State' => $state]);
    }
}

/* get highest temp of hard drives */
function get_highest_temp(){
    global $devignore;
    $ignore = array_flip(explode(',', $devignore));

    //get UA devices
    $ua_json = '/var/state/unassigned.devices/hdd_temp.json';
    $ua_devs = ipmi_read_json_config($ua_json);

    //get all hard drives
    $hdds = array_merge(
        ipmi_read_ini_config('/var/local/emhttp/disks.ini', true),
        ipmi_read_ini_config('/var/local/emhttp/devs.ini', true)
    );

    $highest_temp = 0;
    foreach ($hdds as $hdd) {
        if (!array_key_exists($hdd['id'], $ignore)) {

            if(array_key_exists('temp', $hdd))
                $temp = $hdd['temp'];
            else{
                $ua_key = "/dev/".$hdd['device'];
                $temp = (array_key_exists($ua_key, $ua_devs)) ? $ua_devs[$ua_key]['temp'] : 'N/A';
            }

            if(is_numeric($temp))
                $highest_temp = ($temp > $highest_temp) ? $temp : $highest_temp;
        }
    }
    $return = ($highest_temp === 0) ? 'N/A': $highest_temp;
    return $return;
}

/**
 * Drop sensors whose numeric ID appears in a comma-separated IGNORE list (matches ipmi-sensors -R semantics for typical configs).
 */
function ipmi_sensors_apply_ignore_list($sensors, $ignore) {
    if (!is_array($sensors) || $sensors === [])
        return [];
    $ignore = trim((string)$ignore);
    if ($ignore === '')
        return $sensors;

    $drop = [];
    foreach (array_map('trim', explode(',', $ignore)) as $token) {
        if ($token !== '')
            $drop[$token] = true;
    }
    if (empty($drop))
        return $sensors;

    $out = [];
    foreach ($sensors as $key => $sensor) {
        $sid = (string)ipmi_array_get($sensor, 'ID', '');
        if (isset($drop[$sid]))
            continue;
        $out[$key] = $sensor;
    }
    return $out;
}

/* get an array of all sensors and their values */
function ipmi_sensors($ignore='') {
    global $ipmi, $netopts, $hdd_temp;

    ipmi_ensure_hdd_temp_loaded();

    // return empty array if no ipmi detected and no network options
    if(!($ipmi || !empty($netopts)))
        return [];

    $ignored = (empty($ignore)) ? '' : '-R '.escapeshellarg($ignore);
    $cmd = '/usr/sbin/ipmi-sensors --output-sensor-thresholds --comma-separated-output '.
        "--output-sensor-state --no-header-output --interpret-oem-data $netopts $ignored 2>/dev/null";
    $return_var=null ;    
    $result = ipmi_run_command($cmd, false);
    $output = $result['output'];
    $return_var = $result['exit_code'];

    // return empty array if error
    if ($return_var)
        return [];

    // add highest hard drive temp sensor and check if hdd is ignored
    $hdd = (preg_match('/99/', $ignore)) ? '' :
        "99,HDD Temperature,Temperature,Nominal,$hdd_temp,C,N/A,N/A,N/A,45.00,50.00,N/A,Ok";
    if(!empty($hdd)){
        if(!empty($netopts))
            $hdd = '127.0.0.1:'.$hdd;
        $output[] = $hdd;
    }
    // test sensor
    // $output[] = "98,CPU Temp,OEM Reserved,Nominal,N/A,N/A,N/A,N/A,N/A,45.00,50.00,N/A,'Medium'";

    // key names for ipmi sensors output
    $keys = ['ID','Name','Type','State','Reading','Units','LowerNR','LowerC','LowerNC','UpperNC','UpperC','UpperNR','Event'];
    $sensors = [];

    foreach($output as $line){

        $sensor_raw = explode(",", str_replace("'",'',$line));
        $size_raw = sizeof($sensor_raw);

        // add sensor keys as keys to ipmi sensor output
        $sensor = ($size_raw < 13) ? []: array_combine($keys, array_slice($sensor_raw,0,13,true));

        if(empty($netopts))
            $sensors[$sensor['ID']] = $sensor;
        else{

            //split id into host and id
            $id = explode(':',$sensor['ID']);
            $sensor['IP'] = trim($id[0]);
            $sensor['ID'] = trim($id[1]);
            if ($sensor['IP'] === 'localhost')
                $sensor['IP'] = '127.0.0.1';

            // add sensor to array of sensors
            $sensors[ip2long($sensor['IP']).'_'.$sensor['ID']] = $sensor;
        }
    }
    return $sensors;
}

/* get array of events and their values */
function ipmi_events($archive=null){
    global $ipmi, $netopts;
    $return_var = null;
    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($netopts)))
        return [];

    if($archive) {
        $filename = "/boot/config/plugins/ipmi/archived_events.log";
        $output = is_file($filename) ? file($filename, FILE_IGNORE_NEW_LINES) : [] ;
    } else {
        $cmd = '/usr/sbin/ipmi-sel --comma-separated-output --output-event-state --no-header-output '.
            "--interpret-oem-data --output-oem-event-strings $netopts 2>/dev/null";
        $return_var=null ;
        $result = ipmi_run_command($cmd, false);
        $output = $result['output'];
        $return_var = $result['exit_code'];
    }

    // return empty array if error
    if ($return_var)
        return [];

    // key names for ipmi event output
    $keys = ['ID','Date','Time','Name','Type','State','Event'];
    $events = [];

    foreach($output as $line){

        $event_raw = explode(",", $line);
        $size_raw = sizeof($event_raw);

        // add event keys as keys to ipmi event output
        $event = ($size_raw < 7) ? []: array_combine($keys, array_slice($event_raw,0,7,true));

        // put time in sortable format and add unix timestamp
        $timestamp = $event['Date']." ".$event['Time'];
        if(strtotime($timestamp)) {
            if($date = Datetime::createFromFormat('M-d-Y H:i:s', $timestamp)) {
                $event['Date'] = $date->format('Y-m-d H:i:s');
                $event['Time'] = $date->format('U');
            }
        }

        if (empty($netopts)){

            if($archive)
                $events[$event['Time']."-".$event['ID']] = $event;
            else
                $events[$event['ID']] = $event;

        }else{

            //split id into host and id
            $id = explode(':',$event['ID']);
            $event['IP'] = trim($id[0]);
            if($archive)
                $event['ID'] = $event['Time'];
            else
                $event['ID'] = trim($id[1]);
            if ($event['IP'] === 'localhost')
                $event['IP'] = '127.0.0.1';

            // add event to array of events
            $events[ip2long($event['IP']).'_'.$event['ID']] = $event;
        }
    }
    return $events;
}

/* get select options for a fan and temp sensors */
function ipmi_get_options($selected=null){
    global $sensors;
    $options = "";
    foreach($sensors as $id => $sensor){
        $name = $sensor['Name'];
        $reading  = ($sensor['Type'] === 'OEM Reserved') ? $sensor['Event'] : $sensor['Reading'];
        $ip       = (empty($sensor['IP'])) ? '' : " ({$sensor['IP']})";
        $units    = is_numeric($reading) ? $sensor['Units'] : '';
        $options .= "<option value='$id'";

        // set saved option as selected
        if ($selected == $id)
            $options .= " selected";
        if ($sensor['Type'] == "Temperature")  $options .= ">$name$ip - ".my_temp($reading)."</option>"; else $options .= ">$name$ip - $reading $units</option>" ;
    }
    return $options;
}

/* get select options for enabled sensors */
function ipmi_get_enabled($ignore){
    global $ipmi, $netopts, $allsensors;
    $options = "";
    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($netopts)))
        return [];

    // create array of keyed ignored sensors
    $ignored = array_flip(explode(',', $ignore));
    foreach($allsensors as $sensor){
        $id       = $sensor['ID'];
        $reading  = $sensor['Reading'];
        $units    = ($reading === 'N/A') ? '' : " {$sensor['Units']}";
        $ip       = (empty($netopts))    ? '' : " {$sensor['IP']}";
        $options .= "<option value='$id'";

        // search for id in array to not select ignored sensors
        $options .= array_key_exists($id, $ignored) ?  '' : " selected";

        $options .= ">{$sensor['Name']}$ip - $reading$units</option>";

    }
    return $options;
}

// get a json array of the contents of gihub repo
function get_content_from_github($repo, $file) {
    $ch = curl_init();
    $ch_vers = curl_version();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/'.$ch_vers['version']);
    curl_setopt($ch, CURLOPT_URL, $repo);
    $content = curl_exec($ch);
    curl_close($ch);
    if (!empty($content) && (!is_file($file) || $content != file_get_contents($file)))
        ipmi_atomic_write($file, $content);
}


/* FAN HELPERS */


/* get fan and temp sensors array */
function ipmi_fan_sensors($ignore=null) {
    global $ipmi, $fanopts, $hdd_temp;

    ipmi_ensure_hdd_temp_loaded();

    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($fanopts)))
        return [];

    $ignored = (empty($ignore)) ? '' : "-R $ignore";
    $cmd = "/usr/sbin/ipmi-sensors --comma-separated-output --no-header-output --interpret-oem-data $fanopts $ignored 2>/dev/null";
    $return_var=null ;
    $result = ipmi_run_command($cmd, false);
    $output = $result['output'];
    $return_var = $result['exit_code'];

    if ($return_var)
        return []; // return empty array if error

    // add highest hard drive temp sensor
    $output[] = "99,HDD Temperature,Temperature, $hdd_temp,C,Ok";
    // test sensors
    //$output[] = "700,CPU_FAN1,Fan,1200,RPM,Ok";
    //$output[] = "701,CPU_FAN2,Fan,1200,RPM,Ok";
    //$output[] = "702,SYS_FAN1,Fan,1200,RPM,Ok";
    //$output[] = "703,SYS_FAN2,Fan,1200,RPM,Ok";
    //$output[] = "704,SYS_FAN3,Fan,1200,RPM,Ok";

    // key names for ipmi sensors output
    $keys = ['ID', 'Name', 'Type', 'Reading', 'Units', 'Event'];
    $sensors = [];

    foreach($output as $line){

        // add sensor keys as keys to ipmi sensor output
        $sensor_raw = explode(",", $line);
        $size_raw = sizeof($sensor_raw);
        $sensor = ($size_raw < 6) ? []: array_combine($keys, array_slice($sensor_raw,0,6,true));

        if ($sensor['Type'] === 'Temperature' || $sensor['Type'] === 'Fan')
            $sensors[$sensor['ID']] = $sensor;
    }
    return $sensors; // sensor readings
    unset($sensors);
}

/**
 * Graph-first fan curve editor (primary or HDD override). Syncs legacy INI keys via hidden inputs.
 *
 * @param string $kind 'primary'|'override'
 * @param float|null $readingC primary sensor reading in Celsius, or null
 */
function ipmi_fan_curve_render_editor($fanName, $kind, $range, $display_unit, $curveController, $readingC, array $fancfg) {
    $id_safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$fanName);
    $is_override = ($kind === 'override');
    $points = $is_override
        ? ipmi_fan_curve_override_points($fancfg, $fanName, $range)
        : ipmi_fan_curve_primary_points($fancfg, $fanName, $range);
    $wire = htmlspecialchars(ipmi_fan_curve_wire_encode($points), ENT_QUOTES, 'UTF-8');

    if ($is_override) {
        $curve_key = 'CURVEO_'.$fanName;
        $lt = 'TEMPLOO_'.$fanName;
        $hi = 'TEMPHIO_'.$fanName;
        $lo_pwm = 'FANMINO_'.$fanName;
        $hi_pwm = 'FANMAXO_'.$fanName;
    } else {
        $curve_key = 'CURVE_'.$fanName;
        $lt = 'TEMPLO_'.$fanName;
        $hi = 'TEMPHI_'.$fanName;
        $lo_pwm = 'FANMIN_'.$fanName;
        $hi_pwm = 'FANMAX_'.$fanName;
    }

    $v_lo = htmlspecialchars((string)ipmi_array_get($fancfg, $lt, ''), ENT_QUOTES, 'UTF-8');
    $v_hi = htmlspecialchars((string)ipmi_array_get($fancfg, $hi, ''), ENT_QUOTES, 'UTF-8');
    $v_min = htmlspecialchars((string)ipmi_array_get($fancfg, $lo_pwm, ''), ENT_QUOTES, 'UTF-8');
    $v_max = htmlspecialchars((string)ipmi_array_get($fancfg, $hi_pwm, ''), ENT_QUOTES, 'UTF-8');
    $fan_esc = htmlspecialchars($fanName, ENT_QUOTES, 'UTF-8');
    $kind_esc = htmlspecialchars($kind, ENT_QUOTES, 'UTF-8');
    $unit_esc = htmlspecialchars((string)$display_unit, ENT_QUOTES, 'UTF-8');
    $ctrl_esc = htmlspecialchars((string)$curveController, ENT_QUOTES, 'UTF-8');
    $temp_input_min = ($display_unit === 'F') ? 32 : IPMI_FAN_CURVE_TEMP_MIN_C;
    $temp_input_max = ($display_unit === 'F') ? 212 : IPMI_FAN_CURVE_TEMP_MAX_C;
    $read_attr = ($readingC !== null && is_numeric($readingC))
        ? htmlspecialchars((string)floatval($readingC), ENT_QUOTES, 'UTF-8')
        : '';

    echo '<div class="ipmi-fan-curve-editor" data-fan="', $fan_esc, '" data-curve-kind="', $kind_esc, '" data-range="', intval($range), '" data-display-unit="', $unit_esc, '" data-curve-controller="', $ctrl_esc, '"';
    if ($read_attr !== '')
        echo ' data-reading-c="', $read_attr, '"';
    echo '>';

    echo '<div class="ipmi-fan-curve-toolbar" role="toolbar" aria-label="Curve presets">';
    echo '<div class="ipmi-fan-curve-toolbar__group">';
    echo '<span class="ipmi-fan-curve-toolbar__label">Presets</span>';
    echo '<button type="button" class="ipmi-inline-button ipmi-fan-curve-preset" data-preset="quiet">Quiet</button>';
    echo '<button type="button" class="ipmi-inline-button ipmi-fan-curve-preset" data-preset="balanced">Balanced</button>';
    echo '<button type="button" class="ipmi-inline-button ipmi-fan-curve-preset" data-preset="performance">Performance</button>';
    echo '<button type="button" class="ipmi-inline-button ipmi-fan-curve-preset" data-preset="flat">Flat min</button>';
    echo '</div>';
    echo '<div class="ipmi-fan-curve-toolbar__group ipmi-fan-curve-toolbar__group--actions">';
    echo '<button type="button" class="ipmi-inline-button ipmi-fan-curve-add" title="Add a point">Add point</button>';
    echo '<button type="button" class="ipmi-inline-button ipmi-fan-curve-remove" title="Remove selected point">Remove</button>';
    echo '</div>';
    echo '</div>';

    echo '<div class="ipmi-fan-curve-svg-wrap">';
    echo '<svg class="ipmi-fan-curve-svg" viewBox="0 0 420 184" role="img" aria-label="Fan speed versus temperature">';
    echo '<rect class="ipmi-fan-curve-bg" x="0" y="0" width="420" height="184" rx="8"/>';
    echo '<g class="ipmi-fan-curve-plot"></g>';
    echo '</svg>';
    echo '</div>';

    echo '<div class="ipmi-fan-curve-inspector">';
    echo '<span class="ipmi-fan-curve-inspector__label">Selected point</span>';
    echo '<label class="ipmi-sr-only" for="fan-curve-t-', $id_safe, '-', $kind_esc, '">Temperature</label>';
    echo '<input type="number" class="ipmi-fan-curve-inp-t" id="fan-curve-t-', $id_safe, '-', $kind_esc, '" step="1" min="', intval($temp_input_min), '" max="', intval($temp_input_max), '" value="30" />';
    echo '<span class="ipmi-fan-curve-inspector__unit">', ($display_unit === 'F' ? 'F' : 'C'), '</span>';
    echo '<label class="ipmi-sr-only" for="fan-curve-p-', $id_safe, '-', $kind_esc, '">Duty percent</label>';
    echo '<input type="number" class="ipmi-fan-curve-inp-p" id="fan-curve-p-', $id_safe, '-', $kind_esc, '" step="0.1" min="0" max="100" value="25" />';
    echo '<span class="ipmi-fan-curve-inspector__unit">%</span>';
    echo '</div>';

    echo '<input type="hidden" name="', htmlspecialchars($curve_key, ENT_QUOTES, 'UTF-8'), '" class="ipmi-fan-curve-wire" value="', $wire, '" />';
    echo '<input type="hidden" name="', htmlspecialchars($lt, ENT_QUOTES, 'UTF-8'), '" class="ipmi-fan-curve-legacy ipmi-fan-curve-legacy-lo ', htmlspecialchars($curveController, ENT_QUOTES, 'UTF-8'), '" value="', $v_lo, '" />';
    echo '<input type="hidden" name="', htmlspecialchars($hi, ENT_QUOTES, 'UTF-8'), '" class="ipmi-fan-curve-legacy ipmi-fan-curve-legacy-hi ', htmlspecialchars($curveController, ENT_QUOTES, 'UTF-8'), '" value="', $v_hi, '" />';
    echo '<input type="hidden" name="', htmlspecialchars($lo_pwm, ENT_QUOTES, 'UTF-8'), '" class="ipmi-fan-curve-legacy ipmi-fan-curve-legacy-min ', htmlspecialchars($curveController, ENT_QUOTES, 'UTF-8'), '" value="', $v_min, '" />';
    echo '<input type="hidden" name="', htmlspecialchars($hi_pwm, ENT_QUOTES, 'UTF-8'), '" class="ipmi-fan-curve-legacy ipmi-fan-curve-legacy-max ', htmlspecialchars($curveController, ENT_QUOTES, 'UTF-8'), '" value="', $v_max, '" />';

    echo '<p class="ipmi-field__help ipmi-fan-curve-help">Drag points to shape the curve inside a fixed 0–100 C by 0–100% grid. Duty maps to the board PWM scale (0–', intval($range), '), and the dashed line shows the live sensor reading when available.</p>';
    echo '</div>';
}

/* get all fan options for fan control */
function get_fanctrl_options(){
    global $fansensors, $fancfg, $board, $board_model, $board_json, $board_file_status, $board_status, $cmd_count, $range, $display_unit;
    if (!$board_status) {
        echo '<div class="ipmi-alert ipmi-alert--danger"><p><strong>This board is not currently supported.</strong></p><p>Fan control options are only available for supported ASRock, ASRock Rack, Supermicro, and Dell profiles.</p></div>';
        return;
    }

    $i = 0;
    $fan1234 = 0;
    $sysfan = 0;
    $cpufan = 0;
    $board_profile = ipmi_resolve_asrock_fan_profile($board, $board_model, $board_json);
    $seen_asrock_fans = [];

    foreach($fansensors as $id => $fan){
        if($i > 11) break;
        if ($fan['Type'] !== 'Fan')
            continue;

        $raw_name = $fan['Name'];
        $name = htmlspecialchars($raw_name);
        $display = $name;

        if (($board === 'ASRock' || $board === 'ASRockRack') && !empty($board_profile)) {
            $canonical_name = ipmi_canonicalize_asrock_fan_name_for_profile($board_profile, $raw_name);
            if (array_key_exists($canonical_name, $seen_asrock_fans))
                continue;

            $seen_asrock_fans[$canonical_name] = true;
            $name = htmlspecialchars($canonical_name);
            $display = ($canonical_name === $raw_name) ? $name : htmlspecialchars($canonical_name.' / '.$raw_name);
        }

        if($board === 'Supermicro'){
            $syscpu = false;
            if(strpos($name, 'SYS_FAN') !== false){
                $syscpu = true;
                $i++;
                if($sysfan == 0){
                    $name = 'FANA';
                    $display = 'SYS_FAN';
                    $sysfan++;
                } else {
                    continue;
                }
            } elseif(strpos($name, 'CPU_FAN') !== false){
                $syscpu = true;
                $i++;
                if($cpufan == 0){
                    $name = 'FAN1234';
                    $display = 'CPU_FAN';
                    $cpufan++;
                } else {
                    continue;
                }
            } elseif($name !== 'FANA' && !$syscpu) {
                if($fan1234 == 0){
                    $name = 'FAN1234';
                    $display = 'FAN1234';
                    $fan1234++;
                } else {
                    continue;
                }
            }
        }

        if($board === 'Dell'){
            $i++;
            if($fan1234 == 0){
                $name = 'FAN123456';
                $display = 'FAN123456';
                $fan1234++;
            } else {
                continue;
            }
        }

        $tempid   = 'TEMP_'.$name;
        $temphdd  = 'TEMPHDD_'.$name;
        $templo   = 'TEMPLO_'.$name;
        $temphi   = 'TEMPHI_'.$name;
        $fanmax   = 'FANMAX_'.$name;
        $fanmin   = 'FANMIN_'.$name;
        $temploo  = 'TEMPLOO_'.$name;
        $temphio  = 'TEMPHIO_'.$name;
        $fanmaxo  = 'FANMAXO_'.$name;
        $fanmino  = 'FANMINO_'.$name;

        $temp = [];
        if (!empty($fancfg[$tempid]) && isset($fansensors[$fancfg[$tempid]]))
            $temp = $fansensors[$fancfg[$tempid]];

        $temphddd = [];
        if (!empty($fancfg[$temphdd]) && isset($fansensors[$fancfg[$temphdd]]))
            $temphddd = $fansensors[$fancfg[$temphdd]];

        $fan_configured = false;
        if($board_file_status){
            if (isset($board_json[$board]['fans']) && array_key_exists($name, $board_json[$board]['fans'])) {
                $fan_configured = true;
            } elseif ($cmd_count !== 0 && isset($board_json["{$board}1"]['fans']) && array_key_exists($name, $board_json["{$board}1"]['fans'])) {
                $fan_configured = true;
            }
        }

        $curve_primary = ipmi_fan_curve_primary_points($fancfg, $name, $range);
        $curve_override_pts = ipmi_fan_curve_override_points($fancfg, $name, $range);
        $read_primary_c = (!empty($temp['Name']) && isset($temp['Reading'])) ? floatval($temp['Reading']) : null;
        $read_override_c = (!empty($temphddd['Name']) && isset($temphddd['Reading'])) ? floatval($temphddd['Reading']) : null;

        $normal_sensor_name = !empty($temp['Name']) ? htmlspecialchars($temp['Name']) : 'Auto';
        $normal_sensor_help = !empty($temp['Name'])
            ? 'Current reading: '.my_temp(floatval($temp['Reading']))
            : 'Current reading follows the BMC firmware curve.';
        $normal_sensor_meta = !empty($temp['Name'])
            ? my_temp(floatval($temp['Reading'])).' reading'
            : 'Firmware controls the curve when no sensor is selected';
        $normal_curve = ipmi_fan_curve_format_summary($curve_primary, $display_unit);
        $p0 = intval($curve_primary[0]['p']);
        $p1 = intval($curve_primary[count($curve_primary) - 1]['p']);
        $normal_duty = number_format((intval(intval($p0) / $range * 1000) / 10), 1).'% to '.number_format((intval(intval($p1) / $range * 1000) / 10), 1).'%';
        $override_sensor_name = !empty($temphddd['Name']) ? htmlspecialchars($temphddd['Name']) : 'None';
        $override_sensor_help = !empty($temphddd['Name'])
            ? 'Current reading: '.my_temp(floatval($temphddd['Reading']))
            : 'Select an override sensor to show its current reading.';
        $override_sensor_meta = !empty($temphddd['Name'])
            ? my_temp(floatval($temphddd['Reading'])).' reading'
            : 'No HDD spindown override configured';
        $override_curve = ipmi_fan_curve_format_summary($curve_override_pts, $display_unit);
        $op0 = intval($curve_override_pts[0]['p']);
        $op1 = intval($curve_override_pts[count($curve_override_pts) - 1]['p']);
        $override_duty = number_format((intval(intval($op0) / $range * 1000) / 10), 1).'% to '.number_format((intval(intval($op1) / $range * 1000) / 10), 1).'%';
        $fan_rpm = floatval($fan['Reading']).' '.$fan['Units'];
        $override_disabled = ($fancfg[$tempid] == "99") ? '' : ' disabled';
        $override_selected = !empty($fancfg[$temphdd]) && $fancfg[$temphdd] != 0;

        echo '<section class="ipmi-fan-card', ($fan_configured ? '' : ' needs-mapping'), '" data-fan-name="', $name, '">';
        echo '<div class="ipmi-fan-card-header">';
        echo '<div><h4>', $display, '</h4><div class="ipmi-fan-card-subtitle">Live speed ', $fan_rpm, '</div></div>';
        echo '<span class="ipmi-fan-card-status ', ($fan_configured ? 'ok' : 'warn'), '">', ($fan_configured ? 'Mapped' : 'Needs Mapping'), '</span>';
        echo '</div>';

        echo '<input type="hidden" name="FAN_', $name, '" value="', $id, '"/>';

        echo '<div class="ipmi-fan-card-summary">';
        echo '<div class="fanctrl-basic ipmi-fan-stat-grid">';
        echo '<div class="ipmi-fan-stat"><span class="ipmi-fan-stat__label">Header</span><span class="ipmi-fan-stat__value">', $display, '</span><span class="ipmi-fan-stat__meta">Current fan speed ', $fan_rpm, '</span></div>';
        echo '<div class="ipmi-fan-stat"><span class="ipmi-fan-stat__label">Primary Sensor</span><span class="ipmi-fan-stat__value">', $normal_sensor_name, '</span><span class="ipmi-fan-stat__meta">', $normal_sensor_meta, '<br>', $normal_curve, '</span></div>';
        echo '<div class="ipmi-fan-stat"><span class="ipmi-fan-stat__label">Duty Range</span><span class="ipmi-fan-stat__value">', $normal_duty, '</span><span class="ipmi-fan-stat__meta">Applied while the primary sensor is active.</span></div>';
        echo '<div class="ipmi-fan-stat"><span class="ipmi-fan-stat__label">HDD Spindown Override</span><span class="ipmi-fan-stat__value">', $override_sensor_name, '</span><span class="ipmi-fan-stat__meta">', $override_sensor_meta, '<br>', $override_curve, ' at ', $override_duty, '</span></div>';
        echo '</div>';

        if(!$fan_configured)
            echo '<div class="ipmi-alert ipmi-alert--warning"><p>This header is not mapped in <code>board.json</code> yet. Run <strong>Scan Headers</strong> before enabling daemon control.</p></div>';

        echo '</div>';

        echo '<div class="ipmi-fan-card-controls" role="region" aria-label="Tuning controls for ', $display, '">';
        echo '<div class="ipmi-fan-control-grid">';

        echo '<div class="ipmi-fan-field-group fanctrl-settings">';
        echo '<h5 class="ipmi-fan-field-group__title">Primary temperature curve</h5>';
        echo '<p class="ipmi-fan-field-group__hint">Shape the PWM response versus temperature with multiple points. Values use the board PWM scale (0–', intval($range), '). The daemon interpolates linearly between points.</p>';

        echo '<div class="ipmi-field fanctrl-settings">';
        echo '<label class="ipmi-field__label" for="', $tempid, '">Temperature sensor</label>';
        echo '<select id="', $tempid, '" name="', $tempid, '" class="fanctrl-temp" data-fan-name="', $name, '">';
        echo '<option value="0">Auto</option>', get_temp_options($fancfg[$tempid]), '</select>';
        echo '<div class="ipmi-field__help">Firmware-managed curve when set to <strong>Auto</strong>. Choose a specific sensor for a custom curve. Pick <strong>HDD Temperature</strong> to enable the spindown override section below.</div>';
        echo '<div class="ipmi-field__help ipmi-fan-live-reading" id="fan-reading-primary-', $name, '">', $normal_sensor_help, '</div>';
        echo '</div>';

        echo '<div class="ipmi-field ipmi-field--curve-editor fanctrl-settings" data-controlled-by="', $tempid, '">';
        echo '<div class="ipmi-field__label">Fan curve (PWM vs temperature)</div>';
        ipmi_fan_curve_render_editor($name, 'primary', $range, $display_unit, $tempid, $read_primary_c, $fancfg);
        echo '</div>';

        echo '<div class="ipmi-fan-field-group fanctrl-settings">';
        echo '<h5 class="ipmi-fan-field-group__title">HDD spindown override</h5>';
        echo '<p class="ipmi-fan-field-group__hint">Active only when the primary sensor above is <strong>HDD Temperature</strong>. When drives spin down, this second curve and duty limits apply instead of the primary curve.</p>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $tempid, '">';
        echo '<label class="ipmi-field__label" for="', $temphdd, '">HDD spindown sensor</label>';
        echo '<select id="', $temphdd, '"', $override_disabled, ' name="', $temphdd, '" class="fanctrl-temp fanctrl-override-source" data-fan-name="', $name, '">';
        echo '<option value="0">None</option>', get_temp_options($fancfg[$temphdd]), '</select>';
        echo '<div class="ipmi-field__help">Optional override used while your array drives are spun down.</div>';
        echo '<div class="ipmi-field__help ipmi-fan-live-reading" id="fan-reading-override-', $name, '">', $override_sensor_help, '</div>';
        echo '</div>';

        echo '<div class="ipmi-field ipmi-field--curve-editor fanctrl-settings" data-controlled-by="', $temphdd, '"', ($override_selected ? '' : ' style="display:none;"'), '>';
        echo '<div class="ipmi-field__label">Override fan curve (PWM vs temperature)</div>';
        ipmi_fan_curve_render_editor($name, 'override', $range, $display_unit, $temphdd, $read_override_c, $fancfg);
        echo '</div>';

        echo '</div>';

        echo '</div>';
        echo '</div>';
        echo '</section>';
        $i++;
    }
}

/* get select options for temp & fan sensor types from fan ip*/
function get_temp_options($selected=0){
    global $fansensors, $fanip;
    $options = '';
    foreach($fansensors as $id => $sensor){
        if (($sensor['Type'] === 'Temperature') || ($sensor['Name'] === 'HDD Temperature')){
            $name = htmlspecialchars($sensor['Name']);
            if (isset($sensor['Reading'])) {
                $reading_plain = trim(html_entity_decode(strip_tags(my_temp(floatval($sensor['Reading']))), ENT_QUOTES, 'UTF-8'));
                $current_reading = htmlspecialchars('Current reading: '.$reading_plain, ENT_QUOTES, 'UTF-8');
            } else {
                $current_reading = htmlspecialchars('Current reading unavailable', ENT_QUOTES, 'UTF-8');
            }
            $options .= "<option value='$id' data-current-reading=\"$current_reading\"";

            // set saved option as selected
            if (intval($selected) === $id)
                $options .= ' selected';

        $options .= ">$name</option>";
        }
    }
    return $options;
}

/* get options for high or low temp thresholds */
function get_temp_range($order, $selected=0,$unit = "C"){
    $temps = [20,80];
    if ($order === 'HI')
      rsort($temps);
    $options = "";
    foreach(range($temps[0], $temps[1], 5) as $temp){
        $options .= "<option value='$temp'";

        // set saved option as selected
        if (intval($selected) === $temp)
            $options .= " selected";
        if ($unit == "F") $temp=round(9/5*$temp)+32; ;
        $options .= ">$temp $unit</option>";
    }
    return $options;
}

/* get options for fan speed min and max */
function get_minmax_options($order, $selected=0){
    global $range;
    $incr = [1,$range];
    if ($order === 'HI')
      rsort($incr);
    $options = "";
    foreach(range($incr[0], $incr[1], 1) as $value){
        $options .= "<option value='$value'";

        // set saved option as selected
        if (intval($selected) === $value)
            $options .= ' selected';

        $options .= '>'.number_format((intval(($value/$range)*1000)/10),1)."% duty</option>";
    }
    return $options;
}

/* get network ip options for fan control */
function get_fanip_options(){
    global $ipaddr, $fanip;
    $options = "";
    $ips = 'None,'.$ipaddr;
    $ips = explode(',',$ips);
        foreach($ips as $ip){
            $options .= '<option value="'.$ip.'"';
            if($fanip === $ip)
                $options .= ' selected';

            $options .= '>'.$ip.'</option>';
        }
    echo $options;
}

function get_hdd_options($ignore=null) {
    $hdds = get_all_hdds();
    $ignored = array_flip(explode(',', $ignore));
    $options = "";
    foreach ($hdds as $serial => $hdd) {
        $options .= "<option value='$serial'";

        // search for id in array to not select ignored sensors
        $options .= array_key_exists($serial, $ignored) ?  '' : " selected";

        $options .= ">$serial ($hdd)</option>";

    }
    return $options;
}

?>
