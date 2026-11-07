<?php
if (!defined('ABSPATH')) exit;

/**
 * A lesson's body is an ordered array of steps in one postmeta key (`_bhc_steps`).
 * Each step is an assoc array with a 'type' key plus type-specific fields:
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
 * Quizzes are multiple-choice only.
 */
class BHC_Steps {
    // Every non-quiz type is non-blocking (Mark complete & continue).
    // WHY: multi-word types are hyphenated because the legacy type is derived from the block
    // name via substr($type, 4), and block names only allow hyphens.
    const VALID_TYPES = ['text', 'image', 'video', 'quiz', 'resource', 'callout', 'checklist', 'chord-chart', 'audio-compare'];

    // A fixed set rather than a free-text style field.
    const CALLOUT_VARIANTS = ['tip', 'note', 'warning'];

    /** @return array<int, array<string, mixed>> */
    public static function get(int $lesson_id): array {
        $steps = get_post_meta($lesson_id, '_bhc_steps', true);
        return is_array($steps) ? array_values($steps) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array<int, array<string, mixed>>
     */
    public static function save(int $lesson_id, array $steps): array {
        // Re-sanitize everything; this is the only writer of _bhc_steps.
        $clean = [];
        foreach ($steps as $step) {
            $type = $step['type'] ?? '';
            if (!in_array($type, self::VALID_TYPES, true)) continue;

            if ($type === 'text') {
                $clean[] = ['type' => 'text', 'content' => wp_kses_post($step['content'] ?? '')];
            } elseif ($type === 'image') {
                $ids = array_map('intval', (array) ($step['attachment_ids'] ?? []));
                $clean[] = ['type' => 'image', 'attachment_ids' => array_filter($ids), 'caption' => wp_kses_post($step['caption'] ?? '')];
            } elseif ($type === 'video') {
                $source = in_array($step['source'] ?? '', ['url', 'cloudflare_stream', 'bunny_stream', 'signed_r2'], true) ? $step['source'] : 'upload';
                if ($source === 'bunny_stream') {
                    // Stores only the Bunny GUID (library/keys are site-wide). Must be a UUID.
                    $guid = strtolower(trim((string) ($step['bunny_guid'] ?? '')));
                    if (!$guid || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $guid)) continue;
                    $clean[] = ['type' => 'video', 'source' => 'bunny_stream', 'bunny_guid' => $guid, 'caption' => wp_kses_post($step['caption'] ?? ''), 'watch_threshold' => self::sanitize_watch_threshold($step['watch_threshold'] ?? null), 'annotations' => self::sanitize_annotations($step['annotations'] ?? []), 'chapters' => self::sanitize_chapters($step['chapters'] ?? [])];
                } elseif ($source === 'signed_r2') {
                    // Stores only the R2 object key: a relative path with no traversal.
                    $key = ltrim(trim((string) ($step['r2_key'] ?? '')), '/');
                    if ($key === '' || strpos($key, '..') !== false || !preg_match('#^[A-Za-z0-9._/-]+$#', $key)) continue;
                    $clean[] = ['type' => 'video', 'source' => 'signed_r2', 'r2_key' => $key, 'caption' => wp_kses_post($step['caption'] ?? ''), 'watch_threshold' => self::sanitize_watch_threshold($step['watch_threshold'] ?? null), 'annotations' => self::sanitize_annotations($step['annotations'] ?? []), 'chapters' => self::sanitize_chapters($step['chapters'] ?? [])];
                } elseif ($source === 'cloudflare_stream') {
                    // Cloudflare Stream UID, pasted in manually; must be 32 lowercase hex chars.
                    $uid = strtolower(trim((string) ($step['stream_uid'] ?? '')));
                    if (!$uid || !preg_match('/^[a-f0-9]{32}$/', $uid)) continue;
                    $clean[] = ['type' => 'video', 'source' => 'cloudflare_stream', 'stream_uid' => $uid, 'caption' => wp_kses_post($step['caption'] ?? '')];
                } elseif ($source === 'url') {
                    // WHY: validate the raw input before esc_url_raw(), which would turn
                    // 'not a url' into a "valid" http://not%20a%20url. esc_url_raw() still runs after.
                    $raw = trim((string) ($step['video_url'] ?? ''));
                    if (!$raw || !filter_var($raw, FILTER_VALIDATE_URL)) continue;
                    $url = esc_url_raw($raw);
                    if (!$url) continue;
                    $clean[] = ['type' => 'video', 'source' => 'url', 'video_url' => $url, 'caption' => wp_kses_post($step['caption'] ?? ''), 'watch_threshold' => self::sanitize_watch_threshold($step['watch_threshold'] ?? null), 'annotations' => self::sanitize_annotations($step['annotations'] ?? []), 'chapters' => self::sanitize_chapters($step['chapters'] ?? [])];
                } else {
                    $attachment_id = (int) ($step['attachment_id'] ?? 0);
                    if (!$attachment_id) continue;
                    $clean[] = ['type' => 'video', 'source' => 'upload', 'attachment_id' => $attachment_id, 'caption' => wp_kses_post($step['caption'] ?? ''), 'watch_threshold' => self::sanitize_watch_threshold($step['watch_threshold'] ?? null), 'annotations' => self::sanitize_annotations($step['annotations'] ?? []), 'chapters' => self::sanitize_chapters($step['chapters'] ?? [])];
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
                    // 0 = unlimited. Per step, since a check-in and an exam want different rules.
                    'max_attempts' => max(0, (int) ($step['max_attempts'] ?? 0)),
                    // This whitelist is the only writer of _bhc_steps; a new block attr must be added here too.
                    'shuffle_questions' => !empty($step['shuffle_questions']),
                    'shuffle_choices' => !empty($step['shuffle_choices']),
                    'questions' => $questions,
                ];
            } elseif ($type === 'resource') {
                $attachment_id = (int) ($step['attachment_id'] ?? 0);
                if (!$attachment_id) continue; // no file: don't store a dead step
                $clean[] = [
                    'type' => 'resource',
                    'attachment_id' => $attachment_id,
                    'label' => sanitize_text_field($step['label'] ?? ''),
                    'description' => wp_kses_post($step['description'] ?? ''),
                ];
            } elseif ($type === 'callout') {
                $content = wp_kses_post($step['content'] ?? '');
                if (trim(wp_strip_all_tags($content)) === '') continue; // empty: don't store a dead step
                $variant = in_array($step['variant'] ?? '', self::CALLOUT_VARIANTS, true) ? $step['variant'] : 'tip';
                $clean[] = ['type' => 'callout', 'content' => $content, 'variant' => $variant];
            } elseif ($type === 'checklist') {
                $items = array_map('sanitize_text_field', (array) ($step['items'] ?? []));
                $items = array_values(array_filter($items, fn($i) => $i !== ''));
                if (!$items) continue; // empty: don't store a dead step
                $clean[] = ['type' => 'checklist', 'title' => sanitize_text_field($step['title'] ?? ''), 'items' => $items];
            } elseif ($type === 'chord-chart') {
                // sanitize_textarea_field() keeps the chart's whitespace; rendered via esc_html() in <pre>.
                $content = sanitize_textarea_field($step['content'] ?? '');
                if (trim($content) === '') continue; // empty: don't store a dead step
                $clean[] = ['type' => 'chord-chart', 'title' => sanitize_text_field($step['title'] ?? ''), 'content' => $content];
            } elseif ($type === 'audio-compare') {
                // Needs both clips, or the step is dropped.
                $id_a = (int) ($step['attachment_id_a'] ?? 0);
                $id_b = (int) ($step['attachment_id_b'] ?? 0);
                if (!$id_a || !$id_b) continue;
                $clean[] = [
                    'type' => 'audio-compare',
                    'attachment_id_a' => $id_a,
                    'attachment_id_b' => $id_b,
                    'label_a' => sanitize_text_field($step['label_a'] ?? '') ?: 'A',
                    'label_b' => sanitize_text_field($step['label_b'] ?? '') ?: 'B',
                    'caption' => wp_kses_post($step['caption'] ?? ''),
                ];
            }
        }
        update_post_meta($lesson_id, '_bhc_steps', $clean);
        return $clean;
    }

