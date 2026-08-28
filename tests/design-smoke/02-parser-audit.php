<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

// DESIGN.md parser audit: real-world file forms the parsing stack must
// survive — BOM, CRLF, quoted/capitalized keys, flat scalar tokens, weight
// in flat typography, 0-100 dials, short hexes, sloppy fences, allow lists.
// Pure parsing, no fixtures created. Runs via wp-cli:
//   wp eval-file tests/design-smoke/02-parser-audit.php

use Novamira\Design\Parser;
use Novamira\Design\Preflight;
use Novamira\Design\Tokens;
use Novamira\Design\Contract;

if (!defined('ABSPATH')) {
    exit('Run from wp-cli only');
}

global $RESULTS;
$RESULTS = [];

function check(string $name, bool $pass, string $detail = ''): void
{
    global $RESULTS;
    $RESULTS[] = ($pass ? 'PASS' : 'FAIL') . " | $name" . ($detail !== '' ? " | $detail" : '');
}

// ---------- 1. UTF-8 BOM before the front matter fence ----------
$bom = "\xEF\xBB\xBF---\nname: Bom Test\ncolors:\n  bg: '#ffffff'\n  accent: '#0a7d4f'\n---\n## Overview\nx";
check('BOM: is_valid', Parser\is_valid($bom));
check('BOM: front matter colors extracted (not the prose fallback)', array_keys(Tokens\extract($bom)['colors']) === ['bg', 'accent'], json_encode(Tokens\extract($bom)['colors']));

// ---------- 2. CRLF line endings ----------
$crlf = str_replace("\n", "\r\n", "---\nname: Crlf\ncolors:\n  bg: '#ffffff'\n---\n## Overview\nx");
check('CRLF: is_valid', Parser\is_valid($crlf));
check('CRLF: colors extracted', Tokens\extract($crlf)['colors'] !== []);

// ---------- 3. Quoted keys ----------
$quoted = "---\nname: Quoted\ncolors:\n  \"bg\": \"#ffffff\"\n  'accent': '#123456'\n---\n## O\nx";
$qc = Tokens\extract($quoted)['colors'];
check('quoted keys are unquoted', isset($qc['bg'], $qc['accent']), json_encode(array_keys($qc)));

// ---------- 4. Capitalized keys still feed the preview vars ----------
$caps = "---\nname: Caps\ncolors:\n  Background: '#FFF8E7'\n  Accent: '#B4603A'\ntypography:\n  Display: Fraunces\n---\n## O\nx";
$dv = Tokens\css_vars(Tokens\extract($caps));
check('capitalized color keys -> --nd-bg', ($dv['--nd-bg'] ?? '') === '#FFF8E7', json_encode($dv));
check('capitalized typography role -> --nd-font-heading', ($dv['--nd-font-heading'] ?? '') === 'Fraunces');

// ---------- 5. allow: inline and block list ----------
$ai = "---\nname: A\nallow: [em-dash, warm-craft-palette]\n---\n## O\nx";
$ab = "---\nname: B\nallow:\n  - em-dash\n  - warm-craft-palette\n---\n## O\nx";
check('allow inline', Preflight\context($ai)['allows'] === ['em-dash', 'warm-craft-palette'], json_encode(Preflight\context($ai)['allows']));
check('allow block list', Preflight\context($ab)['allows'] === ['em-dash', 'warm-craft-palette'], json_encode(Preflight\context($ab)['allows']));

// ---------- 6. Flat scalar tokens ----------
$flat = "---\nname: Flat\nrounded: 12\nspacing: 8\ncolors:\n  bg: '#ffffff'\n---\n## O\nx";
$fv = Tokens\css_vars(Tokens\extract($flat));
check('rounded: 12 -> --nd-radius 12px', ($fv['--nd-radius'] ?? '') === '12px', json_encode($fv['--nd-radius'] ?? null));
check('spacing: 8 -> --nd-space 8px', ($fv['--nd-space'] ?? '') === '8px', json_encode($fv['--nd-space'] ?? null));
check('rounded: "2px" passes through', (Tokens\css_vars(Tokens\extract("---\nname: R\nrounded: \"2px\"\n---\n# R"))['--nd-radius'] ?? '') === '2px');

