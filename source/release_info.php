#!/usr/bin/php
<?php

function ipmi_release_normalize_notes($raw_notes) {
    $raw_notes = str_replace(["\r\n", "\r"], "\n", (string)$raw_notes);
    $lines = array_filter(array_map('trim', explode("\n", $raw_notes)), 'strlen');

    if (empty($lines))
        return ['Automated release build.'];

    $normalized = [];
    foreach ($lines as $line) {
        $normalized[] = preg_replace('/^-\s*/', '', $line);
    }

    return $normalized;
}

function ipmi_release_render_entry($version, $notes) {
    $entry = "###{$version}\n";
    foreach ($notes as $note)
        $entry .= '- '.$note."\n";

    return rtrim($entry, "\n");
}

function ipmi_release_update_manifest_text($manifest_text, $version, $notes) {
    $updated = preg_replace_callback(
        '/<!ENTITY\s+version\s+"[^"]+">/',
        function ($matches) use ($version) {
            return preg_replace('/"[^"]+">$/', '"'.$version.'">', $matches[0]);
        },
        $manifest_text,
        1,
        $version_replacements
    );

    if ($version_replacements !== 1)
        throw new RuntimeException('Unable to update the plugin version entity.');

    if (!preg_match('/(<CHANGES>\s*##&name;\s*)(.*?)(\s*<\/CHANGES>)/s', $updated, $matches))
        throw new RuntimeException('Unable to locate the <CHANGES> section.');

    $changes_header = $matches[1];
    $changes_body = ltrim($matches[2], "\r\n");
    $changes_footer = $matches[3];
    $entry = ipmi_release_render_entry($version, $notes)."\n";

    if (preg_match('/\A###([^\r\n]+)\R.*?(?=^###|\z)/ms', $changes_body, $first_entry)) {
        if (trim($first_entry[1]) === $version)
            $changes_body = preg_replace('/\A###([^\r\n]+)\R.*?(?=^###|\z)/ms', $entry, $changes_body, 1);
        else
            $changes_body = $entry.$changes_body;
    } else {
        $changes_body = $entry.$changes_body;
    }

    return preg_replace(
        '/(<CHANGES>\s*##&name;\s*)(.*?)(\s*<\/CHANGES>)/s',
        '$1'.$changes_body.'$3',
        $updated,
        1
    );
}

function ipmi_release_read_notes($notes_path) {
    if ($notes_path === '')
        return '';

    $notes = @file_get_contents($notes_path);
    if ($notes === false)
        throw new RuntimeException('Unable to read release notes file: '.$notes_path);

    return $notes;
}

function ipmi_release_parse_args($argv) {
    $options = [
        'manifest' => '',
        'notes-file' => '',
        'output-notes-file' => '',
        'print-notes' => false,
        'version' => '',
    ];

    for ($index = 1; $index < count($argv); $index++) {
        $arg = $argv[$index];
        switch ($arg) {
            case '--manifest':
            case '--notes-file':
            case '--output-notes-file':
            case '--version':
                if (!isset($argv[$index + 1]))
                    throw new InvalidArgumentException('Missing value for '.$arg);
                $options[ltrim($arg, '-')] = $argv[++$index];
                break;
            case '--print-notes':
                $options['print-notes'] = true;
                break;
            default:
                throw new InvalidArgumentException('Unknown argument: '.$arg);
        }
    }

    if ($options['manifest'] === '')
        throw new InvalidArgumentException('--manifest is required');

    if ($options['version'] === '')
        throw new InvalidArgumentException('--version is required');

    return $options;
}

function ipmi_release_cli_main($argv) {
    $options = ipmi_release_parse_args($argv);
    $manifest_path = $options['manifest'];
    $manifest_text = @file_get_contents($manifest_path);

    if ($manifest_text === false)
        throw new RuntimeException('Unable to read manifest: '.$manifest_path);

    $notes = ipmi_release_normalize_notes(ipmi_release_read_notes($options['notes-file']));
    $updated_text = ipmi_release_update_manifest_text($manifest_text, $options['version'], $notes);

    if (@file_put_contents($manifest_path, $updated_text) === false)
        throw new RuntimeException('Unable to write manifest: '.$manifest_path);

    $rendered_notes = ipmi_release_render_entry($options['version'], $notes)."\n";

    if ($options['output-notes-file'] !== '') {
        if (@file_put_contents($options['output-notes-file'], $rendered_notes) === false)
            throw new RuntimeException('Unable to write rendered notes: '.$options['output-notes-file']);
    }

    if ($options['print-notes'])
        echo $rendered_notes;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        ipmi_release_cli_main($argv);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage().PHP_EOL);
        exit(1);
    }
}
