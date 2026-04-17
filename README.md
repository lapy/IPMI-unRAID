# IPMI-unRAID

`IPMI-unRAID` is an Unraid plugin for IPMI monitoring, event management, dashboard/footer sensor display, and board-aware fan control. This repository is maintained as the `lapy` fork of the original `SimonFair/IPMI-unRAID` project and carries substantial runtime, UI, fan-control, and release-engineering updates.

Install URL:

```text
https://raw.githubusercontent.com/lapy/IPMI-unRAID/master/plugin/ipmi.plg
```

## Fork Highlights

- First-class `ASRockRack ROMED8U-2T` fan-control support
- Board/profile-aware `board.json` normalization with schema versioning
- Shared runtime/config layer for safer command execution and atomic config writes
- Modernized AJAX/admin endpoints with `POST` + CSRF + JSON envelopes
- Improved fan-control UI with mapping status, validation, and profile visibility
- Footer/dashboard improvements including wattage display and more footer slots
- Sub-minute polling options in the fan-control UI
- CI, staged packaging, and automated GitHub release workflow

## Major Features

- Local and remote IPMI access
- Sensor readings, event log browsing, archived event management, and dashboard widgets
- Footer sensor display for temperature, RPM, voltage, HDD temperature, and power readings
- IPMI config and sensor-config editor flows from the web UI
- Fan-control support for `ASRock`, `ASRockRack`, `Supermicro`, and `Dell`
- Board-aware fan mapping discovery with `ipmi2json`
- Long-running fan daemon with wrapper-based lifecycle via `fanctrl_start` and `fanctrl_stop`

## ROMED8U-2T Support

This fork adds a built-in `ASRockRack ROMED8U-2T` profile instead of relying on out-of-tree patch scripts.

- Detects `ASRockRack` + `ROMED8U-2T` as a dedicated fan profile
- Uses the `d6`/`d8` ASRock Rack command layout
- Treats the board as 16-command-slot / 6-physical-header hardware
- Sets manual mode before scan and before daemon-driven direct control
- Clamps ROMED PWM writes to the board minimum of `16`
- Canonicalizes split-header names like `FAN1_1` and `FAN1_2` to shared headers such as `FAN1`

## Fan-Control Improvements

- Shared fan-profile registry in `include/ipmi_fan_profiles.php`
- `board.json` support for `profile`, `manual`, `pwm_prefix`, and `pwm_min`
- Schema-aware normalization for legacy `board.json` files
- Better `ipmi2json` probing flow with manual-mode entry before scan
- ROMED probing limited to the 6 physical headers while still emitting 16-position runtime payloads
- Safer daemon command execution through shared runtime helpers instead of ad-hoc shell strings
- Fixed fan daemon poll counter handling so configured loop intervals behave correctly

## UI Improvements

- Fan page summary cards for runtime, profile, and mapping health
- Per-header fan configuration cards with mapping badges
- Inline fan threshold validation before save
- Wattage sensors shown as first-class footer values with a dedicated power icon
- Footer capacity expanded beyond the original 4 slots
- Shorter loop interval options exposed in the fan-control page

## Runtime And Config Model

Persistent plugin data lives under `/boot/config/plugins/ipmi`.

- `ipmi.cfg`
  Stores connectivity, dashboard/footer, override, and event settings
- `fan.cfg`
  Stores daemon behavior, polling intervals, drive selection, and per-header control rules
- `board.json`
  Stores normalized board fan mappings and board/profile metadata
- `archived_events.log`
  Stores archived SEL entries

Additional runtime notes:

- `ipmi.cfg`, `fan.cfg`, and `board.json` are normalized on load
- Config writes use atomic replacement with timestamped backups
- `board.json` now carries `schema_version`
- UI endpoints now return `{ok, message, data, errors}`

## Repo Layout

- `source/ipmi/`
  Plugin payload staged into the Unraid filesystem layout
- `source/mkpkg`
  Package builder for versioned `.txz` and `.md5` artifacts
- `source/release.sh`
  Release orchestration helper used by GitHub Actions
- `source/release_info.php`
  Manifest/changelog updater for release automation
- `plugin/ipmi.plg`
  Main plugin manifest
- `archive/`
  Versioned plugin packages, checksums, and versioned manifests
- `.github/workflows/`
  CI and release automation
- `tests/php/`
  Lightweight PHP regression tests for helpers and release metadata logic

## Development

Typical local flow:

```bash
php -l source/ipmi/usr/local/emhttp/plugins/ipmi/scripts/ipmifan
php -l source/ipmi/usr/local/emhttp/plugins/ipmi/scripts/ipmi2json
php tests/php/run.php
bash -n source/mkpkg source/release.sh
./source/mkpkg ipmi
```

Optional version override:

```bash
PLUGIN_VERSION=2026.04.17 ./source/mkpkg ipmi
```

Packaging behavior:

- Builds from a staging directory
- Produces a versioned package, checksum, and versioned manifest in `archive/`
- Does not mutate tracked artifacts outside the intended manifest update path
- Falls back to portable `tar`/`xz` packaging when Slackware `makepkg` is unavailable

## CI

GitHub Actions currently validates:

- PHP syntax for plugin includes and runtime scripts
- shell syntax and `shellcheck` for wrappers and packaging helpers
- PHP helper/release tests in `tests/php`
- staged package build smoke tests

## Automated Releases

Use the `Release` workflow on `master` to publish a new version.

It will:

1. Validate the repository
2. Resolve a unique version from the provided input or current UTC date
3. Update `plugin/ipmi.plg`
4. Build release artifacts into `archive/`
5. Commit the new manifest and release artifacts back to `master`
6. Tag the release
7. Publish a GitHub release with the generated assets

If release notes are not supplied manually, the workflow derives them from commit subjects since the last `v*` tag.

## Operator Notes

- Use the Settings page to configure local vs remote IPMI access, SEL polling, dashboard widgets, footer sensors, and editor workflows.
- Use the Fan Control page to scan fan mappings, review board/profile status, and define per-header temperature rules.
- `ipmi2json` should be run with fan control stopped.
- `ipmifan --daemon` is now treated as a compatibility alias; the supported background lifecycle is via `fanctrl_start`.

## Upstream Lineage

This repository started from `SimonFair/IPMI-unRAID` and has been reworked in the `lapy` fork to add board-specific fan-control support, UI polish, safer config/runtime internals, and release automation aligned with the fork.
