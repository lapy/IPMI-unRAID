<?php
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_helpers.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_settings_display.php';

function format_ipmi_temp($reading, $unit, $dot) {
  return (($reading != 0) ? ($unit==='F' ? round(9/5*$reading+32) : str_replace('.',$dot,$reading))."</font><font><small>&deg;$unit</small>" : '##');
}

function format_ipmi_numeric($reading, $dot) {
  $reading = floatval($reading);
  if (floor($reading) == $reading)
    return number_format($reading, 0, $dot, '');

  return rtrim(rtrim(str_replace('.', $dot, (string)$reading), '0'), $dot);
}

$request = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$footer_unit = htmlspecialchars((string)ipmi_array_get($request, 'unit', 'C'));
$footer_dot = htmlspecialchars((string)ipmi_array_get($request, 'dot', '.'));

function format_ipmi_footer_value($icon, $name, $id, $color, $value, $unit_html='') {
    return "<span title='$name ($id)'><i class='icon fa $icon'></i><font color='$color'>$value</font>$unit_html</span>";
}

if (!empty($disp_sensors)){
    $readings = ipmi_sensors($ignore);
    $displays = [];
    foreach($disp_sensors as $disp_sensor){
        if (!empty($readings[$disp_sensor])){
            $disp_name    = $readings[$disp_sensor]['Name'];
            $disp_id      = $readings[$disp_sensor]['ID'];
            $disp_reading = ($readings[$disp_sensor]['Type'] === 'OEM Reserved') ? $readings[$disp_sensor]['Event'] : $readings[$disp_sensor]['Reading'];
            $LowerNR = floatval($readings[$disp_sensor]['LowerNR']);
            $LowerC  = floatval($readings[$disp_sensor]['LowerC']);
            $LowerNC = floatval($readings[$disp_sensor]['LowerNC']);
            $UpperNC = floatval($readings[$disp_sensor]['UpperNC']);
            $UpperC  = floatval($readings[$disp_sensor]['UpperC']);
            $UpperNR = floatval($readings[$disp_sensor]['UpperNR']);
            $Color = ($disp_reading === 'N/A') ? 'blue' : 'green';

            if($readings[$disp_sensor]['Type'] === 'Temperature'){
                // if temperature is greater than upper non-critical show critical
                if ($disp_reading > $UpperNC && $UpperNC != 0)
                    $Color = 'orange';

                    // if temperature is greater than upper critical show non-recoverable
                if ($disp_reading > $UpperC && $UpperC != 0)
                    $Color = 'red';
                if ($disp_name == "HDD Temperature") $icon = "fa-hdd-o"; else $icon = "fa-thermometer";
                $displays[] = format_ipmi_footer_value(
                    $icon,
                    $disp_name,
                    $disp_id,
                    $Color,
                    format_ipmi_temp(floatval($disp_reading), $footer_unit, $footer_dot)
                );
            }elseif($readings[$disp_sensor]['Type'] === 'Fan'){
                // if Fan RPMs are less than lower non-critical
                if ($disp_reading < $LowerNC || $disp_reading < $LowerC || $disp_reading < $LowerNR)
                    $Color = "red";

                $displays[] = format_ipmi_footer_value(
                    'fa-tachometer',
                    $disp_name,
                    $disp_id,
                    $Color,
                    format_ipmi_numeric($disp_reading, $footer_dot),
                    '<small>rpm</small>'
                );
            }elseif($readings[$disp_sensor]['Type'] === 'Voltage'){
                // if Voltage is less than lower non-critical
                if ($disp_reading < $LowerNC || $disp_reading < $LowerC || $disp_reading < $LowerNR)
                    $Color = "red";
                if ($disp_reading > $UpperNC || $disp_reading > $UpperC || $disp_reading > $UpperNR)
                    $Color = "red";

                $displays[] = format_ipmi_footer_value(
                    'fa-bolt',
                    $disp_name,
                    $disp_id,
                    $Color,
                    format_ipmi_numeric($disp_reading, $footer_dot),
                    '<small>v</small>'
                );
            }elseif(
                strtoupper($readings[$disp_sensor]['Units']) === 'WATTS' ||
                strtoupper($readings[$disp_sensor]['Units']) === 'W' ||
                $readings[$disp_sensor]['Type'] === 'Power Supply'
            ){
                if ($disp_reading < $LowerNC || $disp_reading < $LowerC || $disp_reading < $LowerNR)
                    $Color = "red";
                if ($disp_reading > $UpperNC || $disp_reading > $UpperC || $disp_reading > $UpperNR)
                    $Color = "red";

                $displays[] = format_ipmi_footer_value(
                    'fa-plug',
                    $disp_name,
                    $disp_id,
                    $Color,
                    format_ipmi_numeric($disp_reading, $footer_dot),
                    '<small>w</small>'
                );
            }elseif($readings[$disp_sensor]['Type'] === 'OEM Reserved'){
                if($disp_reading === 'Medium')
                    $Color = 'orange';
                if($disp_reading === 'High')
                    $Color = 'Red';
                $displays[] = format_ipmi_footer_value('fa-thermometer', $disp_name, $disp_id, $Color, $disp_reading);
            }else{
                $displays[] = format_ipmi_footer_value('fa-tachometer', $disp_name, $disp_id, $Color, $disp_reading);
            }
        }
    }
}
$html = '';
if (!empty($displays))
    $html = "<span id='ipmitemps' style='margin-right:16px;font-weight: bold;cursor: pointer;'>".implode('&nbsp;', $displays)."</span>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ipmi_require_csrf();
    ipmi_json_response(true, 'ok', ['html' => $html]);
}

echo $html;
?>
