<?php
if (!function_exists('ipmi_array_get')) {
    require_once __DIR__.'/ipmi_runtime.php';
}

/**
 * Multi-point fan curves for fan.cfg (schema v3) with legacy v2 compatibility.
 *
 * Wire format (single line, safe for parse_ini_file values):
 *   "t1:p1|t2:p2|..." — temperatures in Celsius (same storage as TEMPLO/TEMPHI),
 *   PWM duty integers 1..$range (same as FANMIN/FANMAX raw values).
 */

if (!defined('IPMI_FAN_CURVE_MAX_POINTS')) {
    define('IPMI_FAN_CURVE_MAX_POINTS', 12);
}

if (!defined('IPMI_FAN_CURVE_TEMP_MIN_C')) {
    define('IPMI_FAN_CURVE_TEMP_MIN_C', 0);
}

if (!defined('IPMI_FAN_CURVE_TEMP_MAX_C')) {
    define('IPMI_FAN_CURVE_TEMP_MAX_C', 100);
}

/**
 * @param array<int, array{t:int,p:int}> $points
 * @return string
 */
function ipmi_fan_curve_wire_encode(array $points) {
    $parts = [];
    foreach ($points as $pt) {
        $t = isset($pt['t']) ? intval($pt['t']) : 0;
        $p = isset($pt['p']) ? intval($pt['p']) : 0;
        $parts[] = $t.':'.$p;
    }
    return implode('|', $parts);
}

/**
 * @return array<int, array{t:int,p:int}>
 */
function ipmi_fan_curve_wire_decode($wire) {
    $wire = trim((string)$wire);
    if ($wire === '')
        return [];

    $out = [];
    foreach (explode('|', $wire) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '')
            continue;
        $pair = explode(':', $chunk, 2);
        if (count($pair) !== 2)
            continue;
        $t = intval($pair[0]);
        $p = intval($pair[1]);
        $out[] = ['t' => $t, 'p' => $p];
    }
    return ipmi_fan_curve_sort_unique($out);
}

/**
 * @param array<int, array{t:int,p:int}> $points
 * @return array<int, array{t:int,p:int}>
 */
function ipmi_fan_curve_sort_unique(array $points) {
    usort($points, function ($a, $b) {
        return intval($a['t']) <=> intval($b['t']);
    });
    $dedup = [];
    $last_t = null;
    foreach ($points as $pt) {
        $t = intval($pt['t']);
        if ($last_t !== null && $t === $last_t)
            continue;
        $dedup[] = ['t' => $t, 'p' => intval($pt['p'])];
        $last_t = $t;
    }
    return $dedup;
}

/**
 * Legacy two-point model -> curve points (t, pwm).
 *
 * @return array<int, array{t:int,p:int}>
 */
function ipmi_fan_curve_from_legacy(int $templo, int $temphi, int $fanmin, int $fanmax) {
    if ($templo >= $temphi)
        $temphi = $templo + 5;
    return [
        ['t' => $templo, 'p' => $fanmin],
        ['t' => $temphi, 'p' => $fanmax],
    ];
}

/**
 * Sync legacy flat keys from endpoints of a curve (for INI compatibility).
 *
 * @param array<int, array{t:int,p:int}> $points
 */
function ipmi_fan_curve_sync_legacy_from_points(array &$fancfg, $fanName, array $points) {
    if (count($points) < 2)
        return;
    $first = $points[0];
    $last = $points[count($points) - 1];
    $fancfg['TEMPLO_'.$fanName] = (string)intval($first['t']);
    $fancfg['TEMPHI_'.$fanName] = (string)intval($last['t']);
    $fancfg['FANMIN_'.$fanName] = (string)intval($first['p']);
    $fancfg['FANMAX_'.$fanName] = (string)intval($last['p']);
}

/**
 * @param array<int, array{t:int,p:int}> $pointsO
 */
