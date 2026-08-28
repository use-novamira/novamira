<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Design-system smoke: ability registration, pre-flight rule semantics,
// declared overrides, direction non-inheritance, and the pre-flight cost.
// Runs against a real WP install (Abilities API in core) via wp-cli:
//   wp eval-file tests/design-smoke/01-design-smoke.php
// Restores the previously-active design and deletes its fixtures on exit.

if (!defined('ABSPATH')) {
    exit('Run from wp-cli only');
}

// wp-cli runs as user 0 by default; novamira_permission_callback requires manage_options.
$admin = get_user_by('login', 'admin');
if ($admin) {
    wp_set_current_user($admin->ID);
} else {
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    if (!empty($admins)) {
        wp_set_current_user($admins[0]->ID);
    }
}

global $RESULTS;
$RESULTS = [];

function check(string $name, bool $pass, string $detail = ''): void
{
    global $RESULTS;
    $RESULTS[] = ($pass ? 'PASS' : 'FAIL') . " | $name" . ($detail !== '' ? " | $detail" : '');
}

function ab(string $slug, array $input = [])
{
    $a = wp_get_ability($slug);
    if ($a === null) {
        return new WP_Error('missing', "$slug not registered");
    }
    return $a->execute($input);
}

/** @return list<string> rule:severity pairs */
function rules(mixed $r): array
{
    if (is_wp_error($r)) {
        return ['wp_error:' . $r->get_error_code()];
    }
    return array_values(array_map(
        static fn(array $v): string => $v['rule'] . ':' . $v['severity'],
        $r['violations'] ?? [],
    ));
}

/** @return list<string> the readiness warnings about Do/Don't guidance */
function guidance_warnings(mixed $r): array
{
    $warnings = is_wp_error($r) ? [] : $r['readiness']['warnings'] ?? [];
    return array_values(array_filter(
        $warnings,
        static fn(string $warning): bool => str_contains($warning, "Do's and Don'ts section") || str_contains($warning, "no Do/Don't items"),
    ));
}

function severity_of(mixed $r, string $rule): string
{
    foreach ((is_wp_error($r) ? [] : $r['violations'] ?? []) as $v) {
        if ($v['rule'] === $rule) {
            return $v['severity'];
        }
    }
    return '';
}

// ---------- 1. Registration ----------
$design = ['get-active-design', 'save-design', 'activate-design', 'list-design-library', 'get-design', 'delete-design', 'check-design'];
$reg = array_values(array_filter($design, static fn(string $a): bool => wp_get_ability("novamira/$a") !== null));
check('registration: 7/7 design abilities', count($reg) === 7, count($reg) . '/7: ' . implode(',', $reg));

$skills = ['skill-get', 'skill-write', 'skill-edit', 'skill-delete'];
$sreg = array_values(array_filter($skills, static fn(string $a): bool => wp_get_ability("novamira/$a") !== null));
check('registration: 4/4 skill abilities', count($sreg) === 4, count($sreg) . '/4');

// Preserve pre-existing state for restore.
$prev_active = get_option('novamira_active_design', '');

// ---------- Candidate outputs ----------
$out_purple_inter = '<section style="background: linear-gradient(135deg, #8b5cf6, #6d28d9); font-family: Inter, sans-serif"><h1>Studio</h1></section>';
$out_ghisa = '<div style="background:#EDE6D6; color:#2a2119"><a style="color:#B4603A">Contatti</a></div>';
$out_cobalt = '<div style="background:#f6f1e7; color:#111418"><a style="color:#0047ab">Contatti</a></div>';
$out_emerald = '<div style="background:#fafafa"><a style="color:#0f7a4d">Vai</a></div>';
$out_terracotta_slate = '<div style="background:#40505c"><a style="color:#c96f4a">Vai</a></div>';
$out_emdash = '<p>Design — costruito bene</p>';
$out_endash = '<p>Aperto 2019–2024</p>';
$out_purple_flat = '<span style="color:#8b5cf6">badge</span>';
$out_purple_flat_plus_gradient = '<span style="color:#8b5cf6">badge</span><div style="background: linear-gradient(#0047ab, #ffffff)">hero</div>';

// ---------- 2. Universal rules, no active design ----------
update_option('novamira_active_design', '');

