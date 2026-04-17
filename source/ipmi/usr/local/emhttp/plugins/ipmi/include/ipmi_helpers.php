<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_drives.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_fan_profiles.php';
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

        $normal_sensor_name = !empty($temp['Name']) ? htmlspecialchars($temp['Name']) : 'Auto';
        $normal_sensor_meta = !empty($temp['Name'])
            ? my_temp(floatval($temp['Reading'])).' reading'
            : 'Firmware controls the curve when no sensor is selected';
        $normal_curve = $fancfg[$templo].' to '.$fancfg[$temphi].' deg'.$display_unit;
        $normal_duty = number_format((intval(intval($fancfg[$fanmin]) / $range * 1000) / 10), 1).'% to '.number_format((intval(intval($fancfg[$fanmax]) / $range * 1000) / 10), 1).'%';
        $override_sensor_name = !empty($temphddd['Name']) ? htmlspecialchars($temphddd['Name']) : 'None';
        $override_sensor_meta = !empty($temphddd['Name'])
            ? my_temp(floatval($temphddd['Reading'])).' reading'
            : 'No HDD spindown override configured';
        $override_curve = $fancfg[$temploo].' to '.$fancfg[$temphio].' deg'.$display_unit;
        $override_duty = number_format((intval(intval($fancfg[$fanmino]) / $range * 1000) / 10), 1).'% to '.number_format((intval(intval($fancfg[$fanmaxo]) / $range * 1000) / 10), 1).'%';
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
        echo '<p class="ipmi-fan-field-group__hint">Between the low and high thresholds the daemon ramps duty toward your minimum and maximum. Duty percentages use the board PWM scale (0–', intval($range), ').</p>';

        echo '<div class="ipmi-field fanctrl-settings">';
        echo '<label class="ipmi-field__label" for="', $tempid, '">Temperature sensor</label>';
        echo '<select id="', $tempid, '" name="', $tempid, '" class="fanctrl-temp" data-fan-name="', $name, '">';
        echo '<option value="0">Auto</option>', get_temp_options($fancfg[$tempid]), '</select>';
        echo '<div class="ipmi-field__help">Firmware-managed curve when set to <strong>Auto</strong>. Choose a specific sensor for a custom curve. Pick <strong>HDD Temperature</strong> to enable the spindown override section below.</div>';
        echo '</div>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $tempid, '">';
        echo '<label class="ipmi-field__label" for="', $temphi, '">High threshold (deg', $display_unit, ')</label>';
        echo '<select id="', $temphi, '" name="', $temphi, '" class="', $tempid, '">', get_temp_range('HI', $fancfg[$temphi], $display_unit), '</select>';
        echo '<div class="ipmi-field__help">At or above this point the header runs at its configured maximum.</div>';
        echo '</div>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $tempid, '">';
        echo '<label class="ipmi-field__label" for="', $templo, '">Low threshold (deg', $display_unit, ')</label>';
        echo '<select id="', $templo, '" name="', $templo, '" class="', $tempid, '">', get_temp_range('LO', $fancfg[$templo], $display_unit), '</select>';
        echo '<div class="ipmi-field__help">Below this point the header can fall to its configured minimum.</div>';
        echo '</div>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $tempid, '">';
        echo '<label class="ipmi-field__label" for="', $fanmax, '">Maximum duty</label>';
        echo '<select id="', $fanmax, '" name="', $fanmax, '" class="', $tempid, '">', get_minmax_options('HI', $fancfg[$fanmax]), '</select>';
        echo '<div class="ipmi-field__help">Caps the highest duty percentage for this header.</div>';
        echo '</div>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $tempid, '">';
        echo '<label class="ipmi-field__label" for="', $fanmin, '">Minimum duty</label>';
        echo '<select id="', $fanmin, '" name="', $fanmin, '" class="', $tempid, '">', get_minmax_options('LO', $fancfg[$fanmin]), '</select>';
        echo '<div class="ipmi-field__help">Prevents the header from dropping below this duty percentage.</div>';
        echo '</div>';

        echo '</div>';

        echo '<div class="ipmi-fan-field-group fanctrl-settings">';
        echo '<h5 class="ipmi-fan-field-group__title">HDD spindown override</h5>';
        echo '<p class="ipmi-fan-field-group__hint">Active only when the primary sensor above is <strong>HDD Temperature</strong>. When drives spin down, this second curve and duty limits apply instead of the primary curve.</p>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $tempid, '">';
        echo '<label class="ipmi-field__label" for="', $temphdd, '">HDD spindown sensor</label>';
        echo '<select id="', $temphdd, '"', $override_disabled, ' name="', $temphdd, '" class="fanctrl-temp fanctrl-override-source" data-fan-name="', $name, '">';
        echo '<option value="0">None</option>', get_temp_options($fancfg[$temphdd]), '</select>';
        echo '<div class="ipmi-field__help">Optional override used while your array drives are spun down.</div>';
        echo '</div>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $temphdd, '"', ($override_selected ? '' : ' style="display:none;"'), '>';
        echo '<label class="ipmi-field__label" for="', $temphio, '">Override high threshold (deg', $display_unit, ')</label>';
        echo '<select id="', $temphio, '" name="', $temphio, '" class="', $temphdd, '">', get_temp_range('HI', $fancfg[$temphio], $display_unit), '</select>';
        echo '<div class="ipmi-field__help">Upper threshold for the spindown override curve.</div>';
        echo '</div>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $temphdd, '"', ($override_selected ? '' : ' style="display:none;"'), '>';
        echo '<label class="ipmi-field__label" for="', $temploo, '">Override low threshold (deg', $display_unit, ')</label>';
        echo '<select id="', $temploo, '" name="', $temploo, '" class="', $temphdd, '">', get_temp_range('LO', $fancfg[$temploo], $display_unit), '</select>';
        echo '<div class="ipmi-field__help">Lower threshold for the spindown override curve.</div>';
        echo '</div>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $temphdd, '"', ($override_selected ? '' : ' style="display:none;"'), '>';
        echo '<label class="ipmi-field__label" for="', $fanmaxo, '">Override maximum duty</label>';
        echo '<select id="', $fanmaxo, '" name="', $fanmaxo, '" class="', $temphdd, '">', get_minmax_options('HI', $fancfg[$fanmaxo]), '</select>';
        echo '<div class="ipmi-field__help">Maximum duty while the spindown override is active.</div>';
        echo '</div>';

        echo '<div class="ipmi-field fanctrl-settings" data-controlled-by="', $temphdd, '"', ($override_selected ? '' : ' style="display:none;"'), '>';
        echo '<label class="ipmi-field__label" for="', $fanmino, '">Override minimum duty</label>';
        echo '<select id="', $fanmino, '" name="', $fanmino, '" class="', $temphdd, '">', get_minmax_options('LO', $fancfg[$fanmino]), '</select>';
        echo '<div class="ipmi-field__help">Minimum duty while the spindown override is active.</div>';
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
            $name = $sensor['Name'];
            $options .= "<option value='$id'";

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
        $options .= ">$temp</option>";
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

        $options .= '>'.number_format((intval(($value/$range)*1000)/10),1).'</option>';
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
