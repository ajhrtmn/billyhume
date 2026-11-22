<?php
if (!defined('ABSPATH')) exit;

/**
 * [bh_contest_library]: one server-rendered card per published contest with its
 * lifecycle stage and next deadline, grouped Open now / Starting soon / Wrapped.
 * Distinct from the Archive, which lists every submitted entry.
 */
class BH_Contest_Library {

    public static function init(): void {
        add_shortcode('bh_contest_library', [self::class, 'render_shortcode']);
    }

    /** @param mixed $atts */
    public static function render_shortcode($atts = []): string {
        wp_enqueue_style('ous-catalog');
        $atts = shortcode_atts(['heading' => BHCON_Words::get('admin.contest_library.contests')], (array) $atts, 'bh_contest_library');

        ob_start();
        echo '<style>' . BHY_Style::inline_css() . '</style>';
        echo '<div class="bh-container bh-contest-library">';
        if ($atts['heading'] !== '') {
            echo '<div class="bh-header"><div class="bh-brand">' . esc_html($atts['heading']) . '</div></div>';
        }
        echo self::render_library();
        echo '</div>';
        return (string) ob_get_clean();
    }

    /* ---------------- lifecycle model ---------------- */

    /** @param mixed $raw */
    private static function ts($raw): int {
        if (!$raw) return 0;
        $t = strtotime(str_replace('T', ' ', trim((string) $raw)));
        return $t ?: 0;
    }

    // WHY: bh_contest isn't public, so link its _bh_page_id page instead.
    // '' when there's no published page, so the card renders unlinked.
    private static function contest_url(int $cid): string {
        if (class_exists('BH_ShareCards') && method_exists('BH_ShareCards', 'contest_page_url')) {
            $url = BH_ShareCards::contest_page_url($cid);
            return ($url && $url !== home_url('/') && $url !== trailingslashit(home_url())) ? $url : '';
        }
        $pid = (int) get_post_meta($cid, '_bh_page_id', true);
        return ($pid && get_post_status($pid) === 'publish') ? (string) get_permalink($pid) : '';
    }

    // One lifecycle model the whole card renders from, so its parts can't disagree.
    // stage: -1 not started, 0 submissions, 1 voting, 2 results, 3 done.
    /** @return array{group:string,stage:int,tone:string,label:string,deadline:int,deadline_label:string,ending_soon:bool} */
    public static function contest_lifecycle(int $cid): array {
        $mk = fn($group, $stage, $tone, $label, $deadline = 0, $dl_label = '', $soon = false) => [
            'group' => $group, 'stage' => $stage, 'tone' => $tone, 'label' => $label,
            'deadline' => $deadline, 'deadline_label' => $dl_label, 'ending_soon' => $soon,
        ];

        if (get_post_status($cid) !== 'publish') {
            return $mk('past', -1, 'muted', BHCON_Words::get('admin.contest_library.draft'));
        }
        if (get_post_meta($cid, '_bh_results_published', true) === '1') {
            return $mk('past', 3, 'done', BHCON_Words::get('library.results_published'));
        }

        $now       = current_time('timestamp');
        $soon_secs = (int) apply_filters('bh_contest_ending_soon_window', 3 * DAY_IN_SECONDS);
        $sub       = class_exists('BH_Helpers') ? BH_Helpers::submission_status($cid) : 'unscheduled';
        $vote      = class_exists('BH_Helpers')
            ? (class_exists('BH_Rounds') ? (BH_Rounds::is_voting_open($cid) ? 'open' : BH_Helpers::contest_status($cid)) : BH_Helpers::contest_status($cid))
            : 'unscheduled';
        $sub_start  = self::ts(get_post_meta($cid, '_bh_sub_start', true));
        $sub_end    = self::ts(get_post_meta($cid, '_bh_sub_end', true));
        $vote_start = self::ts(get_post_meta($cid, '_bh_start', true));
        $vote_end   = self::ts(get_post_meta($cid, '_bh_end', true));

        if ($sub === 'open') {
            $s = $sub_end && ($sub_end - $now) < $soon_secs;
            return $mk('open', 0, $s ? 'urgent' : 'live', BHCON_Words::get('library.accepting'), $sub_end, BHCON_Words::get('library.submissions_close'), $s);
        }
        if ($vote === 'open') {
            $s = $vote_end && ($vote_end - $now) < $soon_secs;
            return $mk('open', 1, $s ? 'urgent' : 'live', BHCON_Words::get('library.voting_open'), $vote_end, BHCON_Words::get('library.voting_closes'), $s);
        }
        if ($sub === 'upcoming') {
            return $mk('upcoming', 0, 'soon', BHCON_Words::get('library.submissions_soon'), $sub_start, BHCON_Words::get('library.submissions_open'));
        }
        if ($vote === 'upcoming') {
            return $mk('upcoming', 1, 'soon', BHCON_Words::get('library.voting_soon'), $vote_start, BHCON_Words::get('library.voting_opens'));
        }
        if ($sub === 'closed' && ($vote === 'unscheduled' || !$vote_start)) {
            // Submissions done, voting not on the calendar yet — still a
            // live concern (results pending), so it stays in "Open now".
            return $mk('open', 1, 'soon', BHCON_Words::get('library.between_rounds'));
        }
        if ($vote === 'closed') {
            return $mk('past', 2, 'done', BHCON_Words::get('library.awaiting_results'));
        }
        // No windows configured at all (older contests): quietly open.
        return $mk('open', 0, 'live', BHCON_Words::get('admin.contest_library.open'));
    }