$r = ab('novamira/check-design', ['output' => $out_purple_inter]);
$fails = array_values(array_filter(is_wp_error($r) ? [] : $r['violations'], static fn(array $v): bool => $v['severity'] === 'fail'));
$frules = array_map(static fn(array $v): string => $v['rule'], $fails);
check(
    'purple gradient + Inter, no design: ok=false, fails = ai-purple + inter-font',
    !is_wp_error($r) && $r['ok'] === false && count($fails) === 2 && in_array('ai-purple', $frules, true) && in_array('inter-font', $frules, true),
    json_encode(array_values($frules)),
);

$r = ab('novamira/check-design', ['output' => $out_emdash]);
check('em-dash: fail', !is_wp_error($r) && $r['ok'] === false && in_array('em-dash:fail', rules($r), true), json_encode(rules($r)));

$r = ab('novamira/check-design', ['output' => $out_endash]);
check('en-dash in a date range: clean (not the AI tell)', !is_wp_error($r) && $r['ok'] === true && rules($r) === [], json_encode(rules($r)));

$r = ab('novamira/check-design', ['output' => $out_ghisa]);
check('warm-craft gestalt (cream + brass): warn, ok=true', !is_wp_error($r) && $r['ok'] === true && in_array('warm-craft-palette:warn', rules($r), true), json_encode(rules($r)));

foreach ([['cobalt + cream', $out_cobalt], ['emerald', $out_emerald], ['terracotta + slate', $out_terracotta_slate]] as [$name, $o]) {
    $r = ab('novamira/check-design', ['output' => $o]);
    check("$name: clean", !is_wp_error($r) && $r['ok'] === true && rules($r) === [], json_encode(rules($r)));
}

// ai-purple escalation semantics: fail only when the purple sits inside a gradient.
$r = ab('novamira/check-design', ['output' => $out_purple_flat]);
check('flat purple, no gradient: ai-purple warn, ok=true', !is_wp_error($r) && $r['ok'] === true && severity_of($r, 'ai-purple') === 'warn', json_encode(rules($r)));

$r = ab('novamira/check-design', ['output' => $out_purple_flat_plus_gradient]);
check('flat purple + non-purple gradient elsewhere: ai-purple stays warn', !is_wp_error($r) && $r['ok'] === true && severity_of($r, 'ai-purple') === 'warn', json_encode(rules($r)));

$r = ab('novamira/check-design', ['output' => $out_purple_inter]);
check('purple inside the gradient: ai-purple fail', !is_wp_error($r) && severity_of($r, 'ai-purple') === 'fail', json_encode(rules($r)));

// ---------- 3. Contract readiness ----------
$design_incomplete = "---\nname: Incomplete\ncolors:\n  bg: '#ffffff'\n---\n# Incomplete\n";
$r = ab('novamira/save-design', ['slug' => 'smoke-incomplete', 'content' => $design_incomplete, 'activate' => true]);
check(
    'incomplete design is saved as a draft but activation is blocked',
    !is_wp_error($r)
    && ($r['saved'] ?? false) === true
    && ($r['activated'] ?? true) === false
    && ($r['activation_blocked'] ?? false) === true
    && ($r['readiness']['ready'] ?? true) === false,
    is_wp_error($r) ? $r->get_error_message() : json_encode($r['readiness'] ?? null),
);
check(
    'save-design warns that the draft has no guidance section',
    count(guidance_warnings($r)) === 1 && str_contains(guidance_warnings($r)[0], 'No Do\'s and Don\'ts section'),
    json_encode(is_wp_error($r) ? $r->get_error_message() : $r['readiness']['warnings'] ?? null),
);
$r = ab('novamira/activate-design', ['slug' => 'smoke-incomplete']);
check(
    'activate-design rejects an incomplete draft',
    is_wp_error($r) && $r->get_error_code() === 'design_not_ready',
    is_wp_error($r) ? $r->get_error_code() : json_encode($r),
);