// ---------- 7. Flat typography with a trailing weight ----------
$tw = "---\nname: T\ntypography:\n  display: Fraunces 700\n  body: Karla\n---\n## O\nx";
$gt = Tokens\extract($tw)['typography'];
$gv = Tokens\css_vars(Tokens\extract($tw));
check('flat family+weight split', ($gt['display']['fontFamily'] ?? '') === 'Fraunces' && ($gt['display']['fontWeight'] ?? '') === '700', json_encode($gt['display'] ?? null));
check('heading var is the clean family', ($gv['--nd-font-heading'] ?? '') === 'Fraunces');
check('heading weight var set', ($gv['--nd-weight-heading'] ?? '') === '700');

// ---------- 8. Dials on a 0-100 scale ----------
$dh = "---\nname: D\ndials:\n  variance: 20\n  density: 0.4\n---\n## O\nx";
$hd = Tokens\dials(Tokens\extract($dh));
check('dial 20 reads as 0.2', abs($hd['variance'] - 0.2) < 0.001, (string) $hd['variance']);
check('dial 0.4 unchanged', abs($hd['density'] - 0.4) < 0.001, (string) $hd['density']);

// ---------- 9. Short hexes normalize consistently for the pre-flight ----------
$short = "---\nname: S\ncolors:\n  bg: '#FE7'\n  ink: '#211'\n  accent: '#B4603A'\n---\n## O\nx";
check('short hex normalized in allowed_colors', in_array('#ffee77', Preflight\context($short)['allowed_colors'], true), json_encode(Preflight\context($short)['allowed_colors']));

// ---------- 10. Fences with trailing whitespace ----------
$fence = "---   \nname: F\ncolors:\n  bg: '#ffffff'\n---   \n## O\nx";
check('fence trailing spaces: is_valid', Parser\is_valid($fence), 'name=' . Parser\parse($fence)['name']);
check('fence trailing spaces: colors extracted', Tokens\extract($fence)['colors'] !== []);

// ---------- 11. Guidance classification through markdown bold and numbers ----------
$dd = "---\nname: G\n---\n# G\n\n## Do's and Don'ts\n\n1. **Do** keep it simple.\n2. **Don't** use gradients.\n- Always prefer real copy.\n- Never ship placeholders.\n";
$g = Novamira\Design\Markdown\guidance($dd, ["do's and don'ts"]);
check('guidance: bold/numbered Do classified', count($g['dos']) === 2, json_encode($g['dos']));
check('guidance: bold/numbered Dont classified', count($g['donts']) === 2, json_encode($g['donts']));
check('extract_donts sees bold numbered items', Preflight\context($dd)['donts'] !== [], json_encode(Preflight\context($dd)['donts']));

// ---------- 11b. Guidance grouped by label or subheading, apostrophes, parity ----------
$labelled = "---\nname: L\n---\n# L\n\n## Do's and Don'ts\n\n**Do**\n- Use the sand palette for backgrounds\n- Keep body text at 18px\n\n**Don't**\n- Mix serif and sans in the same block\n";
$lg = Contract\guidance($labelled);
check(
    'guidance: bold labels group neutral bullets',
    $lg['dos'] === ['Use the sand palette for backgrounds', 'Keep body text at 18px']
    && $lg['donts'] === ['Mix serif and sans in the same block'],
    json_encode($lg),
);
check(
    'guidance: consumed labels do not leak into the prose remainder',
    Novamira\Design\Markdown\guidance($labelled, Contract\guidance_titles())['rest'] === '',
    json_encode(Novamira\Design\Markdown\guidance($labelled, Contract\guidance_titles())['rest']),
);

$subgrouped = "---\nname: S\n---\n# S\n\n## Do's and Don'ts\n\n### Do's\n- Use the sand palette\n1. Keep body text at 18px\n\n### Don'ts\n- Mix serif and sans\n2) Stack shadows\n";
$sg = Contract\guidance($subgrouped);
check(
    'guidance: ### Do\'s / ### Don\'ts subheadings group neutral bullets and numbered items',
    $sg['dos'] === ['Use the sand palette', 'Keep body text at 18px'] && $sg['donts'] === ['Mix serif and sans', 'Stack shadows'],
    json_encode($sg),
);

$typographic = "---\nname: T\n---\n# T\n\n## Do\u{2019}s and Don\u{2019}ts\n- Don\u{2019}t use gradients\n- Do keep contrast high\n";
$tg = Contract\guidance($typographic);
check(
    'guidance: typographic apostrophes in the heading and the items',
    $tg['dos'] === ['Do keep contrast high'] && $tg['donts'] === ["Don\u{2019}t use gradients"],
    json_encode($tg),
);