function ipmi_fan_curve_sync_legacy_override_from_points(array &$fancfg, $fanName, array $pointsO) {
    if (count($pointsO) < 2)
        return;
    $first = $pointsO[0];
    $last = $pointsO[count($pointsO) - 1];
    $fancfg['TEMPLOO_'.$fanName] = (string)intval($first['t']);
    $fancfg['TEMPHIO_'.$fanName] = (string)intval($last['t']);
    $fancfg['FANMINO_'.$fanName] = (string)intval($first['p']);
    $fancfg['FANMAXO_'.$fanName] = (string)intval($last['p']);
}

/**
 * Primary curve points for a fan header (decode CURVE_* or build from legacy).
 *
 * @return array<int, array{t:int,p:int}>
 */
function ipmi_fan_curve_primary_points(array $fancfg, $fanName, $range) {
    $key = 'CURVE_'.$fanName;
    if (!empty($fancfg[$key])) {
        $pts = ipmi_fan_curve_wire_decode($fancfg[$key]);
        if (count($pts) >= 2)
            return ipmi_fan_curve_clamp_points($pts, $range);
    }
    $templo = intval(ipmi_array_get($fancfg, 'TEMPLO_'.$fanName, 30));
    $temphi = intval(ipmi_array_get($fancfg, 'TEMPHI_'.$fanName, 45));
    $fanmin = intval(ipmi_array_get($fancfg, 'FANMIN_'.$fanName, 16));
    $fanmax = intval(ipmi_array_get($fancfg, 'FANMAX_'.$fanName, $range));
    return ipmi_fan_curve_clamp_points(ipmi_fan_curve_from_legacy($templo, $temphi, $fanmin, $fanmax), $range);
}

/**
 * @return array<int, array{t:int,p:int}>
 */
function ipmi_fan_curve_override_points(array $fancfg, $fanName, $range) {
    $key = 'CURVEO_'.$fanName;
    if (!empty($fancfg[$key])) {
        $pts = ipmi_fan_curve_wire_decode($fancfg[$key]);
        if (count($pts) >= 2)
            return ipmi_fan_curve_clamp_points($pts, $range);
    }
    $templo = intval(ipmi_array_get($fancfg, 'TEMPLOO_'.$fanName, 30));
    $temphi = intval(ipmi_array_get($fancfg, 'TEMPHIO_'.$fanName, 45));
    $fanmin = intval(ipmi_array_get($fancfg, 'FANMINO_'.$fanName, 16));
    $fanmax = intval(ipmi_array_get($fancfg, 'FANMAXO_'.$fanName, $range));
    return ipmi_fan_curve_clamp_points(ipmi_fan_curve_from_legacy($templo, $temphi, $fanmin, $fanmax), $range);
}

/**
 * @param array<int, array{t:int,p:int}> $points
 * @return array<int, array{t:int,p:int}>
 */
function ipmi_fan_curve_clamp_points(array $points, $range) {
    $range = intval($range);
    if ($range < 1)
        $range = 64;
    $out = [];
    foreach ($points as $pt) {
        $t = max(IPMI_FAN_CURVE_TEMP_MIN_C, min(IPMI_FAN_CURVE_TEMP_MAX_C, intval($pt['t'])));
        $p = max(1, min($range, intval($pt['p'])));
        $out[] = ['t' => $t, 'p' => $p];
    }
    return ipmi_fan_curve_sort_unique($out);
}

/**
 * @return int
 */
function ipmi_fan_curve_temp_for_display($tempC, $display_unit) {
    $tempC = floatval($tempC);
    if ($display_unit === 'F')
        return intval(round(($tempC * 9 / 5) + 32));
    return intval(round($tempC));
}

/**
 * @return string
 */
function ipmi_fan_curve_display_unit($display_unit) {
    return ($display_unit === 'F') ? 'F' : 'C';
}

/**
 * @param array<int, array{t:int,p:int}> $points
 */
