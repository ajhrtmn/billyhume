<?php
if (!defined('ABSPATH')) exit;

/**
 * A lesson's body isn't one editor field — it's an ORDERED ARRAY of
 * steps, stored as one post meta key (`_bhc_steps`, a plain PHP array —
 * WordPress serializes it automatically, same "structured data in one
 * postmeta key" convention bh-streaming already uses for lyrics/quality
 * encodes). That's the actual "multistep/multipart lesson" mechanism:
 * a lesson author adds steps in any order/combination — text, then an
 * image, then a quiz, then more text — and the front end (class-render.php)
 * just walks the array.
 *
 * Each step is a plain assoc array with a 'type' key plus type-specific
 * fields:
 *
 *   ['type' => 'text',  'content' => '<p>...</p>']                         // wp_editor HTML
 *   ['type' => 'image', 'attachment_ids' => [123, 456], 'caption' => '...']
 *   ['type' => 'video', 'source' => 'upload', 'attachment_id' => 789, 'caption' => '...']
 *   ['type' => 'video', 'source' => 'url', 'video_url' => 'https://...', 'caption' => '...']
 *   ['type' => 'quiz',  'passing_score' => 70, 'questions' => [
 *        ['question' => 'What is...', 'choices' => ['A', 'B', 'C'], 'correct_index' => 1],
 *        ...
 *   ]]
 *
 * A video step deliberately supports TWO sources rather than forcing
 * one: 'upload' (a plain attachment in the WordPress media library,
 * rendered with wp_get_attachment_url()) is the simple default and
 * needs nothing extra — and because it's regular WordPress media, it's
 * exactly the kind of file an offload plugin (Cloudflare R2 etc., see
 * Own Ur Shit's own dashboard) rewrites automatically, with zero
 * changes needed here. 'url' (a plain external URL — Cloudflare Stream,
 * Bunny Stream, a YouTube/Vimeo embed) is for when real adaptive-
 * bitrate delivery matters more than "just store the file somewhere
 * cheaper" — genuinely different infra, so it's a separate source
 * rather than pretending a raw file and a streaming-platform embed are
 * the same thing.
 *
 * Multiple-choice only for v1 — true/false and short-answer are a
 * natural v1.1 (just another $type case here plus a matching branch in
 * class-render.php/class-progress.php's scoring), not a blocker for
 * shipping this.
 */
class BHC_Steps {
    const VALID_TYPES = ['text', 'image', 'video', 'quiz'];

    public static function get($lesson_id) {
        $steps = get_post_meta($lesson_id, '_bhc_steps', true);
        return is_array($steps) ? array_values($steps) : [];
    }

    public static function save($lesson_id, array $steps) {
        // Defensively re-sanitize on the way in rather than trusting
        // whatever the admin form posted — this ends up in the DB and
        // gets echoed back out on the front end.
        $clean = [];
        foreach ($steps as $step) {
            $type = $step['type'] ?? '';
            if (!in_array($type, self::VALID_TYPES, true)) continue;

            if ($type === 'text') {
                $clean[] = ['type' => 'text', 'content' => wp_kses_post($step['content'] ?? '')];
            } elseif ($type === 'image') {
                $ids = array_map('intval', (array) ($step['attachment_ids'] ?? []));
                $clean[] = ['type' => 'image', 'attachment_ids' => array_filter($ids), 'caption' => sanitize_text_field($step['caption'] ?? '')];
            } elseif ($type === 'video') {
                $source = ($step['source'] ?? '') === 'url' ? 'url' : 'upload';
                if ($source === 'url') {
                    // esc_url_raw() only SANITIZES characters (strips
                    // disallowed ones, encodes the rest) — it does not
                    // reject a string that was never a real URL to begin
                    // with. 'not a url' silently became a syntactically
                    // "valid" http://not%20a%20url and was accepted.
                    // filter_var(..., FILTER_VALIDATE_URL) after
                    // esc_url_raw() is the actual validation step; both
                    // together give "sanitized AND confirmed to parse as
                    // a URL" rather than either alone.
                    $url = esc_url_raw($step['video_url'] ?? '');
                    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) continue; // no URL, or not a real one — nothing to render either way
                    $clean[] = ['type' => 'video', 'source' => 'url', 'video_url' => $url, 'caption' => sanitize_text_field($step['caption'] ?? '')];
                } else {
                    $attachment_id = (int) ($step['attachment_id'] ?? 0);
                    if (!$attachment_id) continue;
                    $clean[] = ['type' => 'video', 'source' => 'upload', 'attachment_id' => $attachment_id, 'caption' => sanitize_text_field($step['caption'] ?? '')];
                }
            } elseif ($type === 'quiz') {
                $questions = [];
                foreach ((array) ($step['questions'] ?? []) as $q) {
                    $choices = array_map('sanitize_text_field', (array) ($q['choices'] ?? []));
                    $choices = array_values(array_filter($choices, fn($c) => $c !== ''));
                    if (!$choices) continue;
                    $questions[] = [
                        'question' => sanitize_text_field($q['question'] ?? ''),
                        'choices' => $choices,
                        'correct_index' => max(0, min(count($choices) - 1, (int) ($q['correct_index'] ?? 0))),
                    ];
                }
                if (!$questions) continue;
                $clean[] = [
                    'type' => 'quiz',
                    'passing_score' => max(0, min(100, (int) ($step['passing_score'] ?? 70))),
                    // 0 = unlimited, matching this ecosystem's existing
                    // "0/absent means the open default" convention
                    // (_bhm_required_tier = 0 means ungated, etc.).
                    // A mid-lesson comprehension check and a real graded
                    // exam plausibly want different retry rules within
                    // the SAME course, so this lives per-step, not as a
                    // plugin-wide setting.
                    'max_attempts' => max(0, (int) ($step['max_attempts'] ?? 0)),
                    'questions' => $questions,
                ];
            }
        }
        update_post_meta($lesson_id, '_bhc_steps', $clean);
        return $clean;
    }

    public static function get_step($lesson_id, $step_index) {
        $steps = self::get($lesson_id);
        return $steps[$step_index] ?? null;
    }

    public static function count($lesson_id) {
        return count(self::get($lesson_id));
    }

    // Score a submitted quiz step: $answers is [question_index => chosen_choice_index].
    // Returns ['score' => 0-100, 'passed' => bool, 'total' => n, 'correct' => n].
    public static function score_quiz(array $step, array $answers) {
        $questions = $step['questions'] ?? [];
        $total = count($questions);
        if (!$total) return ['score' => 0, 'passed' => false, 'total' => 0, 'correct' => 0];

        $correct = 0;
        foreach ($questions as $i => $q) {
            if (isset($answers[$i]) && (int) $answers[$i] === (int) $q['correct_index']) {
                $correct++;
            }
        }
        $score = (int) round(($correct / $total) * 100);
        $passed = $score >= (int) ($step['passing_score'] ?? 70);
        return ['score' => $score, 'passed' => $passed, 'total' => $total, 'correct' => $correct];
    }
}
