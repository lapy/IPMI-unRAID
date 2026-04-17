<?php

$ipmi_include = __DIR__ . '/../../source/ipmi/usr/local/emhttp/plugins/ipmi/include/';
require_once $ipmi_include . 'ipmi_runtime.php';
require_once $ipmi_include . 'ipmi_fan_profiles.php';
require_once $ipmi_include . 'ipmi_config_store.php';
require_once $ipmi_include . 'ipmi_fan_curve.php';
require_once __DIR__.'/../../source/release_info.php';

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$w = ipmi_fan_curve_wire_encode([['t' => 30, 'p' => 16], ['t' => 45, 'p' => 48]]);
assert_true($w === '30:16|45:48', 'Fan curve wire encoding should use t:p segments.');
$pts = ipmi_fan_curve_wire_decode('30:16|45:48');
assert_true(count($pts) === 2 && $pts[0]['t'] === 30 && $pts[1]['p'] === 48, 'Fan curve wire decoding should restore points.');
$iniCurve = tempnam(sys_get_temp_dir(), 'ipmi-curve.');
assert_true($iniCurve !== false, 'Temporary INI fixture should be created.');
file_put_contents($iniCurve, "CURVE_F1=30:16|45:48\n");
$iniData = ipmi_read_ini_config($iniCurve);
assert_true(ipmi_array_get($iniData, 'CURVE_F1', '') === '30:16|45:48', 'INI reader should preserve curve wire strings verbatim.');
@unlink($iniCurve);
$fctest = ['TEMPLO_F1' => '30', 'TEMPHI_F1' => '45', 'FANMIN_F1' => '16', 'FANMAX_F1' => '48'];
$pp = ipmi_fan_curve_primary_points($fctest, 'F1', 64);
assert_true(count($pp) === 2, 'Legacy flat keys should synthesize a two-point primary curve.');
$res = ipmi_fan_curve_compute_pwm(37.5, $pp, 64, 16, 48);
assert_true($res['pwm'] >= 16 && $res['pwm'] <= 48, 'Interpolated PWM should stay within configured endpoints.');
$smooth = ipmi_fan_curve_compute_pwm(85, [['t' => 0, 'p' => 16], ['t' => 100, 'p' => 50]], 64, 16, 64);
assert_true($smooth['pwm'] === 45, 'Interpolated PWM should preserve single-step values instead of snapping to coarse buckets.');
$clamped = ipmi_fan_curve_clamp_points([['t' => -5, 'p' => 0], ['t' => 120, 'p' => 80]], 64);
assert_true($clamped[0]['t'] === 0 && $clamped[0]['p'] === 1, 'Curve clamp should keep the first point inside the fixed graph bounds.');
assert_true($clamped[1]['t'] === 100 && $clamped[1]['p'] === 64, 'Curve clamp should keep the final point inside the fixed graph bounds.');

$profile = ipmi_detect_asrock_fan_profile('ASRockRack', 'ROMED8U-2T');
assert_true($profile === 'asrockrack_romed8u_2t', 'ROMED8U-2T should resolve to the ROMED profile.');

$normalized = ipmi_normalize_board_config('ASRockRack', 'ROMED8U-2T', [
    'ASRockRack' => [
        'fans' => [
            'FAN1_1' => '01',
        ],
    ],
]);

assert_true(intval(ipmi_array_get($normalized, 'schema_version', 0)) === 2, 'board.json should carry schema version 2.');
assert_true(ipmi_array_get($normalized['ASRockRack'], 'profile', '') === 'asrockrack_romed8u_2t', 'ROMED board entry should be normalized to the ROMED profile.');
assert_true(count(ipmi_array_get($normalized['ASRockRack'], 'fans', [])) === 16, 'ROMED board entries should pad to 16 PWM positions.');
assert_true(array_key_exists('FAN1', $normalized['ASRockRack']['fans']), 'ROMED aliases should normalize FAN1_1 to FAN1.');

