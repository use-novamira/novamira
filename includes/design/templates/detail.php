<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Novamira\Design\Admin;
use Novamira\Design\Contract;
use Novamira\Design\Library;
use Novamira\Design\Markdown;
use Novamira\Design\Parser;
use Novamira\Design\Store;
use Novamira\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

if (!Admin\current_user_can_manage()) {
    wp_die(__('You do not have permission to view this page.', domain: 'novamira'));
}

$view_param = $_GET['view'] ?? '';
$slug = Parser\normalize_slug(is_string($view_param) ? $view_param : '');
$design = $slug !== '' ? Library\find($slug) : null;
if ($design === null) {
    wp_die(__('Design not found.', domain: 'novamira'));
}
/** @var array{slug: string, name: string, description: string, content: string} $design */

$tokens = Tokens\extract($design['content']);
$inspection = Contract\inspect($design['content']);
$is_ready = $inspection['readiness']['ready'];
$dials = Tokens\dials($tokens);
$palette = Tokens\palette($design['content']);
$shadows = Tokens\prose_shadows($design['content'], $palette);
$animations = Tokens\prose_animations($design['content']);
$philosophy = Markdown\section($design['content'], [
    'overview',
    'philosophy',
    'design philosophy',
    'about',
    'introduction',
]);
$guidance = Markdown\guidance($design['content'], Contract\guidance_titles());
$has_guidance = $guidance['dos'] !== [] || $guidance['donts'] !== [] || $guidance['rest'] !== '';
$vars_style = Tokens\css_vars_string($tokens);
$accent = Tokens\css_vars($tokens)['--nd-accent'] ?? '';
$active_slug = Store\get_active_slug();
$is_active = $design['slug'] === $active_slug;
$action_url = admin_url('admin-post.php');
$gallery_url = admin_url('admin.php?page=' . Admin\PAGE_SLUG);