    private static function human_until(int $ts): string {
        $now = current_time('timestamp');
        if ($ts <= $now) return '';
        $d = $ts - $now;
        if ($d < HOUR_IN_SECONDS)     return sprintf(_n(BHCON_Words::get('admin.contest_library.d_min'), BHCON_Words::get('admin.contest_library.d_mins'), (int) ceil($d / 60), 'bh-contest'), (int) ceil($d / 60));
        if ($d < DAY_IN_SECONDS)      return sprintf(_n(BHCON_Words::get('admin.contest_library.d_hour'), BHCON_Words::get('admin.contest_library.d_hours'), (int) round($d / HOUR_IN_SECONDS), 'bh-contest'), (int) round($d / HOUR_IN_SECONDS));
        if ($d < 14 * DAY_IN_SECONDS) return sprintf(_n(BHCON_Words::get('admin.contest_library.d_day'), BHCON_Words::get('admin.contest_library.d_days'), (int) round($d / DAY_IN_SECONDS), 'bh-contest'), (int) round($d / DAY_IN_SECONDS));
        return sprintf(_n(BHCON_Words::get('admin.contest_library.d_week'), BHCON_Words::get('admin.contest_library.d_weeks'), (int) round($d / WEEK_IN_SECONDS), 'bh-contest'), (int) round($d / WEEK_IN_SECONDS));
    }

    private static function fmt(int $n): string {
        if ($n >= 1000) return number_format($n / 1000, $n % 1000 >= 100 ? 1 : 0) . 'k';
        return (string) $n;
    }

    // Placeholder hero for a contest with no cover image.
    private static function cover_placeholder_style(): string {
        $wave = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 300 90'%3E%3Cg fill='%23fff' fill-opacity='0.32'%3E%3Crect x='16' y='40' width='7' height='18' rx='3.5'/%3E%3Crect x='30' y='28' width='7' height='42' rx='3.5'/%3E%3Crect x='44' y='14' width='7' height='70' rx='3.5'/%3E%3Crect x='58' y='34' width='7' height='30' rx='3.5'/%3E%3Crect x='72' y='22' width='7' height='54' rx='3.5'/%3E%3Crect x='86' y='42' width='7' height='14' rx='3.5'/%3E%3Crect x='100' y='10' width='7' height='78' rx='3.5'/%3E%3Crect x='114' y='30' width='7' height='38' rx='3.5'/%3E%3Crect x='128' y='20' width='7' height='58' rx='3.5'/%3E%3Crect x='142' y='38' width='7' height='22' rx='3.5'/%3E%3Crect x='156' y='16' width='7' height='66' rx='3.5'/%3E%3Crect x='170' y='36' width='7' height='26' rx='3.5'/%3E%3Crect x='184' y='24' width='7' height='50' rx='3.5'/%3E%3Crect x='198' y='40' width='7' height='18' rx='3.5'/%3E%3Crect x='212' y='12' width='7' height='74' rx='3.5'/%3E%3Crect x='226' y='32' width='7' height='34' rx='3.5'/%3E%3Crect x='240' y='26' width='7' height='46' rx='3.5'/%3E%3Crect x='254' y='40' width='7' height='18' rx='3.5'/%3E%3Crect x='268' y='30' width='7' height='38' rx='3.5'/%3E%3C/g%3E%3C/svg%3E";
        return "background-image:url(\"$wave\"),linear-gradient(135deg,color-mix(in srgb,var(--tone) 40%,var(--bh-surface-2)) 0%,color-mix(in srgb,var(--tone) 12%,var(--bh-surface-2)) 100%);background-size:70% auto,cover;background-position:center,center;background-repeat:no-repeat,no-repeat;";
    }

