<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Dashboard connect page — creates application passwords and shows MCP config samples.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Enable AI Abilities for the current site domain.
 */
function novamira_enable_ai_abilities(): bool
{
    if (function_exists('novamira_get_mcp_dependency_error') && novamira_get_mcp_dependency_error() !== null) {
        return false;
    }

    update_option(option: 'novamira_ai_abilities_enabled', value: '1');
    update_option(option: 'novamira_ai_abilities_domain', value: (string) wp_parse_url(home_url(), PHP_URL_HOST));
    return true;
}

/**
 * Disable AI Abilities and clear the domain lock.
 */
function novamira_disable_ai_abilities(): bool
{
    update_option(option: 'novamira_ai_abilities_enabled', value: '0');
    delete_option('novamira_ai_abilities_domain');
    return true;
}

/**
 * Handle the enable/disable AI Abilities toggle submission.
 * Returns true on save, null when no submission.
 */
function novamira_handle_toggle_enabled(): ?bool
{
    if (($_POST['novamira_submit'] ?? null) === null) {
        return null;
    }
    if (!novamira_current_user_can_manage()) {
        return null;
    }

    check_admin_referer('novamira_settings');

    $enabled = ($_POST['novamira_ai_abilities_enabled'] ?? null) !== null;
    return $enabled ? novamira_enable_ai_abilities() : novamira_disable_ai_abilities();
}

/**
 * Handle the admin-bar AI Abilities toggle.
 */
function novamira_handle_admin_bar_toggle(): void
{
    if (!novamira_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage Novamira settings.', domain: 'novamira'));
    }

    check_admin_referer('novamira_toggle_ai_abilities');

    $target = $_GET['novamira_target'] ?? '';
    $result = null;
    if ($target === 'on') {
        $result = novamira_enable_ai_abilities();
    }
    if ($target === 'off') {
        $result = novamira_disable_ai_abilities();
    }

    $redirect = wp_get_referer();
    if (!is_string($redirect) || $redirect === '') {
        $redirect = admin_url('admin.php?page=novamira-connect');
    }

    $redirect = add_query_arg([
        'novamira_toggle_result' => $result === true ? $target : 'failed',
    ], $redirect);

    wp_safe_redirect($redirect);
    exit();
}

function novamira_render_enable_toggle(): void
{
    $enabled = novamira_is_enabled();
    $dependency_error = function_exists('novamira_get_mcp_dependency_error')
        ? novamira_get_mcp_dependency_error()
        : null;
    $toggle_disabled = $dependency_error !== null && !$enabled;
    $submit_attributes = $toggle_disabled ? ['disabled' => 'disabled'] : [];
    $looks_production = novamira_looks_like_production();
    ?>
    <h2 class="novamira-step-heading">
        <span class="novamira-step-badge">1</span>
        <?php esc_html_e('Enable AI Abilities', domain: 'novamira'); ?>
    </h2>
    <form method="post" action="" id="novamira-settings-form" style="margin: 16px 0 0;">
        <?php wp_nonce_field('novamira_settings'); ?>
        <label style="display:flex; align-items:center; gap:10px; font-size:16px; font-weight:600; color:#1d2327; margin:0 0 12px;">
            <input type="checkbox" name="novamira_ai_abilities_enabled" value="1" id="novamira-enable-checkbox" style="width:18px; height:18px;" <?php checked(
                checked: $enabled,
                current: true,
            ); ?> <?php disabled($toggle_disabled); ?> />
            <span><?php esc_html_e('Turn on AI Abilities for this site', domain: 'novamira'); ?></span>
        </label>
        <p class="description" style="margin:0 0 8px;">
            <strong style="color:#d63638;"><?php esc_html_e('Security note:', domain: 'novamira'); ?></strong>
            <?php esc_html_e(
                'When enabled, AI agents can execute PHP code and perform filesystem operations on this site. For development and staging environments only. Always keep backups.',
                domain: 'novamira',
            ); ?>
        </p>
        <p class="description" style="margin:0 0 14px;">
            <?php esc_html_e(
                'Use Novamira with a capable AI model and set your client to ask for confirmation before every action. Read what the agent is about to do before approving.',
                domain: 'novamira',
            ); ?>
        </p>
        <?php submit_button(
            text: __('Save Settings', domain: 'novamira'),
            type: 'primary',
            name: 'novamira_submit',
            wrap: false,
            other_attributes: $submit_attributes,
        ); ?>
    </form>
    <script>
    document.getElementById('novamira-settings-form').addEventListener('submit', function (e) {
        var cb = document.getElementById('novamira-enable-checkbox');
        if (cb.checked && !cb.defaultChecked) {
            var msg = <?php echo
                wp_json_encode(
                    $looks_production
                        ? __(
                            'This looks like a production site. The plugin can stay installed here, but AI Abilities are not meant for live sites: enable them only on a staging or development copy. Continue anyway?',
                            domain: 'novamira',
                        )
                        : __(
                            'AI agents will be able to execute PHP code and access the filesystem. For development and staging environments only. Continue?',
                            domain: 'novamira',
                        ),
                )
            ; ?>;
            if (!confirm(msg)) {
                e.preventDefault();
            }
        }
    });
    </script>
    <?php
}

/**
 * Render the production-site warning banner above the enable toggle.
 *
 * Shown only when: AI Abilities are currently enabled AND the site looks like production
 * AND the current user has not dismissed the warning.
 */
function novamira_render_production_warning(): void
{
    if (!novamira_is_enabled()) {
        return;
    }
    if (!novamira_looks_like_production()) {
        return;
    }
    if (novamira_production_warning_dismissed()) {
        return;
    }
    ?>
    <div class="novamira-production-warning" role="alert">
        <p>
            <strong><?php esc_html_e('⚠️ This looks like a production site.', domain: 'novamira'); ?></strong>
            <?php esc_html_e(
                'Keeping the plugin installed here is fine, but AI Abilities should only be active on a staging or development copy. Make your changes there, then deploy the result the regular way. On production, keep AI Abilities off.',
                domain: 'novamira',
            ); ?>
        </p>
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('novamira_dismiss_production_warning'); ?>
            <button type="submit" name="novamira_dismiss_production_warning" class="button button-small">
                <?php esc_html_e('Dismiss', domain: 'novamira'); ?>
            </button>
        </form>
    </div>
    <?php
}

/**
 * Compute the default MCP server name from the current site host.
 *
 * Capped at 25 characters total ("novamira-" prefix + up to 16 chars of host slug)
 * because some MCP clients reject longer server names. Used as the placeholder default
 * when no name has been saved by the user.
 */