$edit_url = '';
$post = Store\find_user_post($design['slug']);
if ($post instanceof \WP_Post) {
    $edit_url = add_query_arg(['page' => Admin\PAGE_SLUG, 'design' => $post->ID], admin_url('admin.php'));
}
$page_style = $accent !== '' ? '--ds-accent:' . $accent : '';
?>
<?php novamira_render_admin_header(
    logo_file: 'novamira-design-logo.svg',
    logo_alt: 'Novamira Design',
    logo_width: 340,
    logo_height: 40,
); ?>
<div class="wrap novamira-design novamira-design-detail" style="<?php echo esc_attr($page_style); ?>">
    <a class="nd-detail-back" href="<?php echo esc_url($gallery_url); ?>">&larr; <?php esc_html_e(
        'All designs',
        domain: 'novamira',
    ); ?></a>

    <header class="nd-detail-head">
        <div class="nd-detail-headmain">
            <span class="nd-detail-kicker"><?php esc_html_e('Design', domain: 'novamira'); ?></span>
            <h1 class="nd-detail-title"><?php echo esc_html($design['name']); ?></h1>
            <?php if ($design['description'] !== ''): ?>
                <p class="nd-detail-desc"><?php echo esc_html($design['description']); ?></p>
            <?php endif; ?>
            <?php if (!$is_ready): ?>
                <span class="novamira-design-incomplete-badge"><?php esc_html_e(
                    'Incomplete',
                    domain: 'novamira',
                ); ?></span>
            <?php endif; ?>
            <?php $waivers = Novamira\Design\Preflight\waivers($design['content']); ?>
            <?php if ($waivers !== []): ?>
                <span class="novamira-design-allows"><?php echo
                    esc_html(sprintf(
                        /* translators: %s: list of anti-slop rules this design waives */
                        __('Allows: %s', domain: 'novamira'),
                        implode(' · ', $waivers),
                    ))
                ; ?><span class="novamira-design-allows-help" title="<?php echo
                    esc_attr__(
                        'Anti-slop rules this design intentionally waives. Novamira normally flags these AI tells; here they count as a deliberate house-style choice, not a mistake.',
                        domain: 'novamira',
                    )
                ; ?>">?</span></span>
            <?php endif; ?>
        </div>
        <div class="nd-detail-actions">
            <?php if ($is_active): ?>
                <span class="novamira-design-active-badge nd-detail-activebadge"><?php esc_html_e(
                    'Active',
                    domain: 'novamira',
                ); ?></span>
            <?php endif; ?>
            <?php if (!$is_active && $is_ready): ?>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('novamira_design_activate'); ?>
                    <input type="hidden" name="action" value="novamira_design_activate" />
                    <input type="hidden" name="slug" value="<?php echo esc_attr($design['slug']); ?>" />
                    <button type="submit" class="button button-primary"><?php esc_html_e(
                        'Activate',
                        domain: 'novamira',
                    ); ?></button>
                </form>
            <?php endif; ?>
            <?php if (!$is_active && !$is_ready): ?>
                <button type="button" class="button" disabled title="<?php echo
                    esc_attr(Contract\activation_error($inspection))
                ; ?>"><?php esc_html_e('Activate', domain: 'novamira'); ?></button>
            <?php endif; ?>
            <?php if ($edit_url !== ''): ?>
                <a class="button" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e(
                    'Edit',
                    domain: 'novamira',
                ); ?></a>
            <?php endif; ?>
            <button type="button" class="button" data-nd-copy-design><?php esc_html_e(
                'Copy DESIGN.md',
                domain: 'novamira',
            ); ?></button>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <?php wp_nonce_field('novamira_design_duplicate'); ?>
                <input type="hidden" name="action" value="novamira_design_duplicate" />
                <input type="hidden" name="slug" value="<?php echo esc_attr($design['slug']); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Duplicate', domain: 'novamira'); ?></button>
            </form>
        </div>
    </header>

    <section class="nd-detail-stage">
        <?php require __DIR__ . '/preview.php'; ?>
    </section>

    <?php if ($philosophy !== ''): ?>
        <section class="nd-detail-block nd-detail-philosophy">
            <span class="nd-detail-eyebrow"><?php esc_html_e('Philosophy', domain: 'novamira'); ?></span>
            <div class="nd-doc nd-doc-lead"><?php echo wp_kses_post($philosophy); ?></div>
        </section>
    <?php endif; ?>

    <div class="nd-detail-cols">
        <?php if ($palette !== []): ?>
            <section class="nd-detail-block">
                <h2><?php esc_html_e('Palette', domain: 'novamira'); ?> <span class="nd-detail-count"><?php echo
                    esc_html((string) count($palette))
                ; ?></span></h2>
                <div class="nd-palette">
                    <?php foreach ($palette as $name => $hex): ?>
                        <div class="nd-palette-chip">
                            <span class="nd-palette-swatch" style="background:<?php echo
                                esc_attr(Tokens\css_value($hex))
                            ; ?>"></span>
                            <span class="nd-palette-name"><?php echo esc_html($name); ?></span>
                            <span class="nd-palette-hex"><?php echo esc_html($hex); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($tokens['rounded'] !== [] || $tokens['spacing'] !== []): ?>
            <section class="nd-detail-block">
                <h2><?php esc_html_e('Shape & spacing', domain: 'novamira'); ?></h2>
                <div class="nd-shape" style="<?php echo esc_attr($vars_style); ?>">
                    <?php if ($tokens['rounded'] !== []): ?>
                        <div class="nd-shape-group">
                            <span class="nd-shape-group-label"><?php esc_html_e('Radius', domain: 'novamira'); ?></span>
                            <div class="nd-radius-row">
                                <?php foreach ($tokens['rounded'] as $k => $v):
                                    $radius = is_numeric($v) ? $v . 'px' : $v;
                                    ?>
                                    <div class="nd-radius-spec">
                                        <span class="nd-radius-box" style="border-radius:<?php echo
                                            esc_attr(Tokens\css_value($radius))
                                        ; ?>"></span>
                                        <span class="nd-shape-spec-label"><?php echo
                                            esc_html($k . ' · ' . $v)
                                        ; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($tokens['spacing'] as $k => $v):
                        $space_matches = [];
                        preg_match_all('/\d+(?:\.\d+)?/', $v, $space_matches);
                        $steps = $space_matches[0];
                        if ($steps === []) {
                            continue;
                        }
                        ?>
                        <div class="nd-shape-group">
                            <span class="nd-shape-group-label"><?php echo
                                esc_html(sprintf(
                                    /* translators: %s: spacing token name */
                                    __('Space %s', domain: 'novamira'),
                                    $k,
                                ))
                            ; ?></span>
                            <div class="nd-space-rows">
                                <?php foreach ($steps as $step):
                                    $bar_width = is_numeric($step) ? min((float) $step, 320.0) : 0.0;
                                    ?>
                                    <div class="nd-space-row">
                                        <span class="nd-space-num"><?php echo esc_html($step); ?></span>
                                        <span class="nd-space-bar" style="width:<?php echo
                                            esc_attr((string) $bar_width)
                                        ; ?>px"></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <section class="nd-detail-block">
        <h2><?php esc_html_e('Composition', domain: 'novamira'); ?></h2>
        <div class="nd-dials">
            <?php foreach (['variance', 'density', 'motion'] as $dial_key):
                $dial_pct = (int) round($dials[$dial_key] * 100);
                ?>
                <div class="nd-dial">
                    <div class="nd-dial-head">
                        <span class="nd-dial-label"><?php echo esc_html(ucfirst($dial_key)); ?></span>
                        <span class="nd-dial-value"><?php echo
                            esc_html(number_format($dials[$dial_key], decimals: 2))
                        ; ?></span>
                    </div>
                    <div class="nd-dial-track">
                        <span class="nd-dial-fill" style="width:<?php echo esc_attr((string) $dial_pct); ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="nd-dials-note"><?php esc_html_e(
                '0 = symmetric / airy / static.  1 = asymmetric / packed / kinetic.',
                domain: 'novamira',
            ); ?></p>
        </div>
    </section>

    <?php if ($tokens['typography'] !== []): ?>
        <section class="nd-detail-block">
            <h2><?php esc_html_e('Typography', domain: 'novamira'); ?></h2>
            <div class="nd-typeset">
                <?php foreach ($tokens['typography'] as $role => $props):
                    $family = $props['fontFamily'] ?? '';
                    $weight = $props['fontWeight'] ?? '';
                    $size = $props['fontSize'] ?? '';
                    $display = Tokens\display_px($size);
                    $sample_style = 'font-family:' . Tokens\css_value($family);
                    if ($weight !== '') {
                        $sample_style .= ';font-weight:' . Tokens\css_value($weight);
                    }
                    if ($display !== '') {
                        $sample_style .= ';font-size:' . $display . ';line-height:1.15';
                    }
                    ?>
                    <div class="nd-typespec">
                        <div class="nd-typespec-meta">
                            <code><?php echo esc_html($role); ?></code>
                            <span class="nd-typespec-family"><?php echo
                                esc_html($family !== '' ? $family : '—')
                            ; ?></span>
                            <?php if (trim($weight . ' ' . $size) !== ''): ?>
                                <span class="nd-typespec-num"><?php echo
                                    esc_html(trim($weight . ' ' . $size))
                                ; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="nd-typespec-sample" style="<?php echo
                            esc_attr($sample_style)
                        ; ?>">Ag · The quick brown fox</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="nd-detail-block">
        <h2><?php esc_html_e('Components', domain: 'novamira'); ?></h2>
        <div class="nd-components" style="<?php echo esc_attr($vars_style); ?>">
            <div class="nd-comp-row">
                <button type="button" class="nd-comp-btn nd-comp-primary"><?php esc_html_e(
                    'Primary',
                    domain: 'novamira',
                ); ?></button>
                <button type="button" class="nd-comp-btn nd-comp-secondary"><?php esc_html_e(
                    'Secondary',
                    domain: 'novamira',
                ); ?></button>
                <button type="button" class="nd-comp-btn nd-comp-ghost"><?php esc_html_e(
                    'Ghost',
                    domain: 'novamira',
                ); ?></button>
                <span class="nd-comp-badge"><?php esc_html_e('Badge', domain: 'novamira'); ?></span>
            </div>
            <input class="nd-comp-input" type="text" placeholder="<?php esc_attr_e(
                'Input field',
                domain: 'novamira',
            ); ?>" />
            <div class="nd-comp-card">
                <strong><?php esc_html_e('Card title', domain: 'novamira'); ?></strong>
                <span><?php esc_html_e(
                    'A small surface, rendered in this design system.',
                    domain: 'novamira',
                ); ?></span>
            </div>
        </div>
    </section>

    <?php if ($shadows !== []): ?>
        <section class="nd-detail-block">
            <h2><?php esc_html_e('Elevation', domain: 'novamira'); ?> <span class="nd-detail-count"><?php echo
                esc_html((string) count($shadows))
            ; ?></span></h2>
            <div class="nd-shadows" style="<?php echo esc_attr($vars_style); ?>">
                <?php foreach ($shadows as $shadow_name => $shadow): ?>
                    <div class="nd-shadow">
                        <span class="nd-shadow-swatch" style="box-shadow:<?php echo
                            esc_attr($shadow['css'])
                        ; ?>"></span>
                        <span class="nd-shadow-name"><?php echo esc_html($shadow_name); ?></span>
                        <span class="nd-shadow-spec"><?php echo esc_html($shadow['spec']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($has_guidance): ?>
        <section class="nd-detail-block nd-detail-doc">
            <h2><?php esc_html_e('Do\'s &amp; Don\'ts', domain: 'novamira'); ?></h2>
            <?php if ($guidance['rest'] !== ''): ?>
                <div class="nd-doc"><?php echo wp_kses_post($guidance['rest']); ?></div>
            <?php endif; ?>
            <?php if ($guidance['dos'] !== [] || $guidance['donts'] !== []): ?>
                <div class="nd-doc-split">
                    <?php if ($guidance['dos'] !== []): ?>
                        <div class="nd-doc-col">
                            <span class="nd-doc-col-label nd-doc-col-label--do"><?php esc_html_e(
                                'Do',
                                domain: 'novamira',
                            ); ?></span>
                            <ul class="nd-doc nd-doc-list">
                                <?php foreach ($guidance['dos'] as $item): ?>
                                    <li class="nd-doc-do"><?php echo wp_kses_post($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($guidance['donts'] !== []): ?>
                        <div class="nd-doc-col">
                            <span class="nd-doc-col-label nd-doc-col-label--dont"><?php esc_html_e(
                                'Don\'t',
                                domain: 'novamira',
                            ); ?></span>
                            <ul class="nd-doc nd-doc-list">
                                <?php foreach ($guidance['donts'] as $item): ?>
                                    <li class="nd-doc-dont"><?php echo wp_kses_post($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($animations !== []): ?>
        <section class="nd-detail-block">
            <h2><?php esc_html_e('Animations', domain: 'novamira'); ?> <span class="nd-detail-count"><?php echo
                esc_html((string) count($animations))
            ; ?></span></h2>
            <div class="nd-anims">
                <?php foreach ($animations as $anim_name => $anim_desc): ?>
                    <div class="nd-anim">
                        <span class="nd-anim-name"><?php echo esc_html($anim_name); ?></span>
                        <span class="nd-anim-desc"><?php echo esc_html($anim_desc); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="nd-detail-block">
        <details class="nd-detail-raw">
            <summary><?php esc_html_e('Raw DESIGN.md', domain: 'novamira'); ?></summary>
            <pre class="novamira-design-md nd-detail-md"><?php echo esc_html($design['content']); ?></pre>
        </details>
    </section>

    <?php if ($post instanceof \WP_Post): ?>
        <?php require __DIR__ . '/history.php'; ?>
    <?php endif; ?>
</div>
