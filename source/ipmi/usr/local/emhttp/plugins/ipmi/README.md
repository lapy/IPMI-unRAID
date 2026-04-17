**IPMI Plugin**

This is the `lapy` fork of the Unraid IPMI plugin. It provides sensor monitoring, SEL/event workflows, dashboard and footer widgets, config editing, and board-aware fan control from inside the Unraid web UI.

**Highlights In This Fork**

- Built-in `ASRockRack ROMED8U-2T` fan profile
- Profile-aware `board.json` schema normalization
- Shared runtime/config helpers for safer command and file handling
- JSON + CSRF protected admin endpoints
- Improved fan-control page with profile status, mapping health, and validation
- Footer power sensor support and expanded footer slot count
- Release automation and staged packaging support

**Board And Fan Support**

- `ASRock` and `ASRockRack`
- `Supermicro`
- `Dell`
- `ROMED8U-2T` includes:
  - `d6`/`d8` raw command handling
  - minimum PWM clamp of `16`
  - split-header alias handling such as `FAN1_1` / `FAN1_2`
  - manual-mode scan flow before probing

**Persistent Files**

- `/boot/config/plugins/ipmi/ipmi.cfg`
- `/boot/config/plugins/ipmi/fan.cfg`
- `/boot/config/plugins/ipmi/board.json`
- `/boot/config/plugins/ipmi/archived_events.log`

**Operational Notes**

- `board.json` is schema-versioned and normalized automatically when loaded.
- Use the Settings page for connectivity, dashboard, footer, and SEL behavior.
- Use the Fan Control page to scan headers, review mappings, and define control policies.
- `fanctrl_start` and `fanctrl_stop` are the supported fan daemon lifecycle wrappers.
- `ipmi2json` should be run while fan control is stopped.

**UI Features**

- Dashboard and footer sensor display
- More than 4 footer sensor slots
- Wattage footer values with a dedicated power icon
- Sub-minute fan-control polling choices
- Mapping badges and per-header cards on the fan page

**Developer Notes**

- Packaging is built from `source/mkpkg`
- Automated releases are handled by GitHub Actions in the fork repository
- The plugin manifest is published from:
  - `https://raw.githubusercontent.com/lapy/IPMI-unRAID/master/plugin/ipmi.plg`
