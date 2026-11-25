<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * How often the AI asks, as a person sees it: three levels named by how often they ask, the scale the AI is told to rate
 * with, and how many recent calls each level would have asked about. The page does not show the numbers of the scale.
 */

namespace Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

/** Days of the score log the page counts. */
const LEVEL_COUNT_DAYS = 7;

/** Above every level: what always asks. */
const ALWAYS_ASKS = 11;

/** @return array<string, string> Level => its name. */
function level_names(): array
{
    return [
        'most' => __('Ask more', domain: 'novamira'),
        'big' => __('Balanced', domain: 'novamira'),
        'almost_none' => __('Ask less', domain: 'novamira'),
    ];
}

/**
 * How many recent calls each level would have asked about, from the score log: every call that could ask, with the
 * score that decided it. Null when the log holds none from the last days.
 *
 * @return array{since: int, total: int, levels: array<string, int>}|null
 */
function level_counts(): ?array
{
    /** @var mixed $stored */
    $stored = get_option(SCORE_LOG_OPTION, []);
    if (!is_array($stored)) {
        return null;
    }
    $from = time() - (LEVEL_COUNT_DAYS * DAY_IN_SECONDS);
    $since = time();
    $total = 0;
    $levels = array_fill_keys(array_keys(LEVELS), value: 0);
    // @mago-expect analysis:mixed-assignment -- Stored lines are checked one by one.
    foreach ($stored as $line) {
        if (!is_array($line) || !is_int($line['time'] ?? null) || $line['time'] < $from) {
            continue;
        }
        $since = min($since, $line['time']);
        $total++;
        $score = logged_score($line);
        foreach (LEVELS as $level => $cutoff) {
            if ($score < $cutoff) {
                continue;
            }
            $levels[$level]++;
        }
    }
    return $total === 0 ? null : ['since' => $since, 'total' => $total, 'levels' => $levels];
}

/**
 * The score that decided a logged call: the higher of the AI's, the check's and the ability's own minimum, the top of the scale for
 * code the check could not read, and above every level for an ability that always asks.
 *
 * @param array<array-key, mixed> $line
 */
function logged_score(array $line): int
{
    $policy = ability_policy(is_string($line['ability'] ?? null) ? $line['ability'] : '');
    if ($policy['confirm'] === 'always') {
        return ALWAYS_ASKS;
    }
    if (($line['analysis_reject'] ?? false) === true) {
        return ATTENTION_MAX;
    }
    return max(
        is_int($line['attention'] ?? null) ? $line['attention'] : 0,
        is_int($line['analysis_score'] ?? null) ? $line['analysis_score'] : 0,
        $policy['floor'],
    );
}

/**
 * The three levels as one choice. A number saved before levels existed shows as a fourth choice until another is
 * picked, so nobody gets a different setting without choosing it.
 */