// ---------- 4. Declared overrides ----------
$design_purple = "---\ncolors:\n  ground: \"#f7f7f9\"\n  ink: \"#17151d\"\n  accent: \"#7c3aed\"\ntypography:\n  heading:\n    fontFamily: \"Inter, sans-serif\"\n  body:\n    fontFamily: \"Inter, sans-serif\"\nspacing:\n  md: 16px\nrounded:\n  md: 8px\ncomponents:\n  buttons: Solid purple\ndials:\n  variance: 0.5\n  density: 0.4\n  motion: 0.2\nallow: [em-dash]\n---\n\n# Viola Studio\n\n## Overview\nDeliberately purple, in Inter.\n\n## Do's and Don'ts\n- Do keep the purple accent everywhere.\n";
$r = ab('novamira/save-design', ['slug' => 'smoke-viola', 'content' => $design_purple, 'activate' => true]);
check(
    'save-design smoke-viola returns a ready structured contract and activates',
    !is_wp_error($r)
    && ($r['activated'] ?? false) === true
    && ($r['readiness']['ready'] ?? false) === true
    && ($r['readiness']['sync_ready'] ?? false) === true
    && ($r['token_sources']['colors'] ?? '') === 'explicit'
    && ($r['tokens']['colors']['accent'] ?? '') === '#7c3aed'
    && abs(($r['dials']['variance'] ?? 0.0) - 0.5) < 0.001,
    is_wp_error($r) ? $r->get_error_message() : json_encode($r['readiness'] ?? null),
);
check(
    'save-design smoke-viola carries no guidance warning: its Do item was recognised',
    !is_wp_error($r) && guidance_warnings($r) === [],
    json_encode(is_wp_error($r) ? $r->get_error_message() : $r['readiness']['warnings'] ?? null),
);

// Verify the persisted state, not the claim.
$act = ab('novamira/get-active-design', []);
check(
    'get-active-design = smoke-viola with plain structured guidance',
    !is_wp_error($act)
    && ($act['slug'] ?? '') === 'smoke-viola'
    && ($act['active'] ?? false) === true
    && ($act['guidance']['dos'][0] ?? '') === 'Do keep the purple accent everywhere.'
    && guidance_warnings($act) === [],
    json_encode(['slug' => $act['slug'] ?? null, 'guidance' => $act['guidance'] ?? null, 'warnings' => $act['readiness']['warnings'] ?? null]),
);

// Updating through the ability writes a native WordPress revision for the design CPT.
$post = \Novamira\Design\Store\find_user_post('smoke-viola');
$revisions_before = $post instanceof WP_Post ? count(wp_get_post_revisions($post->ID)) : -1;
$design_purple_v2 = str_replace('Deliberately purple, in Inter.', 'Deliberately purple and set in Inter.', $design_purple);
$r = ab('novamira/save-design', ['slug' => 'smoke-viola', 'content' => $design_purple_v2, 'activate' => false]);
$post = \Novamira\Design\Store\find_user_post('smoke-viola');
$revisions_after = $post instanceof WP_Post ? count(wp_get_post_revisions($post->ID)) : -1;
check(
    'design CPT stores an initial baseline and an update creates another revision',
    post_type_supports('novamira_design', 'revisions') && $revisions_before === 1 && $revisions_after === 2,
    json_encode(['before' => $revisions_before, 'after' => $revisions_after]),
);

$r = ab('novamira/check-design', ['output' => $out_purple_inter . ' <p>testo — con em-dash</p>']);
$hard = array_values(array_filter(is_wp_error($r) ? [] : $r['violations'], static fn(array $v): bool => in_array($v['rule'], ['ai-purple', 'inter-font', 'em-dash'], true)));
check('declared purple + Inter + em-dash: 0 hard violations, ok=true', !is_wp_error($r) && $r['ok'] === true && $hard === [], json_encode(rules($r)));

// activate-design reports the readiness of the design it activates, warnings included.
$design_noguide = "---\ncolors:\n  ground: \"#f4f2ec\"\n  ink: \"#141a16\"\n  accent: \"#1e5c40\"\ntypography:\n  heading:\n    fontFamily: \"Cabinet Grotesk, sans-serif\"\n  body:\n    fontFamily: \"General Sans, sans-serif\"\nspacing:\n  md: 18px\nrounded:\n  md: 4px\ncomponents:\n  buttons: Square and solid\ndials:\n  variance: 0.3\n  density: 0.4\n  motion: 0.1\n---\n\n# No Guide\n\n## Overview\nReady, but without a Do's and Don'ts section.\n";
ab('novamira/save-design', ['slug' => 'smoke-noguide', 'content' => $design_noguide, 'activate' => false]);
$r = ab('novamira/activate-design', ['slug' => 'smoke-noguide']);
check(
    'activate-design returns readiness and warns that the activated design has no guidance section',
    !is_wp_error($r)
    && ($r['activated'] ?? false) === true
    && ($r['readiness']['ready'] ?? false) === true
    && count(guidance_warnings($r)) === 1
    && str_contains(guidance_warnings($r)[0], 'No Do\'s and Don\'ts section'),
    is_wp_error($r) ? $r->get_error_message() : json_encode($r),
);
$r = ab('novamira/activate-design', ['slug' => 'smoke-viola']);
check(
    'activate-design smoke-viola carries readiness with no guidance warning',
    !is_wp_error($r)
    && ($r['activated'] ?? false) === true
    && is_array($r['readiness']['warnings'] ?? null)
    && guidance_warnings($r) === [],
    is_wp_error($r) ? $r->get_error_message() : json_encode($r),
);

