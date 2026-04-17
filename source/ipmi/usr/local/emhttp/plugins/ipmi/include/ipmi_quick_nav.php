<?php
/**
 * Unified IPMI application navigation (workspaces + context links).
 *
 * Replaces the split hub strip + dashed quick nav with one enterprise-style shell.
 *
 * @param string $active_item One of: sensors, events, archive, settings, fans, editor, ''.
 * @param array  $opts Keys: show_fan (bool), show_editor (bool); default true.
 */
function ipmi_app_nav_workspace($active_item) {
    if (in_array($active_item, ['sensors', 'events', 'archive'], true))
        return 'monitor';
    if (in_array($active_item, ['settings', 'fans'], true))
        return 'configure';
    if ($active_item === 'editor')
        return 'advanced';

    return 'configure';
}

/**
 * @param string $workspace monitor|configure|advanced
 */
function ipmi_app_nav_workspace_class($workspace, $current) {
    return 'ipmi-app-nav__workspace'.($workspace === $current ? ' is-active' : '');
}

/**
 * @param string $key        nav item key
 * @param string $active_item current page key
 */
function ipmi_app_nav_link_class($key, $active_item) {
    return 'ipmi-app-nav__link'.($key === $active_item ? ' is-active' : '');
}

function ipmi_render_app_nav($active_item = '', $opts = []) {
    $opts += [
        'show_fan' => true,
        'show_editor' => true,
    ];
    $ws = ipmi_app_nav_workspace($active_item);
    ?>
<div class="ipmi-app-nav" role="navigation" aria-label="IPMI application">
    <div class="ipmi-app-nav__top">
        <div class="ipmi-app-nav__workspaces" role="tablist" aria-label="IPMI workspaces">
            <a class="<?= ipmi_app_nav_workspace_class('monitor', $ws); ?>"
               role="tab"
               href="/Settings/IPMITools"
               data-ipmi-tools-tab="tab1"
               aria-selected="<?= $ws === 'monitor' ? 'true' : 'false'; ?>">Monitor</a>
            <a class="<?= ipmi_app_nav_workspace_class('configure', $ws); ?>"
               role="tab"
               href="/Settings/IPMI"
               data-ipmi-settings-tab="tab1"
               aria-selected="<?= $ws === 'configure' ? 'true' : 'false'; ?>">Configure</a>
            <a class="<?= ipmi_app_nav_workspace_class('advanced', $ws); ?>"
               role="tab"
               href="/Settings/IPMI"
               data-ipmi-settings-tab="tab3"
               aria-selected="<?= $ws === 'advanced' ? 'true' : 'false'; ?>">Advanced</a>
        </div>
        <button type="button"
                class="ipmi-app-nav__export"
                id="ipmi-diag-export"
                title="Download redacted ipmi.cfg, fan.cfg, and board.json">Export diagnostics</button>
    </div>
    <div class="ipmi-app-nav__context" aria-label="Current workspace">
        <?php if ($ws === 'monitor'): ?>
            <a class="<?= ipmi_app_nav_link_class('sensors', $active_item); ?>"
               href="/Settings/IPMITools"
               data-ipmi-tools-tab="tab1">Sensors</a>
            <a class="<?= ipmi_app_nav_link_class('events', $active_item); ?>"
               href="/Settings/IPMITools"
               data-ipmi-tools-tab="tab2">Event log</a>
            <a class="<?= ipmi_app_nav_link_class('archive', $active_item); ?>"
               href="/Settings/IPMITools"
               data-ipmi-tools-tab="tab3">Archived events</a>
        <?php elseif ($ws === 'configure'): ?>
            <a class="<?= ipmi_app_nav_link_class('settings', $active_item); ?>"
               href="/Settings/IPMI"
               data-ipmi-settings-tab="tab1">IPMI settings</a>
            <?php if (!empty($opts['show_fan'])): ?>
            <a class="<?= ipmi_app_nav_link_class('fans', $active_item); ?>"
               href="/Settings/IPMI"
               data-ipmi-settings-tab="tab2">Fan control</a>
            <?php endif; ?>
        <?php else: ?>
            <?php if (!empty($opts['show_editor'])): ?>
            <a class="<?= ipmi_app_nav_link_class('editor', $active_item); ?>"
               href="/Settings/IPMI"
               data-ipmi-settings-tab="tab3">Config editor</a>
            <?php endif; ?>
            <span class="ipmi-app-nav__hint">Use <strong>Export diagnostics</strong> for support bundles.</span>
        <?php endif; ?>
    </div>
</div>
    <?php
}

/**
 * @deprecated Use ipmi_render_app_nav(); kept for call-site compatibility.
 */
function ipmi_render_quick_nav_strip($active = '', $opts = []) {
    ipmi_render_app_nav($active, $opts);
}