    // Phase-appropriate stats as [dashicon, text] pairs.
    /**
     * @param array<string, mixed> $life
     * @return array<int, array{0:string,1:string}>
     */
    private static function card_stats(int $cid, array $life): array {
        if (!class_exists('BH_Helpers')) return [];
        $out = [];
        $cats    = BH_Helpers::categories($cid);
        $entries = BH_Helpers::submission_count($cid);

        if ($life['stage'] >= 3 || $life['group'] === 'past') {
            if (get_post_meta($cid, '_bh_results_published', true) === '1' && class_exists('BH_Reveal')) {
                $top = BH_Reveal::overall_results($cid);
                if (!empty($top[0]['id'])) {
                    $out[] = ['dashicons-awards', get_the_title((int) $top[0]['id']) ?: BHCON_Words::get('library.winner_announced')];
                }
            }
            $out[] = ['dashicons-groups', sprintf(BHCON_Words::get('admin.contest_library.s_votes_cast'), self::fmt(BH_Helpers::vote_count($cid)))];
            $out[] = ['dashicons-format-audio', sprintf(_n(BHCON_Words::get('admin.contest_library.s_entry'), BHCON_Words::get('admin.contest_library.s_entries'), $entries, 'bh-contest'), self::fmt($entries))];
            return $out;
        }

        if ($life['stage'] === 1) { // voting
            $out[] = ['dashicons-thumbs-up', sprintf(BHCON_Words::get('admin.contest_library.s_votes'), self::fmt(BH_Helpers::vote_count($cid)))];
            $out[] = ['dashicons-admin-users', sprintf(_n(BHCON_Words::get('admin.contest_library.s_voter'), BHCON_Words::get('admin.contest_library.s_voters'), BH_Helpers::voter_count($cid), 'bh-contest'), self::fmt(BH_Helpers::voter_count($cid)))];
            $out[] = ['dashicons-format-audio', sprintf(_n(BHCON_Words::get('admin.contest_library.s_entry'), BHCON_Words::get('admin.contest_library.s_entries'), $entries, 'bh-contest'), self::fmt($entries))];
            return $out;
        }

        // submissions / upcoming
        $out[] = ['dashicons-format-audio', sprintf(_n(BHCON_Words::get('admin.contest_library.s_entry_so_far'), BHCON_Words::get('admin.contest_library.s_entries_so_far'), $entries, 'bh-contest'), self::fmt($entries))];
        if (count($cats) > 1) {
            $out[] = ['dashicons-category', sprintf(_n(BHCON_Words::get('admin.contest_library.d_category'), BHCON_Words::get('admin.contest_library.d_categories'), count($cats), 'bh-contest'), count($cats))];
        }
        return $out;
    }

    // Badges for judged, multi-round, and multi-category contests.
    /** @return string[] */
    private static function contest_badges(int $cid): array {
        $b = [];
        $judges = get_post_meta($cid, '_bh_judges', true);
        if (is_array($judges) ? $judges : trim((string) $judges)) {
            $b[] = BHCON_Words::get('admin.contest_library.judged');
        } elseif (trim((string) get_post_meta($cid, '_bh_rubric', true)) !== '') {
            $b[] = BHCON_Words::get('admin.contest_library.judged');
        }
        $rounds = get_post_meta($cid, '_bh_rounds', true);
        $rounds = $rounds ? json_decode($rounds, true) : [];
        if (is_array($rounds) && count($rounds) > 1) {
            $b[] = sprintf(_n(BHCON_Words::get('admin.contest_library.d_round'), BHCON_Words::get('admin.contest_library.d_rounds'), count($rounds), 'bh-contest'), count($rounds));
        }
        return $b;
    }