function render_level_choice(int $cutoff, string $id): void
{
    $counts = level_counts();
    $current = level_of($cutoff);
    $skip = settings()['skip_approvals'];
    ?>
    <fieldset class="nm-levels" id="<?php echo esc_attr($id); ?>">
        <legend class="nm-levels-title"><?php esc_html_e(
            'How often should your AI ask you?',
            domain: 'novamira',
        ); ?></legend>
        <p class="nm-level-intro"><?php esc_html_e(
            'Your AI rates its actions and asks you before those it rates at or above the level you choose.',
            domain: 'novamira',
        ); ?></p>
        <div class="nm-level-scale">
            <?php foreach (level_names() as $level => $name) { ?>
                <label class="nm-level">
                    <input type="radio" name="level" value="<?php echo esc_attr($level); ?>" data-cutoff="<?php echo
                        esc_attr((string) LEVELS[$level])
                    ; ?>" data-name="<?php echo esc_attr($name); ?>" <?php checked(!$skip && $current === $level); ?>>
                    <span class="nm-level-name"><?php

                    echo esc_html($name);
                    if ($level === 'big') {
                        echo ' <span class="nm-level-default">' . esc_html__('default', domain: 'novamira') . '</span>';
                    }
                    ?></span>
                    <?php if ($counts !== null) { ?>
                        <span class="nm-level-count" title="<?php esc_attr_e(
                            'How many of your AI\'s recent actions on this site this level asks you about.',
                            domain: 'novamira',
                        ); ?>"><?php echo
                            esc_html(sprintf(
                                /* translators: 1: actions this level asks about, 2: all recent actions. */
                                _n(
                                    single: 'Asks about %1$s of %2$s action',
                                    plural: 'Asks about %1$s of %2$s actions',
                                    number: $counts['total'],
                                    domain: 'novamira',
                                ),
                                number_format_i18n($counts['levels'][$level]),
                                number_format_i18n($counts['total']),
                            ))
                        ; ?></span>
                    <?php } ?>
                </label>
            <?php } ?>
            <?php if ($current === null) { ?>
                <label class="nm-level">
                    <input type="radio" name="level" value="" data-cutoff="<?php echo
                        esc_attr((string) $cutoff)
                    ; ?>" <?php checked(!$skip); ?>>
                    <span class="nm-level-name"><?php esc_html_e('Your earlier setting', domain: 'novamira'); ?></span>
                    <span class="nm-level-line"><?php echo
                        esc_html(sprintf(
                            /* translators: %d: attention level from 0 to 10. */
                            __('Asks from level %d. Kept until you pick another.', domain: 'novamira'),
                            $cutoff,
                        ))
                    ; ?></span>
                </label>
            <?php } ?>
            <label class="nm-level nm-level-skip">
                <input type="radio" name="level" value="skip" data-cutoff="<?php echo
                    esc_attr((string) ALWAYS_ASKS)
                ; ?>" data-name="<?php esc_attr_e('Approvals skipped', domain: 'novamira'); ?>" <?php checked(
                    $skip,
                ); ?>>
                <span class="nm-level-name"><?php

                esc_html_e('Skip approvals', domain: 'novamira');
                ?></span>
            </label>
        </div>
        <?php if ($counts !== null) { ?>
            <p class="description"><?php echo
                esc_html(sprintf(
                    /* translators: %s: date. */
                    __('Counted on the actions your AI took on this site since %s.', domain: 'novamira'),
                    date_i18n((string) get_option('date_format'), $counts['since']),
                ))
            ; ?></p>
        <?php } ?>
    </fieldset>
    <?php
}

/**
 * The attention scale the AI is told to use, in plain words, each band with its lowest and highest score. It is the
 * scale of the scoring instructions, so the page says what the AI is asked to do, not what it will do.
 *
 * @return list<array{text: string, from: int, to: int}>
 */
function ai_scale_rows(): array
{
    return [
        ['text' => __('Reading', domain: 'novamira'), 'from' => 0, 'to' => 0],
        ['text' => __('Small changes that can be restored', domain: 'novamira'), 'from' => 1, 'to' => 3],
        ['text' => __('Published content or several records', domain: 'novamira'), 'from' => 4, 'to' => 5],
        ['text' => __('Broad changes or code that stays on the site', domain: 'novamira'), 'from' => 6, 'to' => 7],
        [
            'text' => __(
                'What cannot be undone, access, payments, or anything sent outside the site',
                domain: 'novamira',
            ),
            'from' => 8,
            'to' => 9,
        ],
        [
            'text' => __('The largest changes that cannot be undone', domain: 'novamira'),
            'from' => 10,
            'to' => 10,
        ],
        // The instructions say to score high what the AI cannot foresee, without a number: the top bands.
        ['text' => __('Actions it cannot predict', domain: 'novamira'), 'from' => 8, 'to' => 10],
    ];
}

/**
 * What asks at each level: the scale the AI is told to rate with, then in a sentence what Novamira adds. The chosen
 * column is marked; the page's script moves the mark when the choice changes. Novamira's own
 * rating is given as examples, not as a list, since it covers only what its check recognises.
 */