// ---------- 5. A new direction does not inherit ----------
$design_forest = "---\ncolors:\n  ground: \"#f4f2ec\"\n  ink: \"#141a16\"\n  accent: \"#1e5c40\"\ntypography:\n  heading:\n    fontFamily: \"Cabinet Grotesk, sans-serif\"\n  body:\n    fontFamily: \"General Sans, sans-serif\"\nspacing:\n  md: 18px\nrounded:\n  md: 4px\ncomponents:\n  buttons: Square and solid\ndials:\n  variance: 0.3\n  density: 0.4\n  motion: 0.1\n---\n\n# Forest\n\n## Overview\nNew direction: no purple, no Inter.\n";
$r = ab('novamira/save-design', ['slug' => 'smoke-forest', 'content' => $design_forest, 'activate' => true]);
check('save-design smoke-forest (activate)', !is_wp_error($r), is_wp_error($r) ? $r->get_error_message() : 'saved');

$r = ab('novamira/check-design', ['output' => $out_purple_inter . ' <p>testo — con em-dash</p>']);
$frules = array_values(array_map(static fn(array $v): string => $v['rule'], array_filter(is_wp_error($r) ? [] : $r['violations'], static fn(array $v): bool => $v['severity'] === 'fail')));
check(
    'same output vs the new direction: the fails come back',
    !is_wp_error($r) && $r['ok'] === false && in_array('ai-purple', $frules, true) && in_array('inter-font', $frules, true) && in_array('em-dash', $frules, true),
    json_encode($frules),
);

$incomplete_replacement = "---\nname: Forest\ncolors:\n  bg: '#ffffff'\n---\n# Forest\n";
$r = ab('novamira/save-design', ['slug' => 'smoke-forest', 'content' => $incomplete_replacement]);
check(
    'an incomplete write cannot overwrite the active design',
    is_wp_error($r) && $r->get_error_code() === 'active_design_not_ready',
    is_wp_error($r) ? $r->get_error_code() : json_encode($r),
);

// ---------- 6. allow: [warm-craft-palette] ----------
$design_wc = "---\ncolors:\n  ground: \"#EDE6D6\"\n  ink: \"#2a2119\"\n  accent: \"#B4603A\"\ntypography:\n  heading:\n    fontFamily: \"Fraunces, serif\"\n  body:\n    fontFamily: \"Karla, sans-serif\"\nspacing:\n  md: 16px\nrounded:\n  md: 2px\ncomponents:\n  buttons: Sharp and quiet\ndials:\n  variance: 0.2\n  density: 0.3\n  motion: 0.1\nallow: [warm-craft-palette]\n---\n\n# Ghisa\n\n## Overview\nA genuinely warm-craft brand.\n";
ab('novamira/save-design', ['slug' => 'smoke-ghisa', 'content' => $design_wc, 'activate' => true]);
$r = ab('novamira/check-design', ['output' => $out_ghisa]);
check('allow warm-craft-palette: silenced', !is_wp_error($r) && severity_of($r, 'warm-craft-palette') === '', json_encode(rules($r)));

// ---------- 7. not_checked surface ----------
check(
    'not_checked exposes the structural rules',
    !is_wp_error($r) && in_array('eyebrow-overuse', $r['not_checked'] ?? [], true) && in_array('zigzag-cap', $r['not_checked'] ?? [], true),
    json_encode($r['not_checked'] ?? null),
);

// ---------- 8. Cost on ~1 MB ----------
$big = str_repeat($out_cobalt . "\n<p>Sezione con testo reale, colori coerenti, nessun tell.</p>\n", 6500);
$t0 = microtime(true);
$ctx = \Novamira\Design\Preflight\context(null);
$v = \Novamira\Design\Preflight\violations($big, $ctx);
$ms = (microtime(true) - $t0) * 1000;
check('preflight ~1MB under 50ms', $ms < 50, sprintf('%d bytes in %.2f ms, %d violations', strlen($big), $ms, count($v)));

