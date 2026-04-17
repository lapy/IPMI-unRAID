<?php
function ipmi_normalize_hex_string($value) {
    return preg_replace('/\s+/', ' ', trim((string)$value));
}

function ipmi_repeat_hex_value($value, $count) {
    if ($count < 1)
        return '';

    return implode(' ', array_fill(0, $count, $value));
}

function ipmi_hex_token_count($value) {
    $value = ipmi_normalize_hex_string($value);
    return empty($value) ? 0 : count(explode(' ', $value));
}

function ipmi_compose_prefixed_command($prefix, $payload) {
    return ipmi_normalize_hex_string(trim($prefix.' '.$payload));
}

function ipmi_asrock_fan_profiles() {
    return [
        'asrock_legacy' => [
            'profile' => 'asrock_legacy',
            'pwm_prefix' => '',
            'pwm_min' => 0,
            'slot_count' => 0,
        ],
        'asrockrack_romed8u_2t' => [
            'profile' => 'asrockrack_romed8u_2t',
            'raw' => '00 3a',
            'auto_prefix' => 'd8',
            'auto_payload' => ipmi_repeat_hex_value('00', 16),
            'manual_prefix' => 'd8',
            'manual_payload' => ipmi_repeat_hex_value('01', 16),
            'full_prefix' => 'd6',
            'full_payload' => ipmi_repeat_hex_value('64', 16),
            'pwm_prefix' => 'd6',
            'pwm_min' => 16,
            'probe_slots' => [0, 1, 2, 3, 4, 5],
            'placeholder_start' => 7,
            'slot_count' => 16,
        ],
    ];
}

function ipmi_get_asrock_fan_profile($profile_name='asrock_legacy') {
    $profiles = ipmi_asrock_fan_profiles();
    return array_key_exists($profile_name, $profiles) ? $profiles[$profile_name] : $profiles['asrock_legacy'];
}

function ipmi_detect_asrock_fan_profile($board, $board_model) {
    if ($board === 'ASRockRack' && strpos($board_model, 'ROMED8U-2T') === 0)
        return 'asrockrack_romed8u_2t';

    return 'asrock_legacy';
}

function ipmi_get_board_json_primary_key($board, $board_json) {
    if (!is_array($board_json))
        return '';

    if (array_key_exists($board, $board_json))
        return $board;

    foreach ($board_json as $key => $entry) {
        if (is_array($entry))
            return $key;
    }

    return '';
}

function ipmi_resolve_asrock_fan_profile($board, $board_model, $board_json=[]) {
    if ($board !== 'ASRock' && $board !== 'ASRockRack')
        return '';

    $board_key = ipmi_get_board_json_primary_key($board, $board_json);
    if (!empty($board_key) && isset($board_json[$board_key]['profile'])) {
        $profile_name = $board_json[$board_key]['profile'];
        $profiles = ipmi_asrock_fan_profiles();
        if (array_key_exists($profile_name, $profiles))
            return $profile_name;
    }

    return ipmi_detect_asrock_fan_profile($board, $board_model);
}

function ipmi_canonicalize_asrock_fan_name_for_profile($profile_name, $fan_name) {
    $fan_name = trim((string)$fan_name);
    if ($profile_name === 'asrockrack_romed8u_2t' && preg_match('/^(FAN[1-3])_[12]$/', $fan_name, $match))
        return $match[1];

    return $fan_name;
}

function ipmi_canonicalize_asrock_fan_name($board, $board_model, $board_json, $fan_name) {
    $profile_name = ipmi_resolve_asrock_fan_profile($board, $board_model, $board_json);
    return ipmi_canonicalize_asrock_fan_name_for_profile($profile_name, $fan_name);
}

function ipmi_asrock_fan_aliases_for_profile($profile_name, $fan_name) {
    $canonical_name = ipmi_canonicalize_asrock_fan_name_for_profile($profile_name, $fan_name);
    $aliases = [$canonical_name];

    if ($profile_name === 'asrockrack_romed8u_2t' && preg_match('/^FAN([1-3])$/', $canonical_name, $match)) {
        $aliases[] = $canonical_name.'_1';
        $aliases[] = $canonical_name.'_2';
    }

    return array_values(array_unique($aliases));
}

