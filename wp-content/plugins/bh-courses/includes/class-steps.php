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
 *   ['type' => 'resource', 'attachment_id' => 123, 'label' => 'Worksheet', 'description' => '...']
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
    // 'resource' — ROADMAP-ux-polish-and-feature-parity-2026-07.md 4c:
    // a downloadable file (a worksheet, a PDF, a reference doc) attached
    // to a step, distinct from a video/image because it's meant to be
    // downloaded, not played/viewed inline. Deliberately non-blocking —
    // per that doc's own scoping note, a resource step doesn't gate
    // lesson progress any harder than a text/image step already
    // doesn't; it's "always available," same Mark-complete-and-continue
    // pattern as its siblings for consistency, not a new skip mechanic.
    const VALID_TYPES = ['text', 'image', 'video', 'quiz', 'resource'];

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
                    // with. The validate-AFTER-sanitize order this used
                    // to run in had a real, confirmed bug (caught by the
                    // Test Runner, not found by static reading — exactly
                    // the value this ecosystem's own testing convention
                    // is for): esc_url_raw() percent-encodes a raw space
                    // into %20 BEFORE filter_var() ever sees it, so
                    // 'not a url' became the syntactically "valid"
                    // http://not%20a%20url and passed FILTER_VALIDATE_URL
                    // — a literal space fails that filter, but %20
                    // doesn't, since it's no longer a literal space by
                    // the time filter_var() runs. Validating the RAW
                    // input first (before sanitization can "fix" it into
                    // something that parses) closes that gap; esc_url_raw()
                    // still runs afterward on whatever passes, both for
                    // output-safety encoding AND because it independently
                    // rejects a few things filter_var() alone doesn't
                    // (e.g. a javascript: scheme collapses to an empty
                    // string here, still caught by the emptiness check).
                    $raw = trim((string) ($step['video_url'] ?? ''));
                    if (!$raw || !filter_var($raw, FILTER_VALIDATE_URL)) continue;
                    $url = esc_url_raw($raw);
                    if (!$url) continue;
                    $clean[] = ['type' => 'video', 'source' => 'url', 'video_url' => $url, 'caption' => sanitize_text_field($step['caption'] ?? ''), 'watch_threshold' => self::sanitize_watch_threshold($step['watch_threshold'] ?? null)];
                } else {
                    $attachment_id = (int) ($step['attachment_id'] ?? 0);
                    if (!$attachment_id) continue;
                    $clean[] = ['type' => 'video', 'source' => 'upload', 'attachment_id' => $attachment_id, 'caption' => sanitize_text_field($step['caption'] ?? ''), 'watch_threshold' => self::sanitize_watch_threshold($step['watch_threshold'] ?? null)];
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
                    // Real bug: this
                    // whitelist is the ONLY writer of _bhc_steps, so adding
                    // the shuffle_questions/shuffle_choices block attrs to
                    // BH_Content's schema and class-render-lesson.php's
                    // rendering wasn't enough — every save through here was
                    // silently dropping both fields since this list didn't
                    // know about them yet, and the front end kept rendering
                    // in fixed, unshuffled order no matter what the block
                    // editor's toggle showed.
                    'shuffle_questions' => !empty($step['shuffle_questions']),
                    'shuffle_choices' => !empty($step['shuffle_choices']),
                    'questions' => $questions,
                ];
            } elseif ($type === 'resource') {
                $attachment_id = (int) ($step['attachment_id'] ?? 0);
                if (!$attachment_id) continue; // no file, nothing to offer — don't store a dead step
                $clean[] = [
                    'type' => 'resource',
                    'attachment_id' => $attachment_id,
                    'label' => sanitize_text_field($step['label'] ?? ''),
                    'description' => sanitize_text_field($step['description'] ?? ''),
                ];
            }
        }
        update_post_meta($lesson_id, '_bhc_steps', $clean);
        return $clean;
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md 4b: course-creator-
    // configurable per-step, same "0 = open default" convention quiz's
    // max_attempts already uses. 0 = "any playback marks it complete"
    // (today's behavior, unchanged); 1-100 = required percent watched.
    // Only actually enforceable for the directly-trackable <video> tag
    // case (an uploaded file, or a direct-URL step render.php doesn't
    // treat as an iframe embed) — a cross-origin YouTube/Vimeo iframe
    // can't be watch-position-tracked without that provider's own SDK, so
    // class-render-lesson.php falls back to the plain Mark-complete button
    // for those regardless of what's stored here. Stored on the step
    // either way so the value survives a later source-type switch.
    private static function sanitize_watch_threshold($value) {
        if ($value === null || $value === '') return 0;
        return max(0, min(100, (int) $value));
    }

    public static function get_step($lesson_id, $step_index) {
        $steps = self::get($lesson_id);
        return $steps[$step_index] ?? null;
    }

    public static function count($lesson_id) {
        return count(self::get($lesson_id));
    }

    // Score a submitted quiz step: $answers is [question_index => chosen_choice_index].
    // Returns ['score' => 0-100, 'passed' => bool, 'total' => n, 'correct' => n,
    // 'questions' => [ ['q','choices','correct_index','chosen_index'], ... ] ].
    // The 'questions' detail (QUIZ-AND-CATALOG-DESIGN-PLAN.md Part 1.4) is
    // built from the SAME loop that already computes correctness — no new
    // iteration, no new data source, it just stops throwing the per-
    // question detail away after counting $correct. This is what
    // BHC_Progress::ajax_submit_quiz() snapshots into bhc_progress.answers,
    // and what it returns to the front end for the immediate per-question
    // breakdown (courses.js).
    public static function score_quiz(array $step, array $answers) {
        $questions = $step['questions'] ?? [];
        $total = count($questions);
        if (!$total) return ['score' => 0, 'passed' => false, 'total' => 0, 'correct' => 0, 'questions' => []];

        $correct = 0;
        $detail = [];
        foreach ($questions as $i => $q) {
            $chosen = isset($answers[$i]) ? (int) $answers[$i] : -1;
            $is_correct = $chosen === (int) $q['correct_index'];
            if ($is_correct) $correct++;
            $detail[] = [
                'q' => $q['question'] ?? '',
                'choices' => $q['choices'] ?? [],
                'correct_index' => (int) $q['correct_index'],
                'chosen_index' => $chosen,
            ];
        }
        $score = (int) round(($correct / $total) * 100);
        $passed = $score >= (int) ($step['passing_score'] ?? 70);
        return ['score' => $score, 'passed' => $passed, 'total' => $total, 'correct' => $correct, 'questions' => $detail];
    }
}