// ---------- 9. Revision retention and restore ----------
$history_design = str_replace(['# Forest', 'New direction: no purple, no Inter.'], ['# History', 'History version 0.'], $design_forest);
ab('novamira/save-design', ['slug' => 'smoke-history', 'content' => $history_design]);
for ($i = 1; $i <= 7; $i++) {
    $history_design = preg_replace('/History version \d+\./', 'History version ' . $i . '.', $history_design) ?? $history_design;
    ab('novamira/save-design', ['slug' => 'smoke-history', 'content' => $history_design]);
}
$history_post = \Novamira\Design\Store\find_user_post('smoke-history');
$stored_revisions = $history_post instanceof WP_Post ? wp_get_post_revisions($history_post->ID) : [];
$restorable_history = $history_post instanceof WP_Post ? \Novamira\Design\Revisions\history($history_post) : [];
check(
    'design revision retention is capped at five snapshots',
    $history_post instanceof WP_Post
    && wp_revisions_to_keep($history_post) === 5
    && count($stored_revisions) === 5
    && count($restorable_history) === 4,
    json_encode(['stored' => count($stored_revisions), 'restorable' => count($restorable_history)]),
);

$restore_target = $restorable_history[array_key_last($restorable_history)] ?? null;
$content_before_restore = $history_post instanceof WP_Post ? $history_post->post_content : '';
$expected_content = $restore_target instanceof WP_Post ? $restore_target->post_content : '';
$restore_result = $history_post instanceof WP_Post && $restore_target instanceof WP_Post
    ? \Novamira\Design\Revisions\restore($history_post, $restore_target, actor: 'user')
    : new WP_Error('missing_fixture');
$history_post = \Novamira\Design\Store\find_user_post('smoke-history');
$after_restore_history = $history_post instanceof WP_Post ? \Novamira\Design\Revisions\history($history_post) : [];
$previous_current_survived = count(array_filter(
    $after_restore_history,
    static fn(WP_Post $revision): bool => $revision->post_content === $content_before_restore,
)) === 1;
check(
    'restoring history changes the design and keeps the previous current version reversible',
    !is_wp_error($restore_result)
    && $history_post instanceof WP_Post
    && $history_post->post_content === $expected_content
    && count(wp_get_post_revisions($history_post->ID)) === 5
    && $previous_current_survived,
    is_wp_error($restore_result) ? $restore_result->get_error_message() : json_encode(['reversible' => $previous_current_survived]),
);

$ready_incomplete = str_replace(['# Forest', 'New direction: no purple, no Inter.'], ['# Incomplete', 'Repaired and ready.'], $design_forest);
ab('novamira/save-design', ['slug' => 'smoke-incomplete', 'content' => $ready_incomplete, 'activate' => true]);
$incomplete_post = \Novamira\Design\Store\find_user_post('smoke-incomplete');
$incomplete_history = $incomplete_post instanceof WP_Post ? \Novamira\Design\Revisions\history($incomplete_post) : [];
$incomplete_revision = null;
foreach ($incomplete_history as $candidate) {
    if (!\Novamira\Design\Contract\inspect($candidate->post_content)['readiness']['ready']) {
        $incomplete_revision = $candidate;
        break;
    }
}
$restore_result = $incomplete_post instanceof WP_Post && $incomplete_revision instanceof WP_Post
    ? \Novamira\Design\Revisions\restore($incomplete_post, $incomplete_revision, actor: 'user')
    : new WP_Error('missing_fixture');
check(
    'an incomplete historical revision cannot replace the active design',
    is_wp_error($restore_result) && $restore_result->get_error_code() === 'revision_not_ready',
    is_wp_error($restore_result) ? $restore_result->get_error_code() : json_encode($restore_result),
);

// ---------- Cleanup ----------
foreach (['smoke-incomplete', 'smoke-viola', 'smoke-noguide', 'smoke-forest', 'smoke-ghisa', 'smoke-history'] as $slug) {
    ab('novamira/delete-design', ['slug' => $slug]);
}
update_option('novamira_active_design', $prev_active);
check('cleanup: previous active design restored', get_option('novamira_active_design', '') === $prev_active);

// ---------- Report ----------
$failed = count(array_filter($RESULTS, static fn(string $r): bool => str_starts_with($r, 'FAIL')));
echo implode("\n", $RESULTS) . "\n";
echo sprintf("design-smoke: %d checks, %d failed\n", count($RESULTS), $failed);
if ($failed > 0) {
    exit(1);
}