function render_level_details(int $cutoff): void
{
    $chosen = settings()['skip_approvals'] ? 'skip' : level_of($cutoff) ?? '';
    ?>
    <div class="nm-level-more">
        <p class="nm-level-note"><?php esc_html_e(
            'Novamira tells your AI to rate actions like this. The rating is your AI\'s.',
            domain: 'novamira',
        ); ?></p>
        <?php render_level_table($chosen); ?>
        <p class="nm-level-note"><?php esc_html_e(
            'To err on the safe side, Novamira also raises the rating of some actions it recognises in your AI\'s code, such as deleting content, installing plugins or sending data to an address not approved yet. The higher rating counts. What Novamira recognises may change in future versions.',
            domain: 'novamira',
        ); ?></p>
        <p class="nm-level-note"><?php echo
            wp_kses(
                sprintf(
                    /* translators: 1: link to the Abilities Hub, 2: link to Also ask before in Settings. */
                    __(
                        'Abilities of other plugins follow your level too, rated by your AI from their description. In the %1$s you can make any ability always ask, and some never ask. To add actions of your site, use %2$s in Settings.',
                        domain: 'novamira',
                    ),
                    '<a href="'
                    . esc_url(admin_url('admin.php?page=novamira-abilities'))
                    . '">'
                    . esc_html__('Abilities Hub', domain: 'novamira')
                    . '</a>',
                    '<a href="'
                    . esc_url(dashboard_url() . '&tab=settings')
                    . '">'
                    . esc_html__('Also ask before', domain: 'novamira')
                    . '</a>',
                ),
                ['a' => ['href' => []]],
            )
        ; ?></p>
        <p class="nm-level-skip-note"><?php esc_html_e(
            'With approvals skipped, nothing asks.',
            domain: 'novamira',
        ); ?></p>
        <p class="description"><?php esc_html_e('Keep a backup.', domain: 'novamira'); ?></p>
    </div>
    <?php
}

/** The AI's scale against the levels: a band asks from its lowest score, and depends on the score from its highest. */
function render_level_table(string $chosen): void
{
    $names = level_names();
    $words = [
        'asks' => __('Asks', domain: 'novamira'),
        'maybe' => __('Depends on the exact score', domain: 'novamira'),
        'runs' => __('Does not ask', domain: 'novamira'),
    ];
    ?>
    <table class="nm-level-table" data-chosen="<?php echo esc_attr($chosen); ?>">
        <thead>
            <tr>
                <td></td>
                <?php foreach ($names as $level => $name) { ?>
                    <th scope="col" data-level="<?php echo esc_attr($level); ?>"><?php echo esc_html($name); ?></th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach (ai_scale_rows() as $row) { ?>
                <tr>
                    <th scope="row"><?php echo esc_html($row['text']); ?></th>
                    <?php foreach (array_keys($names) as $level) {
                        $state = level_cell_state($row, LEVELS[$level]);
                        ?>
                        <td data-level="<?php echo esc_attr($level); ?>"><span class="nm-dot is-<?php echo
                            esc_attr($state)
                        ; ?>" aria-hidden="true"></span><span class="screen-reader-text"><?php echo
                            esc_html($words[$state])
                        ; ?></span></td>
                    <?php
                    } ?>
                </tr>
            <?php } ?>
        </tbody>
    </table>
    <p class="nm-level-legend">
        <?php foreach ($words as $state => $word) { ?>
            <span><span class="nm-dot is-<?php echo esc_attr($state); ?>" aria-hidden="true"></span> <?php echo
                esc_html($word)
            ; ?></span>
        <?php } ?>
    </p>
    <?php
}

/**
 * Whether a band asks at a cutoff: from its lowest score it does, from only its highest it depends on the score.
 *
 * @param array{text: string, from: int, to: int} $row
 * @return 'asks'|'maybe'|'runs'
 */
function level_cell_state(array $row, int $cutoff): string
{
    return match (true) {
        $row['from'] >= $cutoff => 'asks',
        $row['to'] >= $cutoff => 'maybe',
        default => 'runs',
    };
}

/** Asked before approvals are skipped: what changes, and a way back. The page's script opens it. */
function render_skip_dialog(): void
{ ?>
    <dialog id="novamira-skip-dialog" class="nm-skip-dialog" aria-labelledby="novamira-skip-title">
        <h2 id="novamira-skip-title"><?php esc_html_e('Skip approvals?', domain: 'novamira'); ?></h2>
        <p><?php esc_html_e(
            'Nothing waits for you any more: your AI can act without asking.',
            domain: 'novamira',
        ); ?></p>
        <p><?php esc_html_e('You can choose a level again at any time. Keep a backup.', domain: 'novamira'); ?></p>
        <p class="nm-skip-actions">
            <button type="button" class="button button-primary" value="skip"><?php esc_html_e(
                'Skip approvals',
                domain: 'novamira',
            ); ?></button>
            <button type="button" class="button" value="cancel"><?php esc_html_e(
                'Cancel',
                domain: 'novamira',
            ); ?></button>
        </p>
    </dialog>
    <?php }