function novamira_get_mcp_server_name_default(): string
{
    /** @var string $site_host */
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST) ?? 'wordpress';
    $site_slug = (string) preg_replace(pattern: '/^www\./', replacement: '', subject: $site_host);
    $site_slug = (string) preg_replace(pattern: '/[^a-z0-9-]+/', replacement: '-', subject: strtolower($site_slug));
    $site_slug = trim($site_slug, characters: '-');
    $site_slug = substr($site_slug, offset: 0, length: 16);
    $site_slug = rtrim($site_slug, characters: '-');
    return 'novamira-' . $site_slug;
}

/**
 * Handle the "use existing password" form submission.
 *
 * Returns the pasted plaintext value (only for the current request — never persisted),
 * a WP_Error on validation failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function novamira_handle_use_existing_password()
{
    if (($_POST['novamira_use_existing_password'] ?? null) === null) {
        return null;
    }

    if (!novamira_current_user_can_manage()) {
        return new WP_Error('forbidden', __(
            'You do not have permission to use application passwords.',
            domain: 'novamira',
        ));
    }

    check_admin_referer('novamira_use_existing_password');

    $raw = $_POST['novamira_existing_password'] ?? '';
    $value = is_string($raw) ? trim($raw) : '';
    if ($value === '') {
        return new WP_Error('empty', __('Paste the application password value before submitting.', domain: 'novamira'));
    }
    if (strlen($value) < 16) {
        return new WP_Error('too_short', __(
            'That does not look like an application password. WordPress application passwords are at least 16 characters long.',
            domain: 'novamira',
        ));
    }
    return $value;
}

/**
 * Handle the create-password form submission.
 * Returns the plaintext password on success, a WP_Error on failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function novamira_handle_create_password()
{
    if (($_POST['novamira_create_password'] ?? null) === null) {
        return null;
    }

    if (!novamira_current_user_can_manage()) {
        return new WP_Error('forbidden', __(
            'You do not have permission to create application passwords.',
            domain: 'novamira',
        ));
    }

    check_admin_referer('novamira_create_password');

    $status = novamira_app_passwords_status();
    if (!$status['available']) {
        return new WP_Error('not_available', $status['message']);
    }

    $user_id = get_current_user_id();
    $raw_name = $_POST['novamira_password_name'] ?? '';
    $input_name = is_string($raw_name) ? trim($raw_name) : '';
    $app_name = $input_name !== '' ? 'Novamira: ' . $input_name : 'Novamira';

    // Avoid duplicate names — append a counter if one already exists.
    $existing = WP_Application_Passwords::get_user_application_passwords($user_id);
    $names = array_column($existing, 'name');
    if (in_array(needle: $app_name, haystack: $names, strict: true)) {
        $i = 2;
        while (in_array(needle: $app_name . ' ' . $i, haystack: $names, strict: true)) {
            $i++;
        }
        $app_name = $app_name . ' ' . $i;
    }

    $result = WP_Application_Passwords::create_new_application_password($user_id, ['name' => $app_name]);

    if (is_wp_error($result)) {
        return $result;
    }

    // $result[0] is the plaintext password.
    return $result[0];
}

/**
 * Handle the revoke-password form submission. Redirects on success.
 * Called from admin_init so headers have not been sent yet.
 */
function novamira_handle_revoke_password(): void
{
    if (($_POST['novamira_revoke_password'] ?? null) === null) {
        return;
    }

    if (!novamira_current_user_can_manage()) {
        return;
    }

    $uuid = $_POST['novamira_revoke_uuid'] ?? '';
    if (!is_string($uuid) || $uuid === '') {
        return;
    }

    check_admin_referer('novamira_revoke_password_' . $uuid);

    $user_id = get_current_user_id();
    WP_Application_Passwords::delete_application_password($user_id, $uuid);

    wp_safe_redirect(admin_url('admin.php?page=novamira-connect&novamira_result=revoked'));
    exit();
}

/**
 * Return all application passwords for the current user whose name begins with "Novamira".
 *
 * @return array<int, array<string, mixed>>
 */
function novamira_get_mcp_passwords(): array
{
    $user_id = get_current_user_id();
    $all = WP_Application_Passwords::get_user_application_passwords($user_id);
    return array_values(array_filter($all, static fn($item) => str_starts_with($item['name'], 'Novamira')));
}

/**
 * Render a single password row for the passwords table.
 *
 * @param array<string, mixed> $pw        Password item from WP_Application_Passwords.
 * @param string               $dt_format Date/time format string.
 */
function novamira_render_password_row(array $pw, string $dt_format): void
{
    $uuid = (string) ($pw['uuid'] ?? '');
    $name = (string) ($pw['name'] ?? '');
    $created_date = ($pw['created'] ?? null) !== null ? wp_date($dt_format, (int) $pw['created']) : false;
    $created = $created_date !== false ? $created_date : __('Unknown', domain: 'novamira');
    $last_used_date = ($pw['last_used'] ?? null) !== null ? wp_date($dt_format, (int) $pw['last_used']) : false;
    $last_used = $last_used_date !== false ? $last_used_date : __('Never', domain: 'novamira');
    $revoke_nonce = (string) wp_create_nonce('novamira_revoke_password_' . $uuid);
    ?>
    <tr>
        <td><strong><?php echo esc_html($name); ?></strong></td>
        <td><?php echo esc_html($created); ?></td>
        <td><?php echo esc_html($last_used); ?></td>
        <td>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo
                esc_js(__('Revoke this password? Any clients using it will lose access.', domain: 'novamira'))
            ; ?>');">
                <input type="hidden" name="novamira_revoke_uuid" value="<?php echo esc_attr($uuid); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($revoke_nonce); ?>" />
                <button type="submit" name="novamira_revoke_password" class="button button-small novamira-revoke-btn"><?php esc_html_e(
                    'Revoke',
                    domain: 'novamira',
                ); ?></button>
            </form>
        </td>
    </tr>
    <?php
}

/**
 * Render the "Step 2 — Application Password" card.
 *
 * Just the generate button (with a collapsible name input) and a success notice after generation.
 * The list of existing passwords lives in the separate manage section at the bottom of the page.
 */