$errors = ipmi_validate_board_config(['schema_version' => 2]);
assert_true(!empty($errors), 'Empty board config should fail validation.');

$args = ipmi_build_freeipmi_args('10.0.0.10', 'admin', 'secret', 'LAN_2_0', true);
assert_true(in_array('--always-prefix', $args, true), 'FreeIPMI args should include always-prefix when requested.');
assert_true(in_array('LAN_2_0', $args, true), 'FreeIPMI args should carry the requested driver.');
assert_true(ipmi_stringify_args(['ipmi-raw', '00', '3a']) === "'ipmi-raw' '00' '3a'", 'Argument stringification should quote each token.');
assert_true(ipmi_last_token("45 00 01\n") === '01', 'Last-token helper should extract the final mode byte.');

$kv = ipmi_parse_key_value_output("Socket(s): 2\nModel name: EPYC\n");
assert_true(ipmi_array_get($kv, 'Socket(s)', '') === '2', 'Key/value parser should extract lscpu fields.');

$dmi = ipmi_parse_dmidecode_records("Handle 0x0002, DMI type 2, 15 bytes\nBase Board Information\n\tManufacturer: ASRockRack\n\tProduct Name: ROMED8U-2T\n");
assert_true(count($dmi) === 1, 'DMI parser should detect one record.');
assert_true(ipmi_array_get($dmi[0], 'Manufacturer', '') === 'ASRockRack', 'DMI parser should read manufacturer.');
assert_true(ipmi_array_get($dmi[0], 'Product Name', '') === 'ROMED8U-2T', 'DMI parser should read product name.');

$notes = ipmi_release_normalize_notes("First item\n- Second item\n");
assert_true($notes === ['First item', 'Second item'], 'Release notes should normalize plain and bulleted lines.');
assert_true(ipmi_release_render_entry('2026.04.17', $notes) === "###2026.04.17\n- First item\n- Second item", 'Release entry rendering should use changelog format.');
assert_true(
    ipmi_release_render_entry('2026.04.17', ['Start & stop <daemon>']) === "###2026.04.17\n- Start &amp; stop &lt;daemon&gt;",
    'Release entry rendering should XML-escape generated notes.'
);

$manifest_fixture = <<<PLG
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY version   "2025.12.13">
]>
<PLUGIN>
<CHANGES>
##&name;
###2025.12.13
- Old note
###2025.11.07
- Older note
</CHANGES>
</PLUGIN>
PLG;
$updated_manifest = ipmi_release_update_manifest_text($manifest_fixture, '2026.04.17', $notes);
assert_true(strpos($updated_manifest, '<!ENTITY version   "2026.04.17">') !== false, 'Release helper should update the version entity.');
assert_true(strpos($updated_manifest, "###2026.04.17\n- First item\n- Second item\n###2025.12.13") !== false, 'Release helper should prepend the latest changelog entry.');

$original_server = $_SERVER ?? [];
$original_post = $_POST ?? [];
$original_var = $GLOBALS['var'] ?? null;

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];
$GLOBALS['var'] = ['csrf_token' => 'validated-upstream'];
assert_true(ipmi_request_has_prevalidated_csrf() === true, 'POST requests should treat upstream-validated CSRF as satisfied when the token has already been stripped.');

$_POST = ['csrf_token' => 'validated-upstream'];
assert_true(ipmi_request_has_prevalidated_csrf() === false, 'Explicit csrf_token POST fields should still go through direct token validation.');

$_SERVER = $original_server;
$_POST = $original_post;
if ($original_var === null) {
    unset($GLOBALS['var']);
} else {
    $GLOBALS['var'] = $original_var;
}

$temp_file = tempnam(sys_get_temp_dir(), 'ipmi-runtime.');
assert_true($temp_file !== false, 'Temporary file should be created.');
assert_true(ipmi_atomic_write($temp_file, "first\n", false), 'Atomic write should succeed.');
assert_true(trim((string)file_get_contents($temp_file)) === 'first', 'Atomic write should persist contents.');

echo "All IPMI helper tests passed.\n";