    // Phase-specific block under the stats (podium, momentum bar, etc.).
    /** @param array<string, mixed> $life */
    private static function card_feature(int $cid, array $life): string {
        $cats = class_exists('BH_Helpers') ? BH_Helpers::categories($cid) : [];
        $chips = '';
        if ($cats) {
            $chips = '<div class="bh-contest-card-cats">';
            foreach (array_slice($cats, 0, 4) as $c) {
                $chips .= '<span class="bh-contest-cat-chip">' . esc_html($c['name'] ?? $c['slug'] ?? '') . '</span>';
            }
            if (count($cats) > 4) $chips .= '<span class="bh-contest-cat-chip is-more">+' . (count($cats) - 4) . '</span>';
            $chips .= '</div>';
        }

        // Wrapped — podium.
        if (($life['group'] === 'past') && get_post_meta($cid, '_bh_results_published', true) === '1' && class_exists('BH_Reveal')) {
            $top = BH_Reveal::overall_results($cid);
            if (!empty($top)) {
                $medals = ['🥇', '🥈', '🥉'];
                $rows = '';
                foreach (array_slice($top, 0, 3) as $i => $r) {
                    if (empty($r['id'])) continue;
                    $rows .= '<li><span class="bh-contest-podium-medal">' . $medals[$i] . '</span>'
                        . '<span class="bh-contest-podium-name">' . esc_html(get_the_title((int) $r['id'])) . '</span>'
                        . '<span class="bh-contest-podium-votes">' . esc_html(sprintf(BHCON_Words::get('admin.contest_library.s_votes'), self::fmt((int) ($r['votes'] ?? 0)))) . '</span></li>';
                }
                if ($rows) {
                    return '<ol class="bh-contest-podium">' . $rows . '</ol>'
                        . '<span class="bh-contest-card-more">' . BHCON_Words::e('library.see_results') . ' &rarr;</span>';
                }
            }
        }

        // Voting — a crowd-momentum bar (voters relative to the field size),
        // never a leaked ranking.
        if ($life['stage'] === 1 && class_exists('BH_Helpers')) {
            $entries = max(1, BH_Helpers::submission_count($cid));
            $voters  = BH_Helpers::voter_count($cid);
            $ratio   = min(100, (int) round($voters / $entries * 100));
            return $chips
                . '<div class="bh-contest-momentum" title="' . esc_attr(BHCON_Words::get('library.turnout_hint')) . '">'
                . '<div class="bh-contest-momentum-track"><span style="width:' . $ratio . '%;"></span></div>'
                . '<span class="bh-contest-momentum-label">' . esc_html(sprintf(BHCON_Words::get('admin.contest_library.s_voting'), self::fmt($voters))) . '</span>'
                . '</div>'
                . '<span class="bh-contest-card-more">' . BHCON_Words::e('library.cast_votes') . ' &rarr;</span>';
        }

        // Submissions — the field, plus the call to join it.
        if ($life['stage'] === 0 && $life['group'] === 'open') {
            $entries = class_exists('BH_Helpers') ? BH_Helpers::submission_count($cid) : 0;
            return $chips
                . '<span class="bh-contest-card-more">'
                . BHCON_Words::e('library.be_entry', ['n' => $entries + 1]) . ' &rarr;</span>';
        }

        // Upcoming — when it opens.
        if ($life['group'] === 'upcoming' && $life['deadline']) {
            return $chips
                . '<span class="bh-contest-card-more">'
                . esc_html(sprintf(__('%1$s %2$s', 'bh-contest'), $life['deadline_label'], wp_date(get_option('date_format'), $life['deadline'])))
                . '</span>';
        }

        return $chips;
    }

    /* ---------------- render ---------------- */

