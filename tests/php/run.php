<?php

require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_runtime.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_fan_profiles.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_config_store.php';
require_once __DIR__.'/../../source/release_info.php';

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

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

$temp_file = tempnam(sys_get_temp_dir(), 'ipmi-runtime.');
assert_true($temp_file !== false, 'Temporary file should be created.');
assert_true(ipmi_atomic_write($temp_file, "first\n", false), 'Atomic write should succeed.');
assert_true(trim((string)file_get_contents($temp_file)) === 'first', 'Atomic write should persist contents.');

echo "All IPMI helper tests passed.\n";
