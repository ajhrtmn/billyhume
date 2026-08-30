<?php
if (!defined('ABSPATH')) exit;

/**
 * The Contest Library — a standalone page ([bh_contest_library] /
 * bh/contest-library), separate from the Archive.
 *
 * Where the Archive is the flat "every track ever submitted" catalog,
 * this page answers a different question: "what contests are happening,
 * and where is each one in its life?" Every published contest becomes a
 * card carrying real lifecycle state — a phase pill, a
 * submissions -> voting -> results stage track, and a countdown to the
 * next deadline — grouped Open now / Starting soon / Wrapped and sorted
 * by urgency within each group. Server-rendered; no JS, no REST round
 * trip. Renders through the active theme (a real Page + the_content),
 * the same posture as the account portal and the courses catalog.
 */
class BH_Contest_Library {

    public static function init(): void {
        add_shortcode('bh_contest_library', [self::class, 'render_shortcode']);
    }

    public static function render_shortcode($atts = []): string {
        wp_enqueue_style('ous-catalog');
        $atts = shortcode_atts(['heading' => __('Contests', 'bh-contest')], (array) $atts, 'bh_contest_library');

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

    // The real front-end URL for a contest. The bh_contest CPT is
    // 'public' => false — get_permalink() on it is not a usable link.
    // The actual player lives on a separate WP Page whose id is in
    // _bh_page_id (auto-created by BH_AdminMenus). BH_ShareCards::
    // contest_page_url() already resolves exactly this (published page
    // -> its permalink, else home). '' when there's no real page yet,
    // so the card can render un-linked rather than pointing at the
    // homepage.
    private static function contest_url(int $cid): string {
        if (class_exists('BH_ShareCards') && method_exists('BH_ShareCards', 'contest_page_url')) {
            $url = BH_ShareCards::contest_page_url($cid);
            return ($url && $url !== home_url('/') && $url !== trailingslashit(home_url())) ? $url : '';
        }
        $pid = (int) get_post_meta($cid, '_bh_page_id', true);
        return ($pid && get_post_status($pid) === 'publish') ? (string) get_permalink($pid) : '';
    }

    // Compresses a contest's whole lifecycle into one model every part
    // of the card renders from — stage (0 submissions, 1 voting, 2
    // results; -1 not started, 3 fully done), a visual tone, the next
    // real deadline, and whether it's inside the "ending soon" window —
    // so the pill, the stage track, the countdown and the grouping
    // can't drift apart. Dates: _bh_sub_start/_bh_sub_end (submissions),
    // _bh_start/_bh_end (voting), _bh_results_published (results).
    /** @return array{group:string,stage:int,tone:string,label:string,deadline:int,deadline_label:string,ending_soon:bool} */
    public static function contest_lifecycle(int $cid): array {
        $mk = fn($group, $stage, $tone, $label, $deadline = 0, $dl_label = '', $soon = false) => [
            'group' => $group, 'stage' => $stage, 'tone' => $tone, 'label' => $label,
            'deadline' => $deadline, 'deadline_label' => $dl_label, 'ending_soon' => $soon,
        ];

        if (get_post_status($cid) !== 'publish') {
            return $mk('past', -1, 'muted', __('Draft', 'bh-contest'));
        }
        if (get_post_meta($cid, '_bh_results_published', true) === '1') {
            return $mk('past', 3, 'done', __('Results published', 'bh-contest'));
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
            return $mk('open', 0, $s ? 'urgent' : 'live', __('Accepting submissions', 'bh-contest'), $sub_end, __('Submissions close', 'bh-contest'), $s);
        }
        if ($vote === 'open') {
            $s = $vote_end && ($vote_end - $now) < $soon_secs;
            return $mk('open', 1, $s ? 'urgent' : 'live', __('Voting open', 'bh-contest'), $vote_end, __('Voting closes', 'bh-contest'), $s);
        }
        if ($sub === 'upcoming') {
            return $mk('upcoming', 0, 'soon', __('Submissions open soon', 'bh-contest'), $sub_start, __('Submissions open', 'bh-contest'));
        }
        if ($vote === 'upcoming') {
            return $mk('upcoming', 1, 'soon', __('Voting opens soon', 'bh-contest'), $vote_start, __('Voting opens', 'bh-contest'));
        }
        if ($sub === 'closed' && ($vote === 'unscheduled' || !$vote_start)) {
            // Submissions done, voting not on the calendar yet — still a
            // live concern (results pending), so it stays in "Open now".
            return $mk('open', 1, 'soon', __('Submissions closed — voting to be scheduled', 'bh-contest'));
        }
        if ($vote === 'closed') {
            return $mk('past', 2, 'done', __('Voting closed — awaiting results', 'bh-contest'));
        }
        // No windows configured at all (older contests): quietly open.
        return $mk('open', 0, 'live', __('Open', 'bh-contest'));
    }

    private static function human_until(int $ts): string {
        $now = current_time('timestamp');
        if ($ts <= $now) return '';
        $d = $ts - $now;
        if ($d < HOUR_IN_SECONDS)     return sprintf(_n('%d min', '%d mins', (int) ceil($d / 60), 'bh-contest'), (int) ceil($d / 60));
        if ($d < DAY_IN_SECONDS)      return sprintf(_n('%d hour', '%d hours', (int) round($d / HOUR_IN_SECONDS), 'bh-contest'), (int) round($d / HOUR_IN_SECONDS));
        if ($d < 14 * DAY_IN_SECONDS) return sprintf(_n('%d day', '%d days', (int) round($d / DAY_IN_SECONDS), 'bh-contest'), (int) round($d / DAY_IN_SECONDS));
        return sprintf(_n('%d week', '%d weeks', (int) round($d / WEEK_IN_SECONDS), 'bh-contest'), (int) round($d / WEEK_IN_SECONDS));
    }

    private static function fmt(int $n): string {
        if ($n >= 1000) return number_format($n / 1000, $n % 1000 >= 100 ? 1 : 0) . 'k';
        return (string) $n;
    }

    // A waveform-over-gradient placeholder for a contest with no cover
    // image — same Streamline-Moderne motif the courses catalog uses so
    // a coverless contest still gets a real hero, not a flat block.
    private static function cover_placeholder_style(): string {
        $wave = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 300 90'%3E%3Cg fill='%23fff' fill-opacity='0.32'%3E%3Crect x='16' y='40' width='7' height='18' rx='3.5'/%3E%3Crect x='30' y='28' width='7' height='42' rx='3.5'/%3E%3Crect x='44' y='14' width='7' height='70' rx='3.5'/%3E%3Crect x='58' y='34' width='7' height='30' rx='3.5'/%3E%3Crect x='72' y='22' width='7' height='54' rx='3.5'/%3E%3Crect x='86' y='42' width='7' height='14' rx='3.5'/%3E%3Crect x='100' y='10' width='7' height='78' rx='3.5'/%3E%3Crect x='114' y='30' width='7' height='38' rx='3.5'/%3E%3Crect x='128' y='20' width='7' height='58' rx='3.5'/%3E%3Crect x='142' y='38' width='7' height='22' rx='3.5'/%3E%3Crect x='156' y='16' width='7' height='66' rx='3.5'/%3E%3Crect x='170' y='36' width='7' height='26' rx='3.5'/%3E%3Crect x='184' y='24' width='7' height='50' rx='3.5'/%3E%3Crect x='198' y='40' width='7' height='18' rx='3.5'/%3E%3Crect x='212' y='12' width='7' height='74' rx='3.5'/%3E%3Crect x='226' y='32' width='7' height='34' rx='3.5'/%3E%3Crect x='240' y='26' width='7' height='46' rx='3.5'/%3E%3Crect x='254' y='40' width='7' height='18' rx='3.5'/%3E%3Crect x='268' y='30' width='7' height='38' rx='3.5'/%3E%3C/g%3E%3C/svg%3E";
        return "background-image:url(\"$wave\"),linear-gradient(135deg,color-mix(in srgb,var(--tone) 40%,var(--bh-surface-2)) 0%,color-mix(in srgb,var(--tone) 12%,var(--bh-surface-2)) 100%);background-size:70% auto,cover;background-position:center,center;background-repeat:no-repeat,no-repeat;";
    }

    // The contextual stat strip for a card — a small set of the most
    // interesting numbers for where the contest currently is. Voting: how
    // much the crowd is engaging. Submissions: how full the field is.
    // Wrapped: the winner + the final tally. Each is [icon-dashicon,
    // text]. class_exists guards so a partial install degrades quietly.
    /** @return array<int, array{0:string,1:string}> */
    private static function card_stats(int $cid, array $life): array {
        if (!class_exists('BH_Helpers')) return [];
        $out = [];
        $cats    = BH_Helpers::categories($cid);
        $entries = BH_Helpers::submission_count($cid);

        if ($life['stage'] >= 3 || $life['group'] === 'past') {
            if (get_post_meta($cid, '_bh_results_published', true) === '1' && class_exists('BH_Reveal')) {
                $top = BH_Reveal::overall_results($cid);
                if (!empty($top[0]['id'])) {
                    $out[] = ['dashicons-awards', get_the_title((int) $top[0]['id']) ?: __('Winner announced', 'bh-contest')];
                }
            }
            $out[] = ['dashicons-groups', sprintf(__('%s votes cast', 'bh-contest'), self::fmt(BH_Helpers::vote_count($cid)))];
            $out[] = ['dashicons-format-audio', sprintf(_n('%s entry', '%s entries', $entries, 'bh-contest'), self::fmt($entries))];
            return $out;
        }

        if ($life['stage'] === 1) { // voting
            $out[] = ['dashicons-thumbs-up', sprintf(__('%s votes', 'bh-contest'), self::fmt(BH_Helpers::vote_count($cid)))];
            $out[] = ['dashicons-admin-users', sprintf(_n('%s voter', '%s voters', BH_Helpers::voter_count($cid), 'bh-contest'), self::fmt(BH_Helpers::voter_count($cid)))];
            $out[] = ['dashicons-format-audio', sprintf(_n('%s entry', '%s entries', $entries, 'bh-contest'), self::fmt($entries))];
            return $out;
        }

        // submissions / upcoming
        $out[] = ['dashicons-format-audio', sprintf(_n('%s entry so far', '%s entries so far', $entries, 'bh-contest'), self::fmt($entries))];
        if (count($cats) > 1) {
            $out[] = ['dashicons-category', sprintf(_n('%d category', '%d categories', count($cats), 'bh-contest'), count($cats))];
        }
        return $out;
    }

    // Small structural badges that say what KIND of contest this is —
    // judged, multi-round, multi-category. Cheap meta reads, and each
    // one is a genuine "this contest is different from that one" signal.
    /** @return string[] */
    private static function contest_badges(int $cid): array {
        $b = [];
        $judges = get_post_meta($cid, '_bh_judges', true);
        if (is_array($judges) ? $judges : trim((string) $judges)) {
            $b[] = __('Judged', 'bh-contest');
        } elseif (trim((string) get_post_meta($cid, '_bh_rubric', true)) !== '') {
            $b[] = __('Judged', 'bh-contest');
        }
        $rounds = get_post_meta($cid, '_bh_rounds', true);
        $rounds = $rounds ? json_decode($rounds, true) : [];
        if (is_array($rounds) && count($rounds) > 1) {
            $b[] = sprintf(_n('%d round', '%d rounds', count($rounds), 'bh-contest'), count($rounds));
        }
        return $b;
    }

    // The one distinctive, phase-specific block under the stats — the
    // thing that makes an Open-for-voting card feel different from a
    // Wrapped one at a glance, not just differently labelled.
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
                        . '<span class="bh-contest-podium-votes">' . esc_html(sprintf(__('%s votes', 'bh-contest'), self::fmt((int) ($r['votes'] ?? 0)))) . '</span></li>';
                }
                if ($rows) {
                    return '<ol class="bh-contest-podium">' . $rows . '</ol>'
                        . '<span class="bh-contest-card-more">' . esc_html__('See full results', 'bh-contest') . ' &rarr;</span>';
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
                . '<div class="bh-contest-momentum" title="' . esc_attr__('Voter turnout relative to the field', 'bh-contest') . '">'
                . '<div class="bh-contest-momentum-track"><span style="width:' . $ratio . '%;"></span></div>'
                . '<span class="bh-contest-momentum-label">' . esc_html(sprintf(__('%s voting', 'bh-contest'), self::fmt($voters))) . '</span>'
                . '</div>'
                . '<span class="bh-contest-card-more">' . esc_html__('Cast your votes', 'bh-contest') . ' &rarr;</span>';
        }