    // 0 = any playback completes; 1-100 = required percent watched. Stored regardless of
    // source so it survives a source switch; untrackable sources ignore it.
    /** @param mixed $value */
    private static function sanitize_watch_threshold($value): int {
        if ($value === null || $value === '') return 0;
        return max(0, min(100, (int) $value));
    }

    // Annotations pause/resume their own video step and never navigate, so the step-index
    // progress model is unchanged. 'banner' is the non-pausing, auto-dismissing type.
    const ANNOTATION_TYPES = ['note', 'hotspot', 'question', 'banner'];
    const BANNER_SIZES = ['small', 'medium', 'large'];
    const BANNER_DEFAULT_SECONDS = 8;

    /**
     * @param mixed $annotations
     * @return array<int, array<string, mixed>>
     */
    private static function sanitize_annotations($annotations): array {
        $clean = [];
        foreach ((array) $annotations as $a) {
            $type = in_array($a['type'] ?? '', self::ANNOTATION_TYPES, true) ? $a['type'] : '';
            if (!$type) continue;
            $time = max(0, (int) ($a['time'] ?? -1));
            if (($a['time'] ?? null) === null) continue; // no timestamp, nothing to anchor this to

            if ($type === 'question') {
                $choices = array_map('sanitize_text_field', (array) ($a['payload']['choices'] ?? []));
                $choices = array_values(array_filter($choices, fn($c) => $c !== ''));
                $question_text = sanitize_text_field($a['payload']['question'] ?? '');
                if (!$choices || $question_text === '') continue; // an empty question has nothing to ask — don't store a dead annotation
                $payload = [
                    'question' => $question_text,
                    'choices' => $choices,
                    'correct_index' => max(0, min(count($choices) - 1, (int) ($a['payload']['correct_index'] ?? 0))),
                ];
            } else {
                $text = sanitize_text_field($a['payload']['text'] ?? '');
                if ($text === '') continue; // an empty note/hotspot has nothing to say
                $payload = ['text' => $text];
                if ($type === 'banner') {
                    $payload['duration'] = max(2, min(30, (int) ($a['payload']['duration'] ?? self::BANNER_DEFAULT_SECONDS)));
                    $size = $a['payload']['size'] ?? 'medium';
                    $payload['size'] = in_array($size, self::BANNER_SIZES, true) ? $size : 'medium';
                }
            }

            $clean[] = ['time' => $time, 'type' => $type, 'payload' => $payload];
        }
        // Playback order: courses.js scans forward for the next unshown annotation.
        usort($clean, fn($a, $b) => $a['time'] <=> $b['time']);
        return array_values($clean);
    }