function ipmi_asrock_fan_aliases($board, $board_model, $board_json, $fan_name) {
    $profile_name = ipmi_resolve_asrock_fan_profile($board, $board_model, $board_json);
    return ipmi_asrock_fan_aliases_for_profile($profile_name, $fan_name);
}

function ipmi_normalize_asrock_fan_map($fans, $profile_name) {
    if (!is_array($fans))
        return [];

    $normalized_fans = [];
    foreach ($fans as $fan_name => $fan_value) {
        $canonical_name = ipmi_canonicalize_asrock_fan_name_for_profile($profile_name, $fan_name);
        if (!array_key_exists($canonical_name, $normalized_fans))
            $normalized_fans[$canonical_name] = $fan_value;
    }

    return $normalized_fans;
}

function ipmi_normalize_asrock_fancfg($board, $board_model, $board_json, $fancfg) {
    if (($board !== 'ASRock' && $board !== 'ASRockRack') || !is_array($fancfg))
        return is_array($fancfg) ? $fancfg : [];

    $profile_name = ipmi_resolve_asrock_fan_profile($board, $board_model, $board_json);
    if (empty($profile_name))
        return $fancfg;

    $fan_keys = ['FAN', 'TEMP', 'TEMPHDD', 'TEMPLO', 'TEMPHI', 'FANMAX', 'FANMIN', 'TEMPLOO', 'TEMPHIO', 'FANMAXO', 'FANMINO'];
    foreach ($fancfg as $cfg_key => $cfg_value) {
        if (!preg_match('/^([A-Z0-9]+)_(.+)$/', $cfg_key, $match))
            continue;

        if (!in_array($match[1], $fan_keys, true))
            continue;

        $canonical_key = $match[1].'_'.ipmi_canonicalize_asrock_fan_name_for_profile($profile_name, $match[2]);
        if (!array_key_exists($canonical_key, $fancfg))
            $fancfg[$canonical_key] = $cfg_value;
    }

    return $fancfg;
}

function ipmi_normalize_prefixed_command($command, $prefix, $default_payload) {
    $command = ipmi_normalize_hex_string($command);
    if (empty($command))
        return ipmi_compose_prefixed_command($prefix, $default_payload);

    $tokens = explode(' ', $command);
    if ($tokens[0] === $prefix)
        array_shift($tokens);

    $payload = implode(' ', $tokens);
    if (ipmi_hex_token_count($payload) !== ipmi_hex_token_count($default_payload))
        $payload = $default_payload;

    return ipmi_compose_prefixed_command($prefix, $payload);
}

function ipmi_pad_asrock_fans($fans, $slot_count, $fan_position=1) {
    if (!is_array($fans))
        $fans = [];

    if ($slot_count < 1)
        return $fans;

    $fan_position = max(1, intval($fan_position));
    while (count($fans) < $slot_count) {
        $fan_name = 'FAN_POS'.$fan_position;
        if (!array_key_exists($fan_name, $fans))
            $fans[$fan_name] = '01';
        $fan_position++;
    }

    return $fans;
}

