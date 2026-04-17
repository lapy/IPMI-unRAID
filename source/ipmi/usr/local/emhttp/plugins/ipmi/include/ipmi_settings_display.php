<?php
/* get display temps and fans */
$footer_sensor_slots = 8;
$disp_sensors = [];

for ($i = 1; $i <= $footer_sensor_slots; $i++) {
    $disp_sensors[$i] = isset($cfg["DISP_SENSOR$i"]) ? htmlspecialchars($cfg["DISP_SENSOR$i"]) : '';
    ${"disp_sensor$i"} = $disp_sensors[$i];
}
?>