    // [{ time, title }]; untitled chapters are dropped, sorted into playback order.
    /**
     * @param mixed $chapters
     * @return array<int, array<string, mixed>>
     */
    private static function sanitize_chapters($chapters): array {
        $clean = [];
        foreach ((array) $chapters as $c) {
            $title = sanitize_text_field($c['title'] ?? '');
            if ($title === '') continue;
            $clean[] = ['time' => max(0, (int) ($c['time'] ?? 0)), 'title' => $title];
        }
        usort($clean, fn($a, $b) => $a['time'] <=> $b['time']);
        return array_values($clean);
    }

    /** @return array<string, mixed>|null */
    public static function get_step(int $lesson_id, int $step_index): ?array {
        $steps = self::get($lesson_id);
        return $steps[$step_index] ?? null;
    }

    public static function count(int $lesson_id): int {
        return count(self::get($lesson_id));
    }

    // Score a submitted quiz step: $answers is [question_index => chosen_choice_index].
    // Returns ['score' => 0-100, 'passed' => bool, 'total' => n, 'correct' => n,
    // 'questions' => [ ['q','choices','correct_index','chosen_index'], ... ] ].
    // 'questions' is what ajax_submit_quiz() snapshots and returns for the breakdown.
    /**
     * @param array<string, mixed> $step
     * @param array<int, int> $answers
     * @return array<string, mixed>
     */
    public static function score_quiz(array $step, array $answers): array {
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