// @mago-expect lint:cyclomatic-complexity
function novamira_render_password_step(
    ?string $new_password,
    ?string $existing_password = null,
    ?WP_Error $existing_error = null,
): void {
    $pw_status = novamira_app_passwords_status();
    $has_existing = novamira_get_mcp_passwords() !== [];
    $existing_section_open = $existing_password !== null || $existing_error !== null;
    ?>
    <h2 class="novamira-step-heading">
        <span class="novamira-step-badge">2</span>
        <?php esc_html_e('Application Password', domain: 'novamira'); ?>
    </h2>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'Generate an application password that your AI client will use to authenticate with WordPress. The password is embedded into the connection text in step 3.',
            domain: 'novamira',
        ); ?>
    </p>

    <?php if (!$pw_status['available']): ?>
        <div class="notice notice-error inline" style="margin:12px 0 16px;">
            <p><strong><?php echo esc_html($pw_status['message']); ?></strong></p>
            <?php if ($pw_status['reason'] === 'unsupported' && novamira_likely_local_http()): ?>
                <p style="margin:8px 0 0;">
                    <?php esc_html_e(
                        'This site is on a local hostname over HTTP. Add this line to your wp-config.php (above the "/* That\'s all" comment), then reload:',
                        domain: 'novamira',
                    ); ?>
                </p>
                <pre style="background:#f6f7f7; border:1px solid #c3c4c7; padding:8px 12px; margin:6px 0 0; font-size:13px; border-radius:3px;">define('WP_ENVIRONMENT_TYPE', 'local');</pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($new_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0 0 8px;"><?php esc_html_e(
                'Application password generated. It is now embedded in the connection text in step 3. Save it somewhere safe: it will not be shown in full again.',
                domain: 'novamira',
            ); ?></p>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <code id="novamira-new-pw-value" style="font-size:14px; font-weight:600; padding:6px 10px; background:#fff; border:1px solid #c3c4c7; border-radius:3px;"><?php echo
                    esc_html($new_password)
                ; ?></code>
                <button type="button" class="button button-small" onclick="novamiraCopy('novamira-new-pw-value', this)">
                    <?php esc_html_e('Copy password only', domain: 'novamira'); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($new_password === null && $existing_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0;"><?php esc_html_e(
                'Password accepted. It is now embedded in the connection text in step 3.',
                domain: 'novamira',
            ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" style="margin: 0;">
        <?php wp_nonce_field('novamira_create_password'); ?>
        <?php if (!$has_existing): ?>
            <p style="margin:0 0 10px;">
                <button
                    type="button"
                    class="button-link"
                    id="novamira-password-name-toggle"
                    aria-expanded="false"
                    aria-controls="novamira-password-name-field"
                    onclick="novamiraTogglePasswordName(this)"
                ><?php esc_html_e('Customize password name (optional)', domain: 'novamira'); ?></button>
            </p>
        <?php endif; ?>
        <div
            id="novamira-password-name-field"
            <?php echo $has_existing ? '' : 'hidden'; ?>
            style="margin: 0 0 12px; <?php echo $has_existing ? '' : 'display:none;'; ?>"
        >
            <label for="novamira-password-name" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Name', domain: 'novamira'); ?></strong>
            </label>
            <input
                type="text"
                id="novamira-password-name"
                name="novamira_password_name"
                placeholder="<?php esc_attr_e('e.g. Cursor on laptop, Claude Desktop', domain: 'novamira'); ?>"
                style="width:300px;"
                class="regular-text"
                maxlength="70"
            />
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'A label to identify this credential later. Leave blank to use "Novamira".',
                    domain: 'novamira',
                ); ?>
            </p>
        </div>
        <button
            type="submit"
            name="novamira_create_password"
            class="button button-primary"
            <?php echo !$pw_status['available'] ? 'disabled' : ''; ?>>
            <?php echo
                $has_existing
                    ? esc_html__('Generate another application password', domain: 'novamira')
                    : esc_html__('Generate application password', domain: 'novamira')
            ; ?>
        </button>
    </form>

    <p style="margin:14px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="novamira-use-existing-toggle"
            aria-expanded="<?php echo $existing_section_open ? 'true' : 'false'; ?>"
            aria-controls="novamira-use-existing-field"
            onclick="novamiraToggleUseExisting(this)"
        ><?php esc_html_e('I already have an application password', domain: 'novamira'); ?></button>
    </p>
    <div
        id="novamira-use-existing-field"
        <?php echo $existing_section_open ? '' : 'hidden'; ?>
        style="margin:6px 0 0; <?php echo $existing_section_open ? '' : 'display:none;'; ?>"
    >
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('novamira_use_existing_password'); ?>
            <label for="novamira-existing-password" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Paste the password value', domain: 'novamira'); ?></strong>
            </label>
            <input
                type="text"
                id="novamira-existing-password"
                name="novamira_existing_password"
                placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
                style="width:340px; font-family:monospace;"
                class="regular-text"
                autocomplete="off"
            />
            <button type="submit" name="novamira_use_existing_password" class="button">
                <?php esc_html_e('Use this password', domain: 'novamira'); ?>
            </button>
            <?php if ($existing_error !== null): ?>
                <div class="notice notice-error inline" style="margin:8px 0 0;">
                    <p style="margin:0;"><?php echo esc_html($existing_error->get_error_message()); ?></p>
                </div>
            <?php endif; ?>
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'For reusing an application password you already saved (e.g. from a password manager). It is used only to fill the connection text and never stored on this site.',
                    domain: 'novamira',
                ); ?>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Render the "Manage existing application passwords" collapsible section at the bottom of the page.
 *
 * Only meaningful when at least one Novamira-tagged password exists. Hosts the list with revoke
 * buttons. Used both when AI Abilities are enabled (revoke + create lives elsewhere) and when
 * disabled (revoke only).
 */
function novamira_render_manage_passwords_section(string $context = 'enabled'): void
{
    $mcp_passwords = novamira_get_mcp_passwords();
    if ($mcp_passwords === []) {
        return;
    }

    $dt_format = novamira_get_datetime_format('Y-m-d H:i');
    $count = count($mcp_passwords);
    $open_by_default = $count <= 3;
    /* translators: %d: count of existing application passwords */
    $summary = sprintf(
        _n(
            single: 'Manage existing application password (%d)',
            plural: 'Manage existing application passwords (%d)',
            number: $count,
            domain: 'novamira',
        ),
        $count,
    );
    ?>
    <details class="novamira-manage-passwords"<?php echo $open_by_default ? ' open' : ''; ?>>
        <summary class="novamira-manage-passwords-summary">
            <?php echo esc_html($summary); ?>
        </summary>
        <div class="novamira-manage-passwords-body">
            <?php if ($context === 'disabled'): ?>
                <p class="description" style="margin:0 0 12px;">
                    <?php esc_html_e(
                        'AI Abilities are disabled. These credentials remain valid for WordPress authentication, but the Novamira MCP endpoint will reject requests until AI Abilities are turned back on.',
                        domain: 'novamira',
                    ); ?>
                </p>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', domain: 'novamira'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Created', domain: 'novamira'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Last Used', domain: 'novamira'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Actions', domain: 'novamira'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mcp_passwords as $pw): ?>
                        <?php novamira_render_password_row($pw, $dt_format); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php
}

/**
 * Build the paste-to-agent paragraph displayed in Option A of the Connect section.
 *
 * Returns a plain-text block the user can copy and paste into their AI client / agent.
 * The MCP server name uses the same placeholder as the JSON snippets so the live JS
 * preview can swap it in without re-rendering the page.
 */