$overridden = "---\nname: O\n---\n# O\n\n## Do's and Don'ts\n\n**Do**\n- Never stack shadows\n- Use one accent per view\n\n**Don't:**\n- Always keep contrast high\n- Mix serif and sans\n";
$og = Contract\guidance($overridden);
check(
    'guidance: an item\'s own Never/Always wording overrides its group',
    $og['dos'] === ['Use one accent per view', 'Always keep contrast high']
    && $og['donts'] === ['Never stack shadows', 'Mix serif and sans'],
    json_encode($og),
);

foreach (['bold numbered' => $dd, 'labelled' => $labelled, 'subgrouped' => $subgrouped, 'typographic' => $typographic, 'overridden' => $overridden] as $form => $doc) {
    check(
        "extract_donts equals the contract's donts ($form)",
        Preflight\extract_donts($doc) === Contract\guidance($doc)['donts'],
        json_encode(['extract_donts' => Preflight\extract_donts($doc), 'contract' => Contract\guidance($doc)['donts']]),
    );
}

$guidance_warnings = static fn(array $inspection): array => array_values(array_filter(
    $inspection['readiness']['warnings'],
    static fn(string $warning): bool => str_contains($warning, "Do's and Don'ts section") || str_contains($warning, "no Do/Don't items"),
));
$ready_front = "---\nname: R\ncolors:\n  bg: '#f7f7f2'\n  ink: '#171a18'\n  accent: '#0f6b4f'\ntypography:\n  heading: Fraunces\n  body: Karla\n---\n# R\n\n";
$no_section = Contract\inspect($ready_front . "## Overview\nNo guidance section.\n");
check(
    'readiness warns when no guidance section exists, without touching ready/sync_ready',
    count($guidance_warnings($no_section)) === 1
    && str_contains($guidance_warnings($no_section)[0], 'No Do\'s and Don\'ts section')
    && $no_section['readiness']['ready']
    && $no_section['readiness']['sync_ready'],
    json_encode($no_section['readiness']),
);
$no_items = Contract\inspect($ready_front . "## Do's and Don'ts\n- Sand palette for backgrounds\n- Body text at 18px\n");
check(
    'readiness warns when the guidance section has no recognised items, without touching ready/sync_ready',
    count($guidance_warnings($no_items)) === 1
    && str_contains($guidance_warnings($no_items)[0], 'no Do/Don\'t items were recognised')
    && $no_items['guidance'] === ['dos' => [], 'donts' => []]
    && $no_items['readiness']['ready']
    && $no_items['readiness']['sync_ready'],
    json_encode(['guidance' => $no_items['guidance'], 'readiness' => $no_items['readiness']]),
);
$titled_dont = "---\nname: Dont\n---\n# Don't\n- An introductory bullet under the document title\n\n## Overview\nx\n";
check(
    'a document titled "Don\'t" is not a guidance section; a "## Don\'ts" section is',
    !Novamira\Design\Markdown\guidance($titled_dont, Contract\guidance_titles())['found']
    && count($guidance_warnings(Contract\inspect($titled_dont))) === 1
    && str_contains($guidance_warnings(Contract\inspect($titled_dont))[0], 'No Do\'s and Don\'ts section')
    && Contract\guidance($titled_dont . "\n## Don'ts\n- Use gradients\n")['donts'] === ['Use gradients'],
    json_encode(['titled' => Contract\guidance($titled_dont), 'sectioned' => Contract\guidance($titled_dont . "\n## Don'ts\n- Use gradients\n")]),
);
check(
    'readiness has no guidance warning once items are recognised',
    $guidance_warnings(Contract\inspect($ready_front . "## Do's and Don'ts\n\n**Do**\n- Use the sand palette\n")) === [],
    json_encode(Contract\inspect($ready_front . "## Do's and Don'ts\n\n**Do**\n- Use the sand palette\n")['readiness']['warnings']),
);