    public static function render_library(): string {
        $contests = get_posts([
            'post_type' => 'bh_contest', 'post_status' => 'publish',
            'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC',
        ]);
        if (!$contests) {
            return '<p class="bh-empty">' . BHCON_Words::e('library.none') . '</p>';
        }

        $groups = ['open' => [], 'upcoming' => [], 'past' => []];
        foreach ($contests as $c) {
            $life = self::contest_lifecycle($c->ID);
            $groups[$life['group']][] = [
                'id'      => $c->ID,
                'title'   => get_the_title($c->ID) ?: BHCON_Words::get('admin.contest_library.untitled_contest'),
                'url'     => self::contest_url($c->ID),
                'excerpt' => wp_trim_words(wp_strip_all_tags((string) $c->post_content), 22, '…'),
                'life'    => $life,
            ];
        }
        $by_deadline = function ($a, $b) {
            $da = $a['life']['deadline'] ?: PHP_INT_MAX;
            $db = $b['life']['deadline'] ?: PHP_INT_MAX;
            return $da <=> $db;
        };
        usort($groups['open'], $by_deadline);
        usort($groups['upcoming'], $by_deadline);

        $stage_names = [BHCON_Words::get('library.stage_submissions'), BHCON_Words::get('library.stage_voting'), BHCON_Words::get('library.stage_results')];
        $section_titles = [
            'open'     => BHCON_Words::get('library.group_open'),
            'upcoming' => BHCON_Words::get('library.group_upcoming'),
            'past'     => BHCON_Words::get('library.group_past'),
        ];

        $total = count($groups['open']) + count($groups['upcoming']) + count($groups['past']);

        ob_start();
        echo '<div class="bh-contests-landing">';

        // A quiet lead line so the page reads as its own thing, not a
        // list dropped on the page.
        echo '<p class="bh-contest-library-lead">'
            . esc_html(sprintf(
                _n(BHCON_Words::get('admin.contest_library.d_contest_tracked_from_first'),
                   BHCON_Words::get('admin.contest_library.d_contests_tracked_from_first'), $total, 'bh-contest'),
                $total))
            . '</p>';

        foreach ($section_titles as $key => $heading) {
            if (empty($groups[$key])) continue;
            echo '<div class="bh-contests-landing-section is-' . esc_attr($key) . '">';
            echo '<h2 class="bh-contests-landing-heading">'
                . '<span class="bh-contests-landing-heading-dot" aria-hidden="true"></span>'
                . esc_html($heading)
                . '<span class="bh-contests-landing-count">' . count($groups[$key]) . '</span></h2>';
            echo '<div class="bh-contests-landing-grid">';

            foreach ($groups[$key] as $item) {
                $life  = $item['life'];
                $cid   = $item['id'];
                $class = 'bh-contest-card tone-' . $life['tone'] . ($life['ending_soon'] ? ' is-ending' : '');
                // Link to the contest's real page when it has one;
                // otherwise render the card as a plain container rather
                // than a dead link (see contest_url()).
                $tag = $item['url'] !== '' ? 'a' : 'div';
                $href = $item['url'] !== '' ? ' href="' . esc_url($item['url']) . '"' : '';
                echo '<' . $tag . ' class="' . esc_attr($class) . ($item['url'] === '' ? ' is-unlinked' : '') . '"' . $href . '>';

                // ---- cover ----
                $cover = get_the_post_thumbnail_url($cid, 'large');
                echo '<div class="bh-contest-card-cover"'
                    . ($cover
                        ? ' style="background-image:linear-gradient(to top,color-mix(in srgb,var(--bh-surface) 88%,transparent) 4%,transparent 62%),url(' . esc_url($cover) . ');"'
                        : ' data-placeholder="1" style="' . esc_attr(self::cover_placeholder_style()) . '"')
                    . '>';
                echo '<span class="bh-contest-phase-pill">'
                    . '<span class="bh-contest-phase-dot" aria-hidden="true"></span>'
                    . esc_html($life['label']) . '</span>';
                if ($life['deadline'] && ($h = self::human_until($life['deadline'])) !== '') {
                    echo '<span class="bh-contest-countdown"><span class="bh-contest-countdown-label">'
                        . esc_html($life['deadline_label']) . '</span><span class="bh-contest-countdown-value">'
                        . esc_html(sprintf(BHCON_Words::get('admin.contest_library.s'), $h)) . '</span></span>';
                }
                echo '</div>';

                // ---- body ----
                echo '<div class="bh-contest-card-body">';

                echo '<div class="bh-contest-card-headline">';
                echo '<h3 class="bh-contest-card-title">' . esc_html($item['title']) . '</h3>';
                $badges = self::contest_badges($cid);
                if ($badges) {
                    echo '<div class="bh-contest-card-badges">';
                    foreach ($badges as $bd) echo '<span class="bh-contest-badge">' . esc_html($bd) . '</span>';
                    echo '</div>';
                }
                echo '</div>';

                if ($item['excerpt']) {
                    echo '<p class="bh-contest-card-excerpt">' . esc_html($item['excerpt']) . '</p>';
                }

                $stats = self::card_stats($cid, $life);
                if ($stats) {
                    echo '<ul class="bh-contest-card-stats">';
                    foreach ($stats as [$icon, $text]) {
                        echo '<li><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>'
                            . esc_html($text) . '</li>';
                    }
                    echo '</ul>';
                }

                $feature = self::card_feature($cid, $life);
                if ($feature !== '') echo '<div class="bh-contest-card-feature">' . $feature . '</div>';

                echo '</div>';

                // ---- lifecycle track ----
                echo '<ol class="bh-contest-track" aria-label="' . esc_attr(BHCON_Words::get('library.progress_label')) . '">';
                foreach ($stage_names as $i => $name) {
                    $state = $life['stage'] < 0 ? 'todo'
                        : ($i < $life['stage'] ? 'done' : ($i === $life['stage'] ? 'current' : 'todo'));
                    echo '<li class="is-' . $state . '"><span class="bh-contest-track-dot" aria-hidden="true"></span>'
                        . '<span class="bh-contest-track-label">' . esc_html($name) . '</span></li>';
                }
                echo '</ol>';

                echo '</' . $tag . '>';
            }
            echo '</div></div>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }
}