function novamira_build_paste_to_agent_paragraph(
    string $rest_url,
    string $username,
    string $display_password,
    string $name_placeholder = '__NOVAMIRA_MCP_NAME__',
    ?string $password_placeholder = null,
): string {
    $password_value = $password_placeholder ?? $display_password;
    $lines = [
        'I want to add this WordPress site as an MCP server to this AI client.',
        '',
        'Connection details:',
        '- Server URL: ' . $rest_url,
        '- Username: ' . $username,
        '- Application password: ' . $password_value,
        '- Server name to use in the config: ' . $name_placeholder,
        '- Transport: @automattic/mcp-wordpress-remote via npx',
        '',
        'Setup rules:',
        '- Pass credentials ONLY as env vars: WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD. Do NOT use CLI flags like --url or --password (the package ignores them).',
        '- args array must be exactly ["-y", "@automattic/mcp-wordpress-remote@latest"].'
            . (
                novamira_likely_self_signed_https()
                    ? "\n"
                    . '- Also set NODE_TLS_REJECT_UNAUTHORIZED="0" in env (this site uses a local self-signed TLS certificate).'
                    : ''
            ),
        '',
        'Don\'t ask me to confirm choices already specified above. After writing the config, restart or reload the MCP session (most clients require it), then verify by listing the server\'s tools. If it fails, show me the stderr from the npx process before proposing changes.',
        '',
        'If you cannot modify the config of this AI client from here, tell me to expand "Need the JSON config for a specific client?" on the Novamira Configuration page and copy the snippet manually.',
    ];

    return implode("\n", $lines);
}

/**
 * Build the npx server config array shared across multiple MCP clients.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @return array{command: string, args: list<string>, env: array<string, string>}
 */
function novamira_build_npx_server(string $rest_url, string $username, string $display_password): array
{
    $env = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (novamira_likely_self_signed_https()) {
        $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }
    return [
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => $env,
    ];
}

/** @param array<string, mixed> $npx_server */
function novamira_build_zed_json(string $mcp_name, array $npx_server, int $opts): string
{
    return (string) json_encode([
        'context_servers' => [
            $mcp_name => array_merge([
                'source' => 'custom',
                'enabled' => true,
            ], $npx_server),
        ],
    ], $opts);
}