function ipmi_normalize_asrock_board_json($board, $board_model, $board_json) {
    if (($board !== 'ASRock' && $board !== 'ASRockRack') || !is_array($board_json))
        return is_array($board_json) ? $board_json : [];

    $profile_name = ipmi_resolve_asrock_fan_profile($board, $board_model, $board_json);
    $profile = ipmi_get_asrock_fan_profile($profile_name);

    foreach ($board_json as $board_key => $entry) {
        if (!is_array($entry))
            continue;

        $entry['profile'] = $profile_name;

        if ($profile_name === 'asrockrack_romed8u_2t') {
            $entry['raw'] = $profile['raw'];
            $entry['auto'] = ipmi_normalize_prefixed_command(isset($entry['auto']) ? $entry['auto'] : '', $profile['auto_prefix'], $profile['auto_payload']);
            $entry['manual'] = ipmi_normalize_prefixed_command(isset($entry['manual']) ? $entry['manual'] : '', $profile['manual_prefix'], $profile['manual_payload']);
            $entry['full'] = ipmi_normalize_prefixed_command(isset($entry['full']) ? $entry['full'] : '', $profile['full_prefix'], $profile['full_payload']);
            $entry['pwm_prefix'] = $profile['pwm_prefix'];
            $entry['pwm_min'] = $profile['pwm_min'];
            $entry['fans'] = ipmi_normalize_asrock_fan_map(isset($entry['fans']) ? $entry['fans'] : [], $profile_name);
            $entry['fans'] = ipmi_pad_asrock_fans(
                isset($entry['fans']) ? $entry['fans'] : [],
                $profile['slot_count'],
                isset($profile['placeholder_start']) ? $profile['placeholder_start'] : 1
            );
        } else {
            if (isset($entry['auto']))
                $entry['auto'] = ipmi_normalize_hex_string($entry['auto']);
            if (isset($entry['manual']))
                $entry['manual'] = ipmi_normalize_hex_string($entry['manual']);
            if (isset($entry['full']))
                $entry['full'] = ipmi_normalize_hex_string($entry['full']);
            $entry['pwm_prefix'] = isset($entry['pwm_prefix']) ? ipmi_normalize_hex_string($entry['pwm_prefix']) : '';
            $entry['pwm_min'] = isset($entry['pwm_min']) ? intval($entry['pwm_min']) : $profile['pwm_min'];
        }

        $board_json[$board_key] = $entry;
    }

    return $board_json;
}

function ipmi_get_asrock_board_json_example($board, $board_model) {
    $board_name = ($board === 'ASRock') ? 'ASRock' : 'ASRockRack';
    $profile_name = ipmi_detect_asrock_fan_profile($board_name, $board_model);

    if ($profile_name === 'asrockrack_romed8u_2t') {
        $profile = ipmi_get_asrock_fan_profile($profile_name);
        $fans = [];
        for ($i = 1; $i <= $profile['slot_count']; $i++)
            $fans['FAN_POS'.$i] = '01';

        return [
            'schema_version' => 2,
            $board_name => [
                'profile' => $profile['profile'],
                'raw' => $profile['raw'],
                'auto' => ipmi_compose_prefixed_command($profile['auto_prefix'], $profile['auto_payload']),
                'manual' => ipmi_compose_prefixed_command($profile['manual_prefix'], $profile['manual_payload']),
                'full' => ipmi_compose_prefixed_command($profile['full_prefix'], $profile['full_payload']),
                'pwm_prefix' => $profile['pwm_prefix'],
                'pwm_min' => $profile['pwm_min'],
                'fans' => $fans,
            ],
        ];
    }

    return [
        'schema_version' => 2,
        $board_name => [
            'profile' => 'asrock_legacy',
            'raw' => '00 3a 01',
            'auto' => '00 00 00 00 00 00 00 00',
            'full' => '64 64 64 64 64 64 64 64',
            'fans' => [
                'CPU1_FAN1' => '01',
                'CPU2_FAN1' => '01',
                'REAR_FAN1' => '01',
                'NOT_AVAILABLE' => '01',
                'FRNT_FAN1' => '01',
                'FRNT_FAN2' => '01',
                'FRNT_FAN3' => '01',
                'FRNT_FAN4' => '11'
            ],
        ],
    ];
}

function ipmi_profile_label($profile_name) {
    switch ($profile_name) {
        case 'asrockrack_romed8u_2t':
            return 'ASRock Rack ROMED8U-2T';
        case 'asrock_legacy':
            return 'ASRock Legacy';
        default:
            return ucfirst(str_replace('_', ' ', (string)$profile_name));
    }
}

function ipmi_board_fan_mapping_stats($board, $board_model, $board_json) {
    $entries = [];
    foreach ((array)$board_json as $board_key => $entry) {
        if (is_array($entry))
            $entries[$board_key] = $entry;
    }

    $profile_name = ipmi_resolve_asrock_fan_profile($board, $board_model, $entries);
    $profile = ipmi_get_asrock_fan_profile($profile_name);

    $mapped = 0;
    foreach ($entries as $entry) {
        if (!empty($entry['fans']) && is_array($entry['fans']))
            $mapped += count($entry['fans']);
    }

    $expected = intval(ipmi_array_get($profile, 'slot_count', 0));

    return [
        'profile' => $profile_name,
        'label' => ipmi_profile_label($profile_name),
        'mapped' => $mapped,
        'expected' => $expected,
    ];
}
?>