// ---------- 12. Canonical contract and provenance ----------
$canonical = "---\nname: Contract\ncolors:\n  bg: '#f7f7f2'\n  ink: '#171a18'\n  accent: '#0f6b4f'\ntypography:\n  heading: Cabinet Grotesk 700\n  body: General Sans 400\nspacing:\n  md: 16px\nrounded:\n  md: 8px\ncomponents:\n  buttons: Solid accent\ndials:\n  variance: 0.55\n  density: 0.3\n  motion: 0.2\n---\n# Contract\n\n## Do's and Don'ts\n- Do use the accent consistently.\n- Don't add shadows without hierarchy.\n";
$contract = Contract\inspect($canonical);
check(
    'canonical contract is ready and sync-ready',
    $contract['readiness']['ready']
    && $contract['readiness']['sync_ready'],
    json_encode($contract['readiness']),
);
check(
    'contract exposes components, normalized dials and plain guidance',
    ($contract['tokens']['components']['buttons'] ?? '') === 'Solid accent'
    && abs($contract['dials']['variance'] - 0.55) < 0.001
    && ($contract['guidance']['donts'][0] ?? '') === "Don't add shadows without hierarchy.",
    json_encode(['components' => $contract['tokens']['components'], 'guidance' => $contract['guidance']]),
);

$legacy_prose = "# Legacy\n\n## Colors\nPaper #F7F7F2, ink #171A18, accent #0F6B4F.\n\n## Typography\n- **Heading family**: Cabinet Grotesk, sans-serif\n- **Body family**: General Sans, sans-serif\n- **Display**: 72px bold\n- **Body**: 18px regular\n";
$legacy = Contract\inspect($legacy_prose);
check(
    'prose-only design reports inferred token provenance and is not sync-ready',
    $legacy['token_sources']['colors'] === 'inferred'
    && $legacy['token_sources']['typography'] === 'inferred'
    && $legacy['readiness']['ready']
    && !$legacy['readiness']['sync_ready'],
    json_encode(['sources' => $legacy['token_sources'], 'readiness' => $legacy['readiness']]),
);

// ---------- 13. Portability: unknown front-matter keys are ignored, not rejected ----------
$foreign = "---\nschema_version: 7\nvendor: acme-designer\nname: Foreign\ncolors:\n  bg: '#ffffff'\n  ink: '#111111'\n  accent: '#0f6b4f'\ntypography:\n  heading: Inter\n  body: Inter\n---\n# Foreign\n";
$foreign_contract = Contract\inspect($foreign);
check(
    'unknown foreign front-matter keys are ignored and the design stays ready',
    $foreign_contract['readiness']['ready']
    && ($foreign_contract['tokens']['colors']['accent'] ?? '') === '#0f6b4f'
    && ($foreign_contract['tokens']['typography']['heading']['fontFamily'] ?? '') === 'Inter',
    json_encode($foreign_contract['readiness']),
);

// ---------- 14. Material/Tailwind role names resolve to the canonical roles ----------
$material = "---\nname: Material\ncolors:\n  background: '#ffffff'\n  on-background: '#1a1a1a'\n  primary: '#fe6e00'\ntypography:\n  display-hero:\n    fontFamily: 'ui-sans-serif, system-ui, sans-serif'\n  body-md:\n    fontFamily: 'ui-sans-serif, system-ui, sans-serif'\n---\n# Material\n";
$material_vars = Tokens\css_vars(Tokens\extract($material));
check(
    'Material/Tailwind role names (on-background, display-hero, body-md) resolve and pass readiness',
    Contract\inspect($material)['readiness']['ready']
    && ($material_vars['--nd-ink'] ?? '') === '#1a1a1a'
    && ($material_vars['--nd-font-heading'] ?? '') === 'ui-sans-serif, system-ui, sans-serif'
    && ($material_vars['--nd-font-body'] ?? '') === 'ui-sans-serif, system-ui, sans-serif',
    json_encode($material_vars),
);

// ---------- 15. The contract exposes declared waivers for the agent ----------
$waiving = "---\nname: Warm\ncolors:\n  bg: '#EDE6D6'\n  ink: '#2a2119'\n  accent: '#B4603A'\ntypography:\n  heading: Fraunces\n  body: Karla\n---\n# Warm\n";
$waiving_contract = Contract\inspect($waiving);
check(
    'inspect exposes the design\'s waivers so the agent knows the approved house style',
    in_array('warm-craft palette', $waiving_contract['waivers'], true),
    json_encode($waiving_contract['waivers'] ?? null),
);

// ---------- Report ----------
foreach ($RESULTS as $line) {
    echo $line . "\n";
}
$fails = count(array_filter($RESULTS, static fn(string $r): bool => str_starts_with($r, 'FAIL')));
echo 'parser-audit: ' . count($RESULTS) . ' checks, ' . $fails . " failed\n";
if ($fails > 0) {
    exit(1);
}