function novamira_build_opencode_json(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
    int $opts,
): string {
    $environment = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (novamira_likely_self_signed_https()) {
        $environment['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }
    return (string) json_encode([
        'mcp' => [
            $mcp_name => [
                'type' => 'local',
                'command' => ['npx', '-y', '@automattic/mcp-wordpress-remote@latest'],
                'environment' => $environment,
            ],
        ],
    ], $opts);
}

function novamira_build_codex_toml(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $esc = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';

    $lines = [
        '[mcp_servers.' . $mcp_name . ']',
        'command = "npx"',
        'args = ["-y", "@automattic/mcp-wordpress-remote@latest"]',
        '',
        '[mcp_servers.' . $mcp_name . '.env]',
        'WP_API_URL = ' . $esc($rest_url),
        'WP_API_USERNAME = ' . $esc($username),
        'WP_API_PASSWORD = ' . $esc($display_password),
    ];
    if (novamira_likely_self_signed_https()) {
        $lines[] = 'NODE_TLS_REJECT_UNAUTHORIZED = "0"';
    }
    return implode("\n", $lines);
}

function novamira_build_claude_code_cmd(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";

    $parts = [
        'claude mcp add ' . $sq($mcp_name),
        '--env WP_API_URL=' . $sq($rest_url),
        '--env WP_API_USERNAME=' . $sq($username),
        '--env WP_API_PASSWORD=' . $sq($display_password),
    ];
    if (novamira_likely_self_signed_https()) {
        $parts[] = '--env NODE_TLS_REJECT_UNAUTHORIZED=' . $sq('0');
    }
    $parts[] = '-- npx -y @automattic/mcp-wordpress-remote@latest';

    return implode(" \\\n  ", $parts);
}

/**
 * Build all per-client, per-transport config entries.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @param string $mcp_name        MCP server name used as the config key.
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function novamira_build_configs(string $rest_url, string $username, string $display_password, string $mcp_name): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    $npx_server = novamira_build_npx_server($rest_url, $username, $display_password);
    $mcp_servers_json = (string) json_encode(['mcpServers' => [$mcp_name => $npx_server]], $opts);
    $vscode_servers_json = (string) json_encode(['servers' => [$mcp_name => $npx_server]], $opts);

    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'novamira');

    $special = [
        'claude-code' => [
            'code' => novamira_build_claude_code_cmd($mcp_name, $rest_url, $username, $display_password),
            'hint' => __('Run in your terminal.', domain: 'novamira'),
            'paths' => [],
            'isShell' => true,
        ],
        'codex' => [
            'code' => novamira_build_codex_toml($mcp_name, $rest_url, $username, $display_password),
            'hint' => sprintf($add_to, '<code>config.toml</code>'),
            'paths' => [
                'macOS / Linux' => '~/.codex/config.toml',
                'Windows' => '%USERPROFILE%\\.codex\\config.toml',
            ],
            'isShell' => false,
        ],
        'zed' => [
            'code' => novamira_build_zed_json($mcp_name, $npx_server, $opts),
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => ['macOS / Linux' => '~/.config/zed/settings.json'],
            'isShell' => false,
        ],
        'opencode' => [
            'code' => novamira_build_opencode_json($mcp_name, $rest_url, $username, $display_password, $opts),
            'hint' => sprintf($add_to, '<code>opencode.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => 'opencode.json',
                __('Global', domain: 'novamira') => '~/.config/opencode/opencode.json',
            ],
            'isShell' => false,
        ],
    ];

    return array_merge(novamira_build_standard_configs($mcp_servers_json, $vscode_servers_json), $special);
}

/**
 * Build per-client config entries that reuse the standard mcpServers/servers JSON payloads.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function novamira_build_standard_configs(string $mcp_servers_json, string $vscode_servers_json): array
{
    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'novamira');

    return [
        'claude-desktop' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>claude_desktop_config.json</code>'),
            'paths' => [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
            ],
            'isShell' => false,
        ],
        'cursor' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'novamira') => '~/.cursor/mcp.json',
                __('Project', domain: 'novamira') => '.cursor/mcp.json',
            ],
            'isShell' => false,
        ],
        'vscode' => [
            'code' => $vscode_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Workspace', domain: 'novamira') => '.vscode/mcp.json',
                __('User', domain: 'novamira') => __(
                    'Run: MCP: Open User Configuration (command palette)',
                    domain: 'novamira',
                ),
            ],
            'isShell' => false,
        ],
        'windsurf' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => [
                'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
            ],
            'isShell' => false,
        ],
        'cline' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>cline_mcp_settings.json</code>'),
            'paths' => [
                __('Via UI', domain: 'novamira') => __(
                    'Cline sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'novamira',
                ),
            ],
            'isShell' => false,
        ],
        'roo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => '.roo/mcp.json',
                __('Via UI', domain: 'novamira') => __(
                    'Roo Code sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'novamira',
                ),
            ],
            'isShell' => false,
        ],
        'kilo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => '.kilocode/mcp.json',
                __('Via UI', domain: 'novamira') => __(
                    'Kilo Code sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'novamira',
                ),
            ],
            'isShell' => false,
        ],
        'github-copilot' => [
            'code' => $vscode_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'novamira') => '.github/copilot/mcp.json',
            ],
            'isShell' => false,
        ],
        'amazon-q' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'novamira') => '~/.aws/amazonq/mcp.json',
                __('Project', domain: 'novamira') => '.amazonq/mcp.json',
            ],
            'isShell' => false,
        ],
        'gemini-cli' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => [
                __('Global', domain: 'novamira') => '~/.gemini/settings.json',
                __('Project', domain: 'novamira') => '.gemini/settings.json',
            ],
            'isShell' => false,
        ],
        'antigravity' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => [
                'macOS / Linux' => '~/.gemini/antigravity/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.gemini\\antigravity\\mcp_config.json',
            ],
            'isShell' => false,
        ],
    ];
}

/**
 * Render the tabbed MCP client config section.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 */
function novamira_render_config_section(string $rest_url, string $username, string $display_password): void
{
    $default_name = novamira_get_mcp_server_name_default();
    $name_placeholder = '__NOVAMIRA_MCP_NAME__';
    $pw_slot = '__NOVAMIRA_PW_SLOT__';
    $password_is_placeholder = hash_equals('YOUR-APP-PASSWORD', $display_password);
    $configs = novamira_build_configs($rest_url, $username, $display_password, $name_placeholder);
    $configs_json = (string) wp_json_encode($configs);

    $clients = [
        'claude-code' => 'Claude Code',
        'claude-desktop' => 'Claude Desktop',
        'codex' => 'Codex',
        'antigravity' => 'Antigravity',
        'cursor' => 'Cursor',
        'vscode' => 'VS Code',
        'github-copilot' => 'GitHub Copilot',
        'windsurf' => 'Windsurf',
        'cline' => 'Cline',
        'gemini-cli' => 'Gemini CLI',
        'roo-code' => 'Roo Code',
        'amazon-q' => 'Amazon Q',
        'zed' => 'Zed',
        'kilo-code' => 'Kilo Code',
        'opencode' => 'OpenCode',
    ];

    $copied_label = esc_js(__('Copied!', domain: 'novamira'));
    $paste_paragraph_initial = novamira_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $default_name,
    );
    $paste_paragraph_template = novamira_build_paste_to_agent_paragraph(
        $rest_url,
        $username,
        $display_password,
        $name_placeholder,
        $pw_slot,
    );
    ?>
    <h2 class="novamira-step-heading">
        <span class="novamira-step-badge">3</span>
        <?php esc_html_e('Connect Your AI Client', domain: 'novamira'); ?>
    </h2>
    <p style="margin:0 0 12px;">
        <?php esc_html_e('Copy the block below and paste it to your AI agent.', domain: 'novamira'); ?>
    </p>

    <?php if (novamira_likely_self_signed_https()): ?>
        <div class="notice notice-warning inline" style="margin:0 0 12px;">
            <p style="margin:0;">
                <strong><?php esc_html_e('Local HTTPS detected.', domain: 'novamira'); ?></strong>
                <?php esc_html_e(
                    'Your site uses HTTPS with a certificate that is not publicly trusted (normal for local development). The snippets below include a small flag so your AI client can connect anyway.',
                    domain: 'novamira',
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="novamira-paste-block">
        <div class="novamira-paste-content" id="novamira-paste-content">
            <pre id="novamira-paste-text"><?php echo esc_html($paste_paragraph_initial); ?></pre>
        </div>
        <div class="novamira-paste-actions">
            <button
                type="button"
                class="button-link"
                id="novamira-paste-expand"
                onclick="novamiraToggleExpandPaste(this)"
                aria-expanded="false"
                aria-controls="novamira-paste-content"
            ><?php esc_html_e('Show full text', domain: 'novamira'); ?></button>
            <button
                type="button"
                class="button button-primary"
                onclick="novamiraCopyPaste(this)"
            ><?php esc_html_e('Copy prompt', domain: 'novamira'); ?></button>
            <p
                id="novamira-paste-copied-warning"
                style="display:none; margin:0; color:#d63638; font-size:13px; font-weight:600;"
            >
                <?php esc_html_e(
                    "Don't share with anyone: it contains an application password that grants access to this WordPress site.",
                    domain: 'novamira',
                ); ?>
            </p>
        </div>
    </div>

    <p style="margin:14px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="novamira-server-name-toggle"
            aria-expanded="false"
            aria-controls="novamira-server-name-field"
            onclick="novamiraToggleServerName(this)"
        ><?php esc_html_e('Change server name (optional)', domain: 'novamira'); ?></button>
    </p>
    <div id="novamira-server-name-field" hidden style="display:none; margin: 6px 0 14px;">
        <input
            type="text"
            id="novamira-mcp-name"
            value="<?php echo esc_attr($default_name); ?>"
            placeholder="<?php echo esc_attr($default_name); ?>"
            maxlength="25"
            style="width:220px;"
            oninput="novamiraUpdateName(this.value)"
        >
        <p class="description" style="margin:6px 0 0;">
            <?php esc_html_e(
                'Editing here updates the connection text and JSON snippets below in real time. Each AI client config keeps its own name once saved on its side.',
                domain: 'novamira',
            ); ?>
        </p>
        <div id="novamira-name-warning" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
            <p style="margin:0;">
                <?php esc_html_e(
                    'Maximum 25 characters reached. Required for client compatibility.',
                    domain: 'novamira',
                ); ?>
            </p>
        </div>
        <div id="novamira-name-suggestion" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
            <p style="margin:0;">
                <?php esc_html_e(
                    'Tip: keep "novamira" in the name so you (and your AI agent) can tell this MCP server apart from others.',
                    domain: 'novamira',
                ); ?>
            </p>
        </div>
    </div>

    <p style="margin:6px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="novamira-manual-toggle"
            aria-expanded="false"
            aria-controls="novamira-manual-config"
            onclick="novamiraToggleManualConfig(this)"
        ><?php esc_html_e('Need the JSON config for a specific client?', domain: 'novamira'); ?></button>
    </p>

    <div id="novamira-manual-config" hidden style="display:none;">
        <p class="description" style="margin:0 0 12px;">
            <?php esc_html_e(
                'Select your AI client to copy the JSON snippet for its config file.',
                domain: 'novamira',
            ); ?>
        </p>

        <div class="novamira-client-tabs">
        <?php foreach ($clients as $key => $label): ?>
            <button
                type="button"
                class="novamira-client-tab novamira-manual-client-tab<?php echo
                    $key === 'claude-code' ? ' active' : ''
                ; ?>"
                onclick="novamiraSetClient('<?php echo esc_js($key); ?>', this)"
            ><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
        </div>

        <div class="novamira-tab-content" style="border-radius:4px;">
            <div class="novamira-config-block">
                <pre id="novamira-config-code"></pre>
                <button type="button" class="button novamira-copy-btn" onclick="novamiraCopyConfig(this)"><?php esc_html_e(
                    'Copy',
                    domain: 'novamira',
                ); ?></button>
            </div>
            <div id="novamira-config-footer" style="font-size:13px; color:#666; border-top: 1px solid #c3c4c7;">
                <div id="novamira-config-hint" style="padding: 10px 16px;"></div>
                <div id="novamira-config-paths" style="padding: 0 16px 10px;"></div>
            </div>
        </div>
    </div>

    <p style="margin:6px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="novamira-npxless-toggle"
            aria-expanded="false"
            aria-controls="novamira-npxless-config"
            onclick="novamiraToggleNpxlessConfig(this)"
        ><?php esc_html_e('Need a configuration not requiring npx?', domain: 'novamira'); ?></button>
    </p>

    <div id="novamira-npxless-config" hidden style="display:none;">
        <p class="description" style="margin:0 0 12px;">
            <?php esc_html_e(
                'Copy this configuration snippet to connect using direct HTTP (no Node/npx required).',
                domain: 'novamira',
            ); ?>
        </p>

        <div class="novamira-client-tabs">
            <button
                type="button"
                class="novamira-client-tab novamira-npxless-client-tab active"
                onclick="novamiraSetNpxlessClient('claude', this)"
            ><?php esc_html_e('Claude', domain: 'novamira'); ?></button>
            <button
                type="button"
                class="novamira-client-tab novamira-npxless-client-tab"
                onclick="novamiraSetNpxlessClient('codex', this)"
            ><?php esc_html_e('Codex', domain: 'novamira'); ?></button>
        </div>

        <div class="novamira-tab-content" style="border-radius:4px;">
            <div class="novamira-config-block">
                <pre id="novamira-npxless-code"></pre>
                <button type="button" class="button novamira-copy-btn" onclick="novamiraCopyNpxlessConfig(this)"><?php esc_html_e(
                    'Copy',
                    domain: 'novamira',
                ); ?></button>
            </div>
            <div id="novamira-npxless-footer" style="font-size:13px; color:#666; border-top: 1px solid #c3c4c7;">
                <div id="novamira-npxless-hint" style="padding: 10px 16px;">
                    <?php esc_html_e('Add to your project’s .mcp.json file.', domain: 'novamira'); ?>
                </div>
                <div id="novamira-npxless-paths" style="padding: 0 16px 10px;"></div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var configs = <?php echo $configs_json; ?>;
        var client = 'claude-code';
        var defaultName = <?php echo wp_json_encode($default_name); ?>;
        var pasteTemplate = <?php echo wp_json_encode($paste_paragraph_template); ?>;
        var mcpName = <?php echo wp_json_encode($default_name); ?>;
        var npxlessClient = 'claude';
        var namePlaceholder = <?php echo wp_json_encode($name_placeholder); ?>;
        var passwordSentinel = <?php echo wp_json_encode($pw_slot); ?>;
        var passwordValue = <?php echo wp_json_encode($display_password); ?>;
        var passwordIsPlaceholder = <?php echo wp_json_encode($password_is_placeholder); ?>;
        var usernameValue = <?php echo wp_json_encode($username); ?>;

        function renderPaste() {
            var text = pasteTemplate.split(namePlaceholder).join(mcpName);
            var container = document.getElementById('novamira-paste-text');
            container.textContent = '';
            var idx = text.indexOf(passwordSentinel);
            if (idx === -1) {
                container.appendChild(document.createTextNode(text));
                return;
            }
            container.appendChild(document.createTextNode(text.substring(0, idx)));
            if (passwordIsPlaceholder) {
                var span = document.createElement('span');
                span.className = 'novamira-placeholder';
                span.textContent = 'YOUR-APP-PASSWORD';
                container.appendChild(span);
            } else {
                container.appendChild(document.createTextNode(passwordValue));
            }
            container.appendChild(document.createTextNode(text.substring(idx + passwordSentinel.length)));
        }

        function render() {
            renderConfig();
            renderPaste();
            renderNpxlessConfig();
        }

        function renderConfig() {
            var cfg = configs[client];
            if (!cfg) { return; }

            var code = cfg.code.split(namePlaceholder).join(mcpName);
            var codeEl = document.getElementById('novamira-config-code');
            codeEl.textContent = code;
            if (code.indexOf('YOUR-APP-PASSWORD') !== -1) {
                codeEl.innerHTML = codeEl.innerHTML.replace(
                    /YOUR-APP-PASSWORD/g,
                    '<span class="novamira-placeholder">YOUR-APP-PASSWORD</span>'
                );
            }
            document.getElementById('novamira-config-hint').innerHTML = cfg.hint;

            var pathsEl = document.getElementById('novamira-config-paths');
            var keys = Object.keys(cfg.paths);
            if (keys.length > 0) {
                var html = '<ul style="margin:4px 0 0; padding-left:20px;">';
                keys.forEach(function (label) {
                    html += '<li><strong>' + label + '</strong>: <code>' + cfg.paths[label] + '</code></li>';
                });
                html += '</ul>';
                pathsEl.innerHTML = html;
                pathsEl.style.display = '';
            } else {
                pathsEl.innerHTML = '';
                pathsEl.style.display = 'none';
            }
        }

        window.novamiraSetClient = function (key, btn) {
            client = key;
            document.querySelectorAll('.novamira-manual-client-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            renderConfig();
        };

        window.novamiraSetNpxlessClient = function (key, btn) {
            npxlessClient = key;
            document.querySelectorAll('.novamira-npxless-client-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            renderNpxlessConfig();
        };

        function updateNameWarning(value) {
            var warning = document.getElementById('novamira-name-warning');
            warning.style.display = value.length >= 25 ? 'block' : 'none';

            var suggestion = document.getElementById('novamira-name-suggestion');
            var trimmed = value.trim();
            var missingNovamira = trimmed.length > 0 && trimmed.toLowerCase().indexOf('novamira') === -1;
            suggestion.style.display = missingNovamira ? 'block' : 'none';
        }

        window.novamiraUpdateName = function (value) {
            mcpName = value.trim() || defaultName;
            updateNameWarning(value);
            render();
        };

        window.novamiraToggleServerName = function (btn) {
            var field = document.getElementById('novamira-server-name-field');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                field.style.display = 'none';
                field.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                field.style.display = 'block';
                field.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                var input = document.getElementById('novamira-mcp-name');
                if (input) { input.focus(); }
            }
        };

        window.novamiraToggleManualConfig = function (btn) {
            var panel = document.getElementById('novamira-manual-config');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                panel.style.display = 'none';
                panel.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                panel.style.display = '';
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        };

        window.novamiraToggleExpandPaste = function (btn) {
            var content = document.getElementById('novamira-paste-content');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                content.classList.remove('is-expanded');
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = <?php echo wp_json_encode(__('Show full text', domain: 'novamira')); ?>;
            } else {
                content.classList.add('is-expanded');
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = <?php echo wp_json_encode(__('Show less', domain: 'novamira')); ?>;
            }
        };

        window.novamiraCopyPaste = function (btn) {
            navigator.clipboard.writeText(document.getElementById('novamira-paste-text').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                var warning = document.getElementById('novamira-paste-copied-warning');
                if (warning) { warning.style.display = 'block'; }
                setTimeout(function () {
                    btn.textContent = orig;
                    if (warning) { warning.style.display = 'none'; }
                }, 4000);
            });
        };

        window.novamiraCopyConfig = function (btn) {
            navigator.clipboard.writeText(document.getElementById('novamira-config-code').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        window.novamiraToggleNpxlessConfig = function (btn) {
            var panel = document.getElementById('novamira-npxless-config');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                panel.style.display = 'none';
                panel.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            } else {
                panel.style.display = '';
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        };

        window.novamiraCopyNpxlessConfig = function (btn) {
            navigator.clipboard.writeText(document.getElementById('novamira-npxless-code').textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = '<?php echo $copied_label; ?>';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        function renderNpxlessConfig() {
            var npxlessCodeEl = document.getElementById('novamira-npxless-code');
            if (!npxlessCodeEl) { return; }

            var serverName = mcpName;
            var url = <?php echo wp_json_encode($rest_url); ?>;
            var username = usernameValue;

            var authHeaderValue;
            if (passwordIsPlaceholder) {
                authHeaderValue = 'Basic <span class="novamira-placeholder">BASE64_ENCODED_CREDENTIALS</span>';
            } else {
                var pwClean = passwordValue.replace(/\s+/g, '');
                var encoded = window.btoa(username + ':' + pwClean);
                authHeaderValue = 'Basic ' + encoded;
            }

            var indent = '  ';
            var hintEl = document.getElementById('novamira-npxless-hint');
            var pathsEl = document.getElementById('novamira-npxless-paths');
            var placeholder = 'BASE64_ENCODED_CREDENTIALS';
            var jsonQuote = function (value) {
                return JSON.stringify(value);
            };
            var tomlQuote = function (value) {
                return '"' + value.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
            };
            var code;

            if (npxlessClient === 'codex') {
                code = '[mcp_servers.' + serverName + ']\n' +
                    'url = ' + tomlQuote(url) + '\n' +
                    'http_headers = { Authorization = ' + tomlQuote(authHeaderValue.replace(/<[^>]+>/g, '')) + ' }';
                hintEl.textContent = <?php echo
                    wp_json_encode(__('Add to your project’s .codex/config.toml file.', domain: 'novamira'))
                ; ?>;
                pathsEl.innerHTML = '<ul style="margin:4px 0 0; padding-left:20px;">' +
                    '<li><strong><?php echo
                        esc_js(__('Project', domain: 'novamira'))
                    ; ?></strong>: <code>.codex/config.toml</code></li>' +
                    '<li><strong><?php echo
                        esc_js(__('Global', domain: 'novamira'))
                    ; ?></strong>: <code>~/.codex/config.toml</code></li>' +
                    '</ul>';
            } else {
                code = '{\n' +
                    indent + '"mcpServers": {\n' +
                    indent + indent + jsonQuote(serverName) + ': {\n' +
                    indent + indent + indent + '"type": "http",\n' +
                    indent + indent + indent + '"url": ' + jsonQuote(url) + ',\n' +
                    indent + indent + indent + '"headers": {\n' +
                    indent + indent + indent + indent + '"Authorization": ' + jsonQuote(authHeaderValue.replace(/<[^>]+>/g, '')) + '\n' +
                    indent + indent + indent + '}\n' +
                    indent + indent + '}\n' +
                    indent + '}\n' +
                    '}';
                hintEl.textContent = <?php echo
                    wp_json_encode(__('Add to your project’s .mcp.json file.', domain: 'novamira'))
                ; ?>;
                pathsEl.innerHTML = '<ul style="margin:4px 0 0; padding-left:20px;">' +
                    '<li><strong><?php echo
                        esc_js(__('Project', domain: 'novamira'))
                    ; ?></strong>: <code>.mcp.json</code></li>' +
                    '</ul>';
            }

            npxlessCodeEl.textContent = code;
            if (passwordIsPlaceholder) {
                npxlessCodeEl.innerHTML = npxlessCodeEl.innerHTML.replace(
                    placeholder,
                    '<span class="novamira-placeholder">' + placeholder + '</span>'
                );
            }
        }

        render();
    }());
    </script>
    <?php
}

function novamira_render_mcp_dependency_inline_notice(?WP_Error $dependency_error): void
{
    if ($dependency_error === null) {
        return;
    }

    ?>
    <div class="novamira-mcp-error-panel" role="alert">
        <h2><?php esc_html_e('Novamira cannot expose MCP', domain: 'novamira'); ?></h2>
        <p><?php echo esc_html($dependency_error->get_error_message()); ?></p>
    </div>
    <?php
}

function novamira_render_enable_prompt(?WP_Error $dependency_error): void
{
    if (novamira_is_enabled() || $dependency_error !== null) {
        return;
    }

    ?>
    <p style="color:#666; font-size:14px;">
        <?php esc_html_e(
            'Enable AI Abilities above to create application passwords and connect an MCP client.',
            domain: 'novamira',
        ); ?>
    </p>
    <?php
}

/**
 * Render the connect / setup dashboard page.
 */
// @mago-expect lint:cyclomatic-complexity
function novamira_render_connect_page(): void
{
    if (!novamira_current_user_can_manage()) {
        return;
    }

    $mcp_dependency_error = novamira_get_mcp_dependency_error();
    $toggle_saved = novamira_handle_toggle_enabled();
    $enabled = novamira_is_enabled();
    $mcp_ready = $enabled && $mcp_dependency_error === null;

    $password_result = $mcp_ready ? novamira_handle_create_password() : null;
    $create_error = is_wp_error($password_result) ? $password_result : null;
    $new_password = is_string($password_result) ? $password_result : null;

    $existing_result = $mcp_ready ? novamira_handle_use_existing_password() : null;
    $existing_error = is_wp_error($existing_result) ? $existing_result : null;
    $existing_password = is_string($existing_result) ? $existing_result : null;

    $result_message = match ($_GET['novamira_result'] ?? null) {
        'revoked' => __('Application password revoked.', domain: 'novamira'),
        default => null,
    };

    $current_user = wp_get_current_user();
    $username = $current_user->user_login;
    $rest_url = rest_url('mcp/novamira');
    $display_password = $new_password ?? $existing_password ?? 'YOUR-APP-PASSWORD';

    $copied_label = esc_js(__('Copied!', domain: 'novamira'));

    ?>
    <style>
        .novamira-connect-section {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 6px;
            padding: 20px 24px;
            margin: 0 0 20px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.03);
        }
        .novamira-step-heading {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 12px;
            font-size: 18px;
            font-weight: 600;
            color: #1d2327;
        }
        .novamira-step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #1d2327;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            flex: 0 0 auto;
        }
        .novamira-config-block { position: relative; }
        .novamira-config-block pre {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 16px;
            border-radius: 0 4px 0 0;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.5;
            margin: 0;
        }
        .novamira-copy-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
        }
        .novamira-password-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff8e1;
            border: 1px solid #f0c040;
            border-radius: 4px;
            padding: 12px 16px;
            margin: 12px 0;
        }
        .novamira-password-value {
            font-family: monospace;
            font-size: 18px;
            letter-spacing: 1px;
            font-weight: bold;
        }
        .novamira-client-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
        .novamira-client-tab {
            padding: 5px 14px;
            border: 1px solid #c3c4c7;
            background: #f6f7f7;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
        }
        .novamira-client-tab.active {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
            font-weight: 600;
        }
        .novamira-tab-content { border: 1px solid #c3c4c7; border-radius: 4px; }
        .novamira-revoke-btn { color: #d63638 !important; border-color: #d63638 !important; }
        .novamira-placeholder { background: #d63638; color: #fff; padding: 1px 4px; border-radius: 3px; }
        .novamira-mcp-error-panel {
            background: #fff;
            border-left: 4px solid #d63638;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
            margin: 16px 0 24px;
            padding: 12px 16px;
        }
        .novamira-mcp-error-panel h2 {
            color: #1d2327;
            font-size: 16px;
            line-height: 1.4;
            margin: 0 0 8px;
        }
        .novamira-mcp-error-panel p {
            font-size: 14px;
            margin: 0;
        }
        .novamira-production-warning {
            background: #fff8e1;
            border-left: 4px solid #f0c040;
            padding: 12px 16px;
            margin: 12px 0 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .novamira-production-warning p {
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
            flex: 1 1 auto;
        }
        .novamira-paste-block {
            margin: 12px 0;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            overflow: hidden;
        }
        .novamira-paste-header {
            background: #1d2327;
            color: #fff;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .novamira-paste-content {
            position: relative;
            background: #f6f7f7;
        }
        .novamira-paste-content pre {
            background: transparent;
            color: #1d2327;
            padding: 16px;
            border: none;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
            max-height: 6.5em;
            overflow: hidden;
        }
        .novamira-paste-content.is-expanded pre {
            max-height: none;
            overflow: visible;
        }
        .novamira-paste-content:not(.is-expanded)::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 32px;
            background: linear-gradient(to bottom, rgba(246, 247, 247, 0), rgba(246, 247, 247, 1));
            pointer-events: none;
        }
        .novamira-paste-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 14px 14px;
            background: #fff;
            border-top: 1px solid #c3c4c7;
        }
        .novamira-manage-passwords {
            margin: 20px 0 0;
            border-top: 1px solid #e0e0e0;
            padding-top: 16px;
        }
        .novamira-manage-passwords-summary {
            font-weight: 600;
            cursor: pointer;
            list-style: none;
            color: #1d2327;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
        }
        .novamira-manage-passwords-summary::-webkit-details-marker { display: none; }
        .novamira-manage-passwords-summary::before {
            content: '▸';
            color: #646970;
            transition: transform 0.15s;
        }
        .novamira-manage-passwords[open] .novamira-manage-passwords-summary::before {
            transform: rotate(90deg);
        }
        .novamira-manage-passwords-body {
            padding-top: 12px;
        }
    </style>

    <?php novamira_render_admin_header(); ?>
    <div class="wrap">
        <h1><?php esc_html_e('Configuration', domain: 'novamira'); ?></h1>

        <?php novamira_render_mcp_dependency_inline_notice($mcp_dependency_error); ?>

        <?php if ($toggle_saved === true): ?>
            <div class="notice notice-success is-dismissible"><p><?php

            esc_html_e('Settings saved.', domain: 'novamira');
            ?></p></div>
        <?php endif; ?>

        <?php novamira_render_production_warning(); ?>

        <div class="novamira-connect-section">
            <?php novamira_render_enable_toggle(); ?>
        </div>

        <?php novamira_render_enable_prompt($mcp_dependency_error); ?>
        <?php if ($mcp_ready): ?>
            <?php if ($create_error !== null): ?>
                <div class="notice notice-error"><p><?php

                echo esc_html($create_error->get_error_message());
                ?></p></div>
            <?php endif; ?>

            <?php if ($result_message !== null): ?>
                <div class="notice notice-success is-dismissible"><p><?php

                echo esc_html($result_message);
                ?></p></div>
            <?php endif; ?>

            <div class="novamira-connect-section">
                <?php novamira_render_password_step($new_password, $existing_password, $existing_error); ?>
                <?php novamira_render_manage_passwords_section(); ?>
            </div>

            <?php if ($new_password !== null || $existing_password !== null): ?>
                <div class="novamira-connect-section">
                    <?php novamira_render_config_section($rest_url, $username, $display_password); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$mcp_ready && novamira_get_mcp_passwords() !== []): ?>
            <div class="novamira-connect-section">
                <h2 class="novamira-step-heading">
                    <span class="novamira-step-badge">2</span>
                    <?php esc_html_e('Application Password', domain: 'novamira'); ?>
                </h2>
                <?php novamira_render_manage_passwords_section(context: 'disabled'); ?>
            </div>
        <?php endif; ?>

    </div>

    <script>
    function novamiraCopy(id, btn) {
        var text = document.getElementById(id).textContent;
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = '<?php echo $copied_label; ?>';
            setTimeout(function() { btn.textContent = orig; }, 1500);
        });
    }
    function novamiraTogglePasswordName(btn) {
        var field = document.getElementById('novamira-password-name-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            field.style.display = 'none';
            field.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        } else {
            field.style.display = 'block';
            field.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var input = document.getElementById('novamira-password-name');
            if (input) { input.focus(); }
        }
    }
    function novamiraToggleUseExisting(btn) {
        var field = document.getElementById('novamira-use-existing-field');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
            field.style.display = 'none';
            field.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        } else {
            field.style.display = 'block';
            field.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            var input = document.getElementById('novamira-existing-password');
            if (input) { input.focus(); }
        }
    }
    </script>
    <?php
}