function ipmi_fan_curve_format_summary(array $points, $display_unit) {
    if (count($points) < 2)
        return '';
    $first = $points[0];
    $last = $points[count($points) - 1];
    $u = ipmi_fan_curve_display_unit($display_unit);
    $t0 = ipmi_fan_curve_temp_for_display($first['t'], $display_unit);
    $t1 = ipmi_fan_curve_temp_for_display($last['t'], $display_unit);
    return count($points).' points · '.$t0.'–'.$t1.' '.$u;
}

/**
 * Piecewise linear PWM from temperature reading (Celsius).
 *
 * @param array<int, array{t:int,p:int}> $points sorted, at least 2
 * @return array{pct: float, pwm: int} pct 0..1 for logging (legacy style)
 */
function ipmi_fan_curve_compute_pwm($reading, array $points, $range, $fanmin, $fanmax) {
    $range = intval($range);
    $fanmin = max(1, min($range, intval($fanmin)));
    $fanmax = max(1, min($range, intval($fanmax)));
    if ($fanmin > $fanmax) {
        $tmp = $fanmin;
        $fanmin = $fanmax;
        $fanmax = $tmp;
    }
    $points = ipmi_fan_curve_sort_unique($points);
    if (count($points) < 2) {
        $pwm = $fanmin;
        $pct = $pwm / $range;
        return ['pct' => $pct, 'pwm' => $pwm];
    }

    $reading = floatval($reading);
    $n = count($points);
    $pwm = null;
    if ($reading <= floatval($points[0]['t'])) {
        $pwm = intval($points[0]['p']);
    } elseif ($reading >= floatval($points[$n - 1]['t'])) {
        $pwm = intval($points[$n - 1]['p']);
    } else {
        for ($i = 0; $i < $n - 1; $i++) {
            $t0 = floatval($points[$i]['t']);
            $t1 = floatval($points[$i + 1]['t']);
            if ($reading < $t0 || $reading > $t1)
                continue;
            $p0 = floatval($points[$i]['p']);
            $p1 = floatval($points[$i + 1]['p']);
            if ($t1 <= $t0) {
                $pwm = intval(round($p0));
                break;
            }
            $alpha = ($reading - $t0) / ($t1 - $t0);
            $pwm = intval(round($p0 + $alpha * ($p1 - $p0)));
            break;
        }
        if ($pwm === null)
            $pwm = intval($points[0]['p']);
    }

    $pwm = max(1, min($range, intval(round($pwm))));

    if ($pwm < $fanmin) {
        $pwm = $fanmin;
        $pct = $fanmin / $range;
    }
    if ($pwm > $fanmax) {
        $pwm = $fanmax;
        $pct = $fanmax / $range;
    }

    return ['pct' => $pwm / $range, 'pwm' => $pwm];
}

/**
 * Validate a curve wire string; returns error messages or empty array.
 *
 * @return array<int, string>
 */
function ipmi_fan_curve_validate_wire($wire, $range, $label) {
    $errs = [];
    $pts = ipmi_fan_curve_wire_decode($wire);
    if (count($pts) < 2)
        return [$label.': add at least two points on the curve.'];

    if (count($pts) > IPMI_FAN_CURVE_MAX_POINTS)
        $errs[] = $label.': at most '.IPMI_FAN_CURVE_MAX_POINTS.' points.';

    $range = intval($range);
    $prev_t = null;
    foreach ($pts as $pt) {
        $t = intval($pt['t']);
        $p = intval($pt['p']);
        if ($prev_t !== null && $t <= $prev_t)
            $errs[] = $label.': temperatures must increase along the curve.';
        $prev_t = $t;
        if ($t < IPMI_FAN_CURVE_TEMP_MIN_C || $t > IPMI_FAN_CURVE_TEMP_MAX_C)
            $errs[] = $label.': temperatures must be between '.IPMI_FAN_CURVE_TEMP_MIN_C.' C and '.IPMI_FAN_CURVE_TEMP_MAX_C.' C.';
        if ($p < 1 || $p > $range)
            $errs[] = $label.': duty values must be between 1 and '.$range.'.';
    }
    return $errs;
}