        // Submissions — the field, plus the call to join it.
        if ($life['stage'] === 0 && $life['group'] === 'open') {
            $entries = class_exists('BH_Helpers') ? BH_Helpers::submission_count($cid) : 0;
            return $chips
                . '<span class="bh-contest-card-more">'
                . esc_html(sprintf(__('Be entry #%d', 'bh-contest'), $entries + 1)) . ' &rarr;</span>';
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
            return '<p class="bh-empty">' . esc_html__('No contests yet — check back soon.', 'bh-contest') . '</p>';
        }

        $groups = ['open' => [], 'upcoming' => [], 'past' => []];
        foreach ($contests as $c) {
            $life = self::contest_lifecycle($c->ID);
            $groups[$life['group']][] = [
                'id'      => $c->ID,
                'title'   => get_the_title($c->ID) ?: __('(untitled contest)', 'bh-contest'),
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

        $stage_names = [__('Submissions', 'bh-contest'), __('Voting', 'bh-contest'), __('Results', 'bh-contest')];
        $section_titles = [
            'open'     => __('Open now', 'bh-contest'),
            'upcoming' => __('Starting soon', 'bh-contest'),
            'past'     => __('Wrapped', 'bh-contest'),
        ];

        $total = count($groups['open']) + count($groups['upcoming']) + count($groups['past']);

        ob_start();
        echo '<div class="bh-contests-landing">';

        // A quiet lead line so the page reads as its own thing, not a
        // list dropped on the page.
        echo '<p class="bh-contest-library-lead">'
            . esc_html(sprintf(
                _n('%d contest, tracked from first submission to final results.',
                   '%d contests, tracked from first submission to final results.', $total, 'bh-contest'),
                $total))
            . '</p>';

        foreach ($section_titles as $key => $heading) {
            if (!$groups[$key]) continue;
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
                        . esc_html(sprintf(__('in %s', 'bh-contest'), $h)) . '</span></span>';
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
                echo '<ol class="bh-contest-track" aria-label="' . esc_attr__('Contest progress', 'bh-contest') . '">';
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
