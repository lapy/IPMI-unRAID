<?php
/**
 * Shared IPMI plugin quick navigation (replaces fragile injected tabs on tool pages).
 *
 * @param string $active One of: sensors, events, archive, settings, fans, editor, ''.
 * @param array $opts Keys: show_fan (bool), show_editor (bool); default true.
 */
function ipmi_render_quick_nav_strip($active = '', $opts = []) {
    $opts += [
        'show_fan' => true,
        'show_editor' => true,
    ];
    $cls = function ($key) use ($active) {
        return 'ipmi-quick-nav__link'.($active === $key ? ' is-active' : '');
    };
    ?>
<nav class="ipmi-quick-nav" aria-label="IPMI shortcuts">
    <span class="ipmi-quick-nav__label">Go to</span>
    <a class="<?= $cls('sensors'); ?>" href="/Settings/IPMITools" data-ipmi-tools-tab="tab1">Sensors</a>
    <a class="<?= $cls('events'); ?>" href="/Settings/IPMITools" data-ipmi-tools-tab="tab2">Event log</a>
    <a class="<?= $cls('archive'); ?>" href="/Settings/IPMITools" data-ipmi-tools-tab="tab3">Archived events</a>
    <a class="<?= $cls('settings'); ?>" href="/Settings/IPMI" data-ipmi-settings-tab="tab1">IPMI settings</a>
    <?php if (!empty($opts['show_fan'])): ?>
    <a class="<?= $cls('fans'); ?>" href="/Settings/IPMI" data-ipmi-settings-tab="tab2">Fan control</a>
    <?php endif; ?>
    <?php if (!empty($opts['show_editor'])): ?>
    <a class="<?= $cls('editor'); ?>" href="/Settings/IPMI" data-ipmi-settings-tab="tab3">Config editor</a>
    <?php endif; ?>
</nav>
    <?php
}
