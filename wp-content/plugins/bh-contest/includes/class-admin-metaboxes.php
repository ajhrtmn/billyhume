<?php
if (!defined('ABSPATH')) exit;

/**
 * Split out of class-admin.php (DRY/SOLID audit Phase 3b) — every
 * contest/submission edit-screen metabox (settings, categories, judging,
 * rounds, shortcode, revisions, site menu, share-card style, branding)
 * plus the single save handler that persists all of them. No list-table
 * columns, moderation actions, or CSV/results reporting here.
 */
class BH_AdminMetaboxes {
    public static function init() {
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        // The branding/style override box is an opt-in, per-contest
        // feature most contests never touch (the site-wide theme from
        // Settings & Style already applies by default) — starting it
        // collapsed when a contest hasn't turned the override on keeps
        // it out of the way without hiding or removing it.
        add_filter('postbox_classes_bh_contest_bh_contest_style', [self::class, 'maybe_collapse_style_box']);
        add_action('save_post_bh_contest', [self::class, 'save_contest_meta']);
    }

    // Adds WordPress's own "closed" postbox class when this contest's
    // style override isn't enabled yet — the box is still fully present
    // and expandable, just not competing for attention by default the
    // way it would if it always opened expanded.
    public static function maybe_collapse_style_box($classes) {
        global $post;
        if ($post && !get_post_meta($post->ID, '_bhy_style_override', true)) $classes[] = 'closed';
        return $classes;
    }

    /**
     * Submission review box — covers the file-replace workflow ("wrong
     * file uploaded") and a real reject path. Three states
     * this renders:
     *  1. No pending swap, not rejected: original behavior — live
     *     audio player + the existing "set Status to Published"
     *     instruction, PLUS a new Reject form.
     *  2. A pending swap exists (`_bh_pending_audio_id`): shows BOTH
     *     the currently-live file (still what's actually playing/
     *     being voted on) and the pending replacement, with Approve/
     *     Discard buttons for the swap specifically — first-time
     *     Publish approval and the swap review are two independent
     *     actions once a submission has ever been approved once.
     *  3. post_status === 'rejected': shows the stored reason/note
     *     read-only, plus the same Reject-again path is naturally
     *     unavailable (nothing to re-reject) — a contestant re-
     *     submitting a new file automatically flips this back to
     *     'pending' (see BH_API::replace_audio()).
     */
    public static function render_approval_box($post) {
        $note = get_post_meta($post->ID, '_bh_admin_note', true);
        $audio_id = (int) get_post_meta($post->ID, '_bh_audio_id', true);
        $url  = $audio_id ? wp_get_attachment_url($audio_id) : '';
        $cid  = (int) get_post_meta($post->ID, '_bh_contest_id', true);
        $pending_id = (int) get_post_meta($post->ID, '_bh_pending_audio_id', true);

        if ($cid && get_post($cid)) {
            echo '<p><strong>Contest:</strong> <a href="' . esc_url(get_edit_post_link($cid)) . '">' . esc_html(get_the_title($cid)) . '</a></p>';
        }
        echo '<h4>Artist Name: ' . esc_html(get_post_meta($post->ID, '_bh_artist_name', true)) . '</h4>';
        if ($note) echo '<p><strong>Note to Admin:</strong><br><em>' . esc_html($note) . '</em></p>';

        echo '<p><strong>' . ($pending_id ? 'Currently live:' : 'Audio:') . '</strong></p>';
        echo $url
            ? "<audio controls src='" . esc_url($url) . "' style='width:100%;margin:0 0 15px;'></audio>"
            : '<p>No audio attached.</p>';

        if ($pending_id) {
            $pending_url = wp_get_attachment_url($pending_id);
            $replaced_at = get_post_meta($post->ID, '_bh_pending_replaced_at', true);
            $replaced_by = (int) get_post_meta($post->ID, '_bh_pending_replaced_by', true);
            $by_user = $replaced_by ? get_userdata($replaced_by) : null;
            echo '<div style="background:#fff8e5;border:1px solid #dba617;border-radius:4px;padding:10px 14px;margin-bottom:15px;">';
            echo '<p style="margin-top:0;"><strong>&#9888; Pending replacement</strong>'
               . ($replaced_at ? ' — uploaded ' . esc_html(mysql2date('M j, Y g:ia', $replaced_at)) : '')
               . ($by_user ? ' by ' . esc_html($by_user->display_name) : '') . '</p>';
            echo $pending_url
                ? "<audio controls src='" . esc_url($pending_url) . "' style='width:100%;margin:0 0 10px;'></audio>"
                : '<p>No audio attached.</p>';

            $approve_url = wp_nonce_url(admin_url('admin-post.php?action=bh_approve_swap&submission_id=' . $post->ID), 'bh_approve_swap_' . $post->ID);
            $discard_url = wp_nonce_url(admin_url('admin-post.php?action=bh_discard_swap&submission_id=' . $post->ID), 'bh_discard_swap_' . $post->ID);
            echo '<p style="margin-bottom:0;"><a class="button button-primary" href="' . esc_url($approve_url) . '">Approve replacement</a> '
               . '<a class="button" href="' . esc_url($discard_url) . '" onclick="return confirm(\'Discard this replacement and keep the current file?\');">Discard replacement</a></p>';
            echo '</div>';
        }

        if ($post->post_status === 'rejected') {
            $reason_code = get_post_meta($post->ID, '_bh_rejection_reason_code', true);
            $reason_note = get_post_meta($post->ID, '_bh_rejection_note', true);
            echo '<div style="background:#fbeaea;border:1px solid #b32d2e;border-radius:4px;padding:10px 14px;margin-bottom:15px;">';
            echo '<p style="margin:0;"><strong>Rejected</strong> — ' . esc_html(BH_Admin::REJECTION_REASONS[$reason_code] ?? 'No reason recorded') . '</p>';
            if ($reason_note) echo '<p style="margin:8px 0 0;"><em>' . esc_html($reason_note) . '</em></p>';
            echo '</div>';
        } else {
            echo '<hr><p><strong>To Approve:</strong> set Status to <em>Published</em> and click Update.</p>';

            // QA fix: a metabox renders INSIDE WordPress's own outer
            // post-edit <form id="post">, so a second, nested <form>
            // here is invalid HTML — the browser silently resolves a
            // submit click to the OUTER form (a normal post Update),
            // never actually hitting admin-post.php at all (the reject
            // button appeared to work but the submission's status/meta
            // never actually changed). Fixed by dropping the nested
            // <form> entirely — these are
            // plain fields plus a button with no form ancestor, and a
            // small inline script does a fetch() POST to admin-post.php
            // directly instead of relying on native form submission.
            echo '<details><summary style="cursor:pointer;color:#b32d2e;">Reject this submission</summary>';
            $reject_nonce = wp_create_nonce('bh_reject_submission_' . $post->ID);
            $box_id = 'bh-reject-box-' . (int) $post->ID;
            echo '<div id="' . esc_attr($box_id) . '" style="margin-top:10px;">';
            echo '<p><label>Reason<br><select class="bh-reject-reason">';
            foreach (BH_Admin::REJECTION_REASONS as $code => $label) {
                echo '<option value="' . esc_attr($code) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><label>Note to contestant (included in their notification)<br>'
               . '<textarea class="bh-reject-note" rows="3" style="width:100%;"></textarea></label></p>';
            echo '<button type="button" class="button bh-reject-submit" style="color:#b32d2e;border-color:#b32d2e;">Reject &amp; notify contestant</button>';
            echo ' <span class="bh-reject-status description"></span>';
            echo '</div>';
            echo '<script>(function(){
                var box = document.getElementById(' . wp_json_encode($box_id) . ');
                if (!box) return;
                box.querySelector(".bh-reject-submit").addEventListener("click", function () {
                    if (!confirm("Reject this submission? The contestant will be notified.")) return;
                    var btn = this;
                    var statusEl = box.querySelector(".bh-reject-status");
                    btn.disabled = true;
                    var fd = new FormData();
                    fd.append("action", "bh_reject_submission");
                    fd.append("submission_id", ' . (int) $post->ID . ');
                    fd.append("_wpnonce", ' . wp_json_encode($reject_nonce) . ');
                    fd.append("reason_code", box.querySelector(".bh-reject-reason").value);
                    fd.append("note", box.querySelector(".bh-reject-note").value);
                    fetch(' . wp_json_encode(admin_url('admin-post.php')) . ', { method: "POST", credentials: "same-origin", body: fd })
                        .then(function (res) { if (res.redirected || res.ok) { window.location.reload(); } else { statusEl.textContent = "Something went wrong."; btn.disabled = false; } })
                        .catch(function () { statusEl.textContent = "Request failed."; btn.disabled = false; });
                });
            })();</script>';
            echo '</details>';
        }
    }

    public static function add_meta_boxes() {
        add_meta_box('bh_approval', 'Submission Details & Approval', [self::class, 'render_approval_box'], 'bh_submission', 'normal', 'high');

        add_meta_box('bh_contest_settings', 'Contest Rules & Results', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $sub_start = self::dt_for_input(get_post_meta($post->ID, '_bh_sub_start', true));
            $sub_end   = self::dt_for_input(get_post_meta($post->ID, '_bh_sub_end', true));
            $start = self::dt_for_input(get_post_meta($post->ID, '_bh_start', true));
            $end   = self::dt_for_input(get_post_meta($post->ID, '_bh_end', true));
            $pub   = get_post_meta($post->ID, '_bh_results_published', true);
            $base  = get_post_meta($post->ID, '_bh_vote_base', true);
            $bonus = get_post_meta($post->ID, '_bh_vote_bonus', true);

            // A brand-new contest (nothing saved yet) naturally has both
            // fields blank, so this defaults to checked — "submissions
            // open the moment I publish" is the sensible out-of-the-box
            // behavior, not something that has to be configured first.
            $sub_always_open = ($sub_start === '' && $sub_end === '');

            $phase = BH_Helpers::contest_phase_summary($post->ID);
            echo '<div style="padding:8px 10px;border-radius:4px;background:' . esc_attr($phase['color']) . '1a;border:1px solid ' . esc_attr($phase['color']) . ';margin-bottom:14px;">'
               . '<strong style="color:' . esc_attr($phase['color']) . ';font-size:12px;">' . esc_html($phase['label']) . '</strong></div>';

            echo '<p style="display:flex;align-items:center;justify-content:space-between;"><strong>Submissions</strong> <span id="bh_sub_dot"></span></p>';
            echo '<p><label><input type="checkbox" id="bh_sub_always_open" name="bh_sub_always_open" value="1" ' . checked($sub_always_open, true, false) . '> Open submissions the moment this contest is published</label></p>';
            echo '<div id="bh_sub_dates" style="' . ($sub_always_open ? 'display:none;' : '') . '">';
            echo "<p>Opens: <input type='datetime-local' id='bh_sub_start' name='bh_sub_start' value='" . esc_attr($sub_start) . "'></p>";
            echo "<p>Closes: &nbsp;<input type='datetime-local' id='bh_sub_end' name='bh_sub_end' value='" . esc_attr($sub_end) . "'></p>";
            echo '</div>';

            // Off by default — a submission with no audio yet ('draft'
            // status) can't earn the bonus vote or reach admin review
            // until it's finished (class-api.php's submit()/replace_audio()),
            // but it DOES reserve the entry (blocks a second attempt) —
            // an admin opts a specific contest into this rather than it
            // being silently on everywhere, since most contests want a
            // real file at submit time.
            $allow_audio_optional = (bool) get_post_meta($post->ID, '_bh_allow_audio_optional', true);
            echo '<p><label><input type="checkbox" name="bh_allow_audio_optional" value="1" ' . checked($allow_audio_optional, true, false) . '> Allow submitting without audio yet — a fan can reserve their entry with title/artist/contact info, then finish by uploading a file later (from their account portal) any time before submissions close</label></p>';

            $contact_cfg = BH_Helpers::contact_config($post->ID);
            $field_labels = [
                'real_name' => 'Real name', 'discord_name' => 'Discord', 'twitch_name' => 'Twitch',
                'youtube_name' => 'YouTube', 'typical_platform' => 'Typical platform (dropdown)', 'phone' => 'Phone',
            ];
            echo '<hr><p><strong>Contact info collected at submission</strong></p>';
            echo '<p class="description">Choose what this contest asks submitters for. Leave everything as-is for the default (all fields shown, real name + at least one handle required, phone optional).</p>';
            foreach ($field_labels as $key => $label) {
                $shown = in_array($key, $contact_cfg['show'], true);
                echo '<label style="display:block;margin:2px 0;"><input type="checkbox" class="bh-contact-show" data-field="' . esc_attr($key) . '" name="bh_contact_show[]" value="' . esc_attr($key) . '" ' . checked($shown, true, false) . '> ' . esc_html($label) . '</label>';
            }
            echo '<p style="margin-top:10px;"><strong>Required</strong></p>';
            echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="bh_require_real_name" value="1" ' . checked(!empty($contact_cfg['require_real_name']), true, false) . ' class="bh-contact-require" data-requires="real_name"> Real name</label>';
            echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="bh_require_handle" value="1" ' . checked(!empty($contact_cfg['require_handle']), true, false) . '> At least one platform handle (Discord/Twitch/YouTube)</label>';
            echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="bh_require_phone" value="1" ' . checked(!empty($contact_cfg['require_phone']), true, false) . ' class="bh-contact-require" data-requires="phone"> Phone</label>';
            echo '<p class="description">A field can only be required if it\'s also shown above — unchecking "shown" for a required field will un-require it automatically.</p>';

            echo '<hr><p style="display:flex;align-items:center;justify-content:space-between;"><strong>Voting</strong> <span id="bh_vote_dot"></span></p>';
            echo "<p>Opens: <input type='datetime-local' id='bh_start' name='bh_start' value='" . esc_attr($start) . "'> <button type=\"button\" class=\"button button-small\" id=\"bh_vote_start_now\">When submissions close</button></p>";
            echo "<p>Closes: &nbsp;<input type='datetime-local' id='bh_end' name='bh_end' value='" . esc_attr($end) . "'></p>";

            echo '<hr><p>Votes per category: '
               . '<input type="number" name="bh_vote_base" min="0" max="20" style="width:56px;" value="' . esc_attr($base !== '' ? $base : BH_VOTE_BASE) . '"> base'
               . ' + <input type="number" name="bh_vote_bonus" min="0" max="20" style="width:56px;" value="' . esc_attr($bonus !== '' ? $bonus : BH_VOTE_BONUS) . '"> bonus for submitting</p>';
            echo '<p class="description">Applies to every category on this contest independently (voting in 3 categories with 1+1 votes = up to 6 total). Leave blank for the site default. Bonus only counts once a submission is approved.</p>';
            echo '<hr><p><label><input type="checkbox" name="bh_results_published" value="1" ' . checked($pub, '1', false) . '> <strong>Publish Results to Public</strong></label></p>';
            echo '<p><em>Check this only after the contest ends and you have audited the votes.</em></p>';

            if ($pub === '1') {
                $sent_at = get_post_meta($post->ID, '_bh_winner_notifications_sent_at', true);
                $send_url = wp_nonce_url(admin_url('admin-post.php?action=bh_send_winners&contest_id=' . $post->ID), 'bh_send_winners');
                echo '<p>';
                if ($sent_at) {
                    echo '<span class="description">Winner notifications sent ' . esc_html(mysql2date('M j, g:ia', $sent_at)) . '.</span> ';
                    echo '<a href="' . esc_url($send_url) . '" onclick="return confirm(\'Resend winner notifications? This posts to Discord and emails every winner again.\');">Resend</a>';
                } else {
                    echo '<a href="' . esc_url($send_url) . '" class="button button-primary" onclick="return confirm(\'Send winner notifications now? This posts to Discord and emails every winner immediately — make sure the results above are actually final.\');">Send Winner Notifications</a>';
                }
                echo '</p>';
            }

            $webhook = get_post_meta($post->ID, '_bh_discord_webhook', true);
            echo '<hr><p><strong>Discord notifications</strong> <span class="description">(optional)</span></p>';
            echo '<p><input type="url" name="bh_discord_webhook" value="' . esc_attr($webhook) . '" placeholder="https://discord.com/api/webhooks/..." style="width:100%;"></p>';
            echo '<p class="description">Automatically posts when a track is submitted or voting starts. The results announcement is sent separately, on demand — see "Send Winner Notifications" above. Get a webhook URL from a Discord channel\'s Settings &rarr; Integrations &rarr; Webhooks. Leave blank for no notifications.</p>';

            if ($webhook) {
                $reveal_url = ($rp = (int) get_option('bh_reveal_page_id')) ? get_permalink($rp) : '';
                echo '<p><strong>Announce to Discord</strong> <span class="description">— sends right away, independent of Save/Update</span></p>';
                echo '<textarea id="bh_discord_message" rows="2" style="width:100%;" placeholder="e.g. Going live for the results reveal in 5 minutes!"></textarea>';
                echo '<p style="margin:6px 0;">';
                if ($reveal_url) echo '<button type="button" class="button button-small" id="bh_discord_preset_reveal">Fill: Going live for reveal</button> ';
                echo '</p>';
                echo '<p><button type="button" class="button" id="bh_discord_send">Send to Discord</button> <span id="bh_discord_status" style="margin-left:8px;font-size:12px;"></span></p>';
            }
            ?>
            <script>
            (function () {
                var revealUrl = <?php echo wp_json_encode($reveal_url ?? ''); ?>;
                var msgField = document.getElementById('bh_discord_message');
                var presetReveal = document.getElementById('bh_discord_preset_reveal');
                if (presetReveal) presetReveal.addEventListener('click', function () {
                    msgField.value = '📺 Going live for the results reveal — come watch: ' + revealUrl;
                });

                var sendBtn = document.getElementById('bh_discord_send');
                if (sendBtn) sendBtn.addEventListener('click', function () {
                    var msg = msgField.value.trim();
                    var status = document.getElementById('bh_discord_status');
                    if (!msg) { status.textContent = 'Type a message first.'; status.style.color = '#b3261e'; return; }
                    sendBtn.disabled = true;
                    status.textContent = 'Sending…'; status.style.color = '#787c82';
                    fetch(<?php echo wp_json_encode(esc_url_raw(rest_url('bh/v1/discord/announce'))); ?>, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?> },
                        body: JSON.stringify({ contest: <?php echo (int) $post->ID; ?>, message: msg }),
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            sendBtn.disabled = false;
                            if (data.sent) { status.textContent = 'Sent!'; status.style.color = '#1DB954'; msgField.value = ''; }
                            else { status.textContent = 'Could not send — check the webhook URL.'; status.style.color = '#b3261e'; }
                        })
                        .catch(function () {
                            sendBtn.disabled = false;
                            status.textContent = 'Network error — try again.'; status.style.color = '#b3261e';
                        });
                });
            })();
            </script>
            <script>
            (function () {
                function dot(status) {
                    var live = status === 'open';
                    var label = status === 'open' ? 'Live now' : (status === 'upcoming' ? 'Not started' : (status === 'closed' ? 'Closed' : 'Not scheduled'));
                    return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:' + (live ? '#1DB954' : '#b3261e') + ';">'
                        + '<span style="width:7px;height:7px;border-radius:50%;background:' + (live ? '#1DB954' : '#b3261e') + ';"></span>' + label + '</span>';
                }
                function computeStatus(startEl, endEl, alwaysOpenIfBlank) {
                    var sv = startEl ? startEl.value : '', ev = endEl ? endEl.value : '';
                    if (!sv || !ev) return alwaysOpenIfBlank ? 'open' : 'unscheduled';
                    var now = new Date(), start = new Date(sv), end = new Date(ev);
                    if (now < start) return 'upcoming';
                    if (now > end) return 'closed';
                    return 'open';
                }

                var alwaysCb = document.getElementById('bh_sub_always_open');
                var subDates = document.getElementById('bh_sub_dates');
                var subStart = document.getElementById('bh_sub_start');
                var subEnd = document.getElementById('bh_sub_end');
                var subDot = document.getElementById('bh_sub_dot');
                var voteStart = document.getElementById('bh_start');
                var voteEnd = document.getElementById('bh_end');
                var voteDot = document.getElementById('bh_vote_dot');

                // A "required" checkbox only makes sense if its field is
                // also shown — unchecking "shown" un-checks "required"
                // live, matching the same rule contact_config() enforces
                // server-side, so the correction is visible immediately
                // rather than only discovered after saving.
                var handleFields = ['discord_name', 'twitch_name', 'youtube_name'];
                document.querySelectorAll('.bh-contact-show').forEach(function (showCb) {
                    showCb.addEventListener('change', function () {
                        if (showCb.checked) return;
                        var requireCb = document.querySelector('.bh-contact-require[data-requires="' + showCb.dataset.field + '"]');
                        if (requireCb) requireCb.checked = false;

                        // The composite "at least one handle" rule needs
                        // ANY of the three to remain shown, not this one
                        // specifically — only un-check it once all three
                        // are gone.
                        if (handleFields.indexOf(showCb.dataset.field) !== -1) {
                            var anyHandleShown = handleFields.some(function (f) {
                                var cb = document.querySelector('.bh-contact-show[data-field="' + f + '"]');
                                return cb && cb.checked;
                            });
                            if (!anyHandleShown) {
                                var requireHandleCb = document.querySelector('input[name="bh_require_handle"]');
                                if (requireHandleCb) requireHandleCb.checked = false;
                            }
                        }
                    });
                });

                function refreshSubDot() {
                    subDot.innerHTML = dot(alwaysCb.checked ? 'open' : computeStatus(subStart, subEnd, false));
                }
                function refreshVoteDot() {
                    voteDot.innerHTML = dot(computeStatus(voteStart, voteEnd, false));
                }

                if (alwaysCb) alwaysCb.addEventListener('change', function () {
                    subDates.style.display = alwaysCb.checked ? 'none' : '';
                    refreshSubDot();
                });
                [subStart, subEnd].forEach(function (el) { if (el) el.addEventListener('input', refreshSubDot); });
                [voteStart, voteEnd].forEach(function (el) { if (el) el.addEventListener('input', refreshVoteDot); });

                var startNowBtn = document.getElementById('bh_vote_start_now');
                if (startNowBtn) startNowBtn.addEventListener('click', function () {
                    // "When submissions close" — copies the submission end
                    // date/time straight into the voting start field, since
                    // that's the overwhelmingly common intent (no gap
                    // between the two phases) and otherwise means manually
                    // re-typing a date you already entered two fields above.
                    if (subEnd && subEnd.value) { voteStart.value = subEnd.value; refreshVoteDot(); }
                    else if (subStart) { alert('Set a submissions close date first, or enter the voting start time directly.'); }
                });

                refreshSubDot();
                refreshVoteDot();
            })();
            </script>
            <?php
        }, 'bh_contest', 'side', 'default');

        add_meta_box('bh_contest_categories', 'Voting Categories', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $cats = BH_Helpers::categories($post->ID);
            $text = implode("\n", array_map(fn($c) => $c['name'], $cats));
            echo '<p class="description">One category per line, e.g. "Best Vocals". Leave empty for a single, ordinary vote — this is optional.</p>';
            echo '<textarea name="bh_categories" rows="5" style="width:100%;font-family:inherit;">' . esc_textarea($text) . '</textarea>';
            echo '<p class="description">Voters get their normal 1 (or 2, if they submitted) vote in <em>each</em> category independently. All submissions are eligible in every category — there\'s no per-track assignment.</p>';
        }, 'bh_contest', 'normal', 'default');

        add_meta_box('bh_contest_judging', 'Judging Format', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $format = BH_Helpers::contest_format($post->ID);
            $rubric = BH_Judging::rubric($post->ID);
            $rubric_text = implode("\n", array_map(fn($c) => $c['name'] . ':' . $c['max'], $rubric));
            $judge_ids = BH_Judging::judge_ids($post->ID);
            $judge_names = [];
            foreach ($judge_ids as $jid) {
                $u = get_userdata($jid);
                if ($u) $judge_names[] = $u->user_login;
            }

            echo '<p><label><strong>Format</strong><br><select name="bh_contest_format">';
            echo '<option value="public"' . selected($format, 'public', false) . '>Public voting (default, unchanged)</option>';
            echo '<option value="judges"' . selected($format, 'judges', false) . '>Judges only — a rubric score replaces public voting</option>';
            echo '<option value="hybrid"' . selected($format, 'hybrid', false) . '>Hybrid — both run, shown as two separate leaderboards (Judges\' Pick / People\'s Choice)</option>';
            echo '</select></label></p>';

            echo '<p class="description">Rubric criteria, one per line — "Originality" (defaults to a max of 10) or "Originality:20" for a custom max. Only used when Format is Judges or Hybrid. Each criterion is normalized to a 0–100 scale and averaged, so a 5-criterion and a 3-criterion rubric land on the same footing — a submission\'s final score is the average across every judge who has actually submitted (not just drafted) a score for it.</p>';
            echo '<textarea name="bh_rubric" rows="4" style="width:100%;font-family:inherit;">' . esc_textarea($rubric_text) . '</textarea>';

            echo '<p class="description">Judges, one WordPress username per line. Each must already have an account on this site — judges score from the front-end <code>[bh_judge_panel]</code> shortcode, not wp-admin.</p>';
            echo '<textarea name="bh_judges" rows="3" style="width:100%;font-family:inherit;">' . esc_textarea(implode("\n", $judge_names)) . '</textarea>';

            if ($post->post_status === 'publish' && $judge_ids) {
                echo '<p class="description" style="margin-top:8px;">Judge panel shortcode: <code>[bh_judge_panel contest="' . esc_html($post->post_name) . '"]</code></p>';
            }
        }, 'bh_contest', 'normal', 'default');

        add_meta_box('bh_contest_rounds', 'Rounds (elimination format)', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $rounds = BH_Rounds::rounds($post->ID);
            $count = max(1, count($rounds));
            $active = BH_Rounds::active_round_index($post->ID);

            echo '<p class="description">Leave at 1 round for a normal single-round contest (unchanged default behavior). 2+ rounds turns this into an elimination format — round 1 is the normal submission+voting window; round 2+ only re-votes/re-scores whoever survived the previous cut (leave a round\'s own submission dates blank unless you specifically want it to accept new entries too).</p>';
            echo '<p><label><strong>Number of rounds</strong> <select id="bh_round_count" name="bh_round_count">';
            for ($i = 1; $i <= 4; $i++) echo '<option value="' . $i . '"' . selected($count, $i, false) . '>' . $i . '</option>';
            echo '</select></label></p>';

            if ($post->post_status === 'publish' && count($rounds) > 1) {
                echo '<p><span class="bhy-badge bhy-badge-dot">Active round: ' . ((int) $active + 1) . ' of ' . count($rounds) . '</span></p>';
                if (isset($rounds[$active + 1])) {
                    echo '<p><button type="button" class="button button-primary" id="bh_advance_round" data-contest="' . (int) $post->ID . '" data-nonce="' . esc_attr(wp_create_nonce('bh_advance_round_' . $post->ID)) . '">Close round ' . ((int) $active + 1) . ' &amp; advance to round ' . ((int) $active + 2) . '</button> <span id="bh_advance_round_result" style="margin-left:8px;"></span></p>';
                    echo '<p class="description">Tallies the active round\'s votes/judge scores now, keeps the configured cut count, and opens the next round for the survivors. Cannot be undone from here.</p>';
                } else {
                    echo '<p class="description">This is the final round — nothing left to advance to.</p>';
                }
            }

            for ($i = 0; $i < 4; $i++) {
                $r = $rounds[$i] ?? [];
                $display = $i < $count ? '' : 'display:none;';
                echo '<div class="bh-round-block" data-round-index="' . $i . '" style="' . $display . 'border:1px solid #dcdcde;border-radius:4px;padding:10px;margin-bottom:10px;">';
                echo '<p><strong>Round ' . ($i + 1) . '</strong></p>';
                echo '<p><label>Name<br><input type="text" name="bh_round_name[]" value="' . esc_attr($r['name'] ?? ('Round ' . ($i + 1))) . '" style="width:100%;"></label></p>';
                echo '<p><label>Submission opens (blank = no new entries this round)<br><input type="datetime-local" name="bh_round_sub_start[]" value="' . esc_attr(self::dt_for_input($r['sub_start'] ?? '')) . '"></label> ';
                echo '<label>closes<br><input type="datetime-local" name="bh_round_sub_end[]" value="' . esc_attr(self::dt_for_input($r['sub_end'] ?? '')) . '"></label></p>';
                echo '<p><label>Voting opens<br><input type="datetime-local" name="bh_round_vote_start[]" value="' . esc_attr(self::dt_for_input($r['vote_start'] ?? '')) . '"></label> ';
                echo '<label>closes<br><input type="datetime-local" name="bh_round_vote_end[]" value="' . esc_attr(self::dt_for_input($r['vote_end'] ?? '')) . '"></label></p>';
                echo '<p><label>Cut to (how many advance out of this round)<br><input type="number" min="1" name="bh_round_cut[]" value="' . esc_attr((string) ($r['cut_count'] ?? 8)) . '" style="width:80px;"></label></p>';
                echo '</div>';
            }
            ?>
            <script>
            (function () {
                var sel = document.getElementById('bh_round_count');
                var blocks = document.querySelectorAll('.bh-round-block');
                if (sel) sel.addEventListener('change', function () {
                    var n = parseInt(sel.value, 10);
                    blocks.forEach(function (b) { b.style.display = parseInt(b.dataset.roundIndex, 10) < n ? '' : 'none'; });
                });
                var btn = document.getElementById('bh_advance_round');
                if (btn) btn.addEventListener('click', function () {
                    if (!confirm('Close the active round and advance survivors now? This cannot be undone from here.')) return;
                    btn.disabled = true;
                    var body = new URLSearchParams({ action: 'bh_advance_round', contest_id: btn.dataset.contest, nonce: btn.dataset.nonce });
                    fetch(ajaxurl, { method: 'POST', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            var out = document.getElementById('bh_advance_round_result');
                            if (res.success) {
                                // Was set on the same tick as location.reload() —
                                // the message never had a chance to actually be
                                // seen before the page navigated away.
                                out.textContent = 'Advanced — reloading…';
                                setTimeout(function () { location.reload(); }, 900);
                            }
                            else { out.textContent = (res.data && res.data.message) || 'Could not advance.'; btn.disabled = false; }
                        })
                        .catch(function () {
                            // Previously had no .catch() — a dropped connection
                            // left the button disabled forever with no sign
                            // anything went wrong.
                            document.getElementById('bh_advance_round_result').textContent = 'Could not reach the server — check your connection and try again.';
                            btn.disabled = false;
                        });
                });
            })();
            </script>
            <?php
        }, 'bh_contest', 'normal', 'default');

        add_meta_box('bh_contest_shortcode', 'Shortcode & Page', function ($post) {
            if ($post->post_status !== 'publish') {
                echo '<p class="description">Publish this contest to get its shortcode and page.</p>';
                return;
            }
            $sc = BH_Helpers::shortcode_for($post->ID);
            echo '<input type="text" readonly value="' . esc_attr($sc) . '" onclick="this.select();" style="width:100%;font-family:monospace;font-size:12px;padding:6px;">';
            echo '<p class="description">Paste into any page or post to embed this specific contest. Leaving out the <code>contest</code> attribute always falls back to whichever contest was published most recently — fine for one contest at a time, ambiguous once you\'re running more than one.</p>';
            echo '<hr><p><strong>Page:</strong> ' . BH_AdminMenus::page_links_html($post->ID) . '</p>';
            echo '<p class="description">A simple page with this shortcode was created automatically when you published. If you deleted it, "Create page" makes a new one.</p>';
        }, 'bh_contest', 'side', 'default');

        if (class_exists('OUS_Revisions')) {
            add_meta_box('bh_contest_revisions', 'Version History', function ($post) {
                OUS_Revisions::render_history_panel('bh_contest', $post->ID, 'bh_restore_contest_revision', 'bh_restore_contest_' . $post->ID);
            }, 'bh_contest', 'side', 'default');
        }

        add_meta_box('bh_contest_site_menu', 'Site Menu', function ($post) {
            $page_id = (int) get_post_meta($post->ID, '_bh_page_id', true);
            $has_page = $page_id && get_post_status($page_id) === 'publish';
            $checked = (bool) get_post_meta($post->ID, '_bh_show_in_menu', true);
            $label = get_post_meta($post->ID, '_bh_menu_label', true);

            if (!$has_page) {
                echo '<p class="description">Publish this contest (and its auto-created page) first — a contest with nowhere real to send a visitor can\'t appear in the menu.</p>';
                return;
            }
            echo '<p><label><input type="checkbox" name="bh_show_in_menu" value="1"' . checked($checked, true, false) . '> Show under <strong>Contests</strong> in the site menu</label></p>';
            echo '<p><label>Menu label (optional)<br><input type="text" name="bh_menu_label" value="' . esc_attr($label) . '" placeholder="' . esc_attr($post->post_title) . '" style="width:100%;"></label></p>';
        }, 'bh_contest', 'side', 'default');

        // Separate from the "Contest Branding & Style" override below —
        // this picks a CARD TEMPLATE (brand vs. poster), not a color
        // override; a contest that never turns on style override at all
        // can still choose poster-style share cards.
        add_meta_box('bh_contest_share_cards', 'Shareable images', function ($post) {
            $stored_style = get_post_meta($post->ID, '_bh_share_card_style', true);
            $style = (class_exists('BH_ShareCard') && BH_ShareCard::is_valid_style($stored_style)) ? $stored_style : 'brand';
            echo '<p class="description">Submitters get a "Now entered" and "Vote for me" image after submitting. <strong>Brand</strong> matches this site\'s own live colors; the <strong>Poster</strong> options are bolder, stand-alone looks.</p>';
            if (class_exists('BH_ShareCard')) {
                echo '<p><label>Style<br><select name="bh_share_card_style">';
                foreach (BH_ShareCard::STYLES as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '"' . selected($style, $key, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select></label></p>';
            }
        }, 'bh_contest', 'side', 'default');

        add_meta_box('bh_contest_style', 'Contest Branding & Style', function ($post) {
            wp_nonce_field('bh_save_contest', 'bh_contest_nonce');
            $on = get_post_meta($post->ID, '_bhy_style_override', true);
            $data = json_decode((string) get_post_meta($post->ID, '_bhy_style_json', true), true);
            if (!is_array($data)) $data = [];
            $g = fn($k, $d = '') => $data[$k] ?? $d;
            $defaults = BHY_Style::get(); // site-wide values, shown as placeholders

            echo '<style>' . BHY_UI::swatch_css() . '
                .bh-cat-swatch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; }
            </style>';

            echo '<p class="description">Off by default — this contest just uses the site-wide look from Settings &amp; Style. Turn this on to give '
               . 'this one contest its own logo/brand text and accent colors (e.g. a sponsor or seasonal skin) without changing anything else site-wide.</p>';
            echo '<p><label><input type="checkbox" id="bh_style_override" name="bh_style_override" value="1" ' . checked($on, '1', false) . '> <strong>Override site styling for this contest</strong></label></p>';

            echo '<div id="bh_style_fields" style="' . ($on ? '' : 'display:none;') . ' margin-top:12px;">';

            echo '<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">';
            $logo_id  = (int) $g('brand_logo_id', 0);
            $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
            echo '<div id="bh_contest_logo_preview" style="width:64px;height:64px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;">';
            echo '<img id="bh_contest_logo_img" src="' . esc_url($logo_url) . '" style="max-width:100%;max-height:100%;object-fit:contain;' . ($logo_url ? '' : 'display:none;') . '">';
            echo '<span id="bh_contest_logo_empty" style="font-size:11px;color:#888;' . ($logo_url ? 'display:none;' : '') . '">No logo</span>';
            echo '</div>';
            echo '<div>';
            echo '<input type="hidden" id="bh_contest_logo_id" name="bh_style_logo_id" value="' . esc_attr($logo_id) . '">';
            echo '<button type="button" class="button" id="bh_contest_logo_upload">Upload logo…</button> ';
            echo '<button type="button" class="button" id="bh_contest_logo_remove" style="' . ($logo_url ? '' : 'display:none;') . '">Remove</button>';
            echo '</div></div>';

            // Quick pick — same THEME_GROUPS as Settings & Style, filtered
            // to just the fields a contest is allowed to override. Fills
            // every field below in one click; each stays editable
            // afterward for fine-tuning.
            echo '<p><label for="bh_style_theme_pick"><strong>Quick pick from a theme</strong></label><br>';
            echo '<select id="bh_style_theme_pick" style="max-width:280px;">';
            echo '<option value="">Choose a theme…</option>';
            foreach (BHY_Style::THEME_GROUPS as $group_label => $themes) {
                echo '<optgroup label="' . esc_attr($group_label) . '">';
                foreach ($themes as $name => $colors) {
                    $subset = array_intersect_key($colors, array_flip(BHY_Style::OVERRIDABLE_FIELDS));
                    echo '<option value="' . esc_attr($name) . '" data-set=\'' . esc_attr(wp_json_encode($subset)) . '\'>' . esc_html($name) . '</option>';
                }
                echo '</optgroup>';
            }
            echo '</select></p>';

            echo '<p style="margin-top:14px;"><strong>Brand text</strong> <span class="description">— leave either blank to use the site-wide text</span></p>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';
            echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;">First part<input type="text" name="bh_style_brand1" value="' . esc_attr($g('brand_part1')) . '" placeholder="' . esc_attr($defaults['brand_part1']) . '" style="width:120px;"></label>';
            echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;">Accent part<input type="text" name="bh_style_brand2" value="' . esc_attr($g('brand_part2')) . '" placeholder="' . esc_attr($defaults['brand_part2']) . '" style="width:120px;"></label>';
            echo '</div>';

            echo '<p><strong>Base &amp; surfaces</strong></p>';
            echo '<div class="bh-cat-swatch-grid" style="margin-bottom:14px;">';
            BHY_UI::swatch_field('bh_style_bg', 'bh_style_bg', 'Background', $g('color_bg'), $defaults['color_bg']);
            BHY_UI::swatch_field('bh_style_surface', 'bh_style_surface', 'Surface', $g('color_surface'), $defaults['color_surface']);
            BHY_UI::swatch_field('bh_style_surface_2', 'bh_style_surface_2', 'Surface (raised)', $g('color_surface_2'), $defaults['color_surface_2']);
            BHY_UI::swatch_field('bh_style_border', 'bh_style_border', 'Border', $g('color_border'), $defaults['color_border']);
            BHY_UI::swatch_field('bh_style_text', 'bh_style_text', 'Text', $g('color_text'), $defaults['color_text']);
            BHY_UI::swatch_field('bh_style_text_dim', 'bh_style_text_dim', 'Text (dim)', $g('color_text_dim'), $defaults['color_text_dim']);
            echo '</div>';

            echo '<p><strong>Accent</strong></p>';
            echo '<div class="bh-cat-swatch-grid" style="margin-bottom:14px;">';
            BHY_UI::swatch_field('bh_style_accent', 'bh_style_accent', 'Accent', $g('color_accent'), $defaults['color_accent']);
            BHY_UI::swatch_field('bh_style_accent_soft', 'bh_style_accent_soft', 'Accent (soft)', $g('color_accent_soft'), $defaults['color_accent_soft']);
            BHY_UI::swatch_field('bh_style_overlay', 'bh_style_overlay', 'Modal backdrop', $g('color_overlay'), $defaults['color_overlay']);
            echo '</div>';

            echo '<p><strong>Category colors</strong> <span class="description">— blank falls through to site-wide</span></p>';
            echo '<div class="bh-cat-swatch-grid">';
            for ($i = 1; $i <= 8; $i++) {
                BHY_UI::swatch_field('bh_style_cat_' . $i, 'bh_style_cat_' . $i, 'Category ' . $i, $g('cat_color_' . $i), $defaults['cat_color_' . $i]);
            }
            echo '</div>';
            echo '</div>';
            ?>
            <script>
            <?php echo BHY_UI::swatch_js(); ?>
            (function () {
                var cb = document.getElementById('bh_style_override');
                var fields = document.getElementById('bh_style_fields');
                if (cb) cb.addEventListener('change', function () { fields.style.display = cb.checked ? '' : 'none'; });

                var uploadBtn = document.getElementById('bh_contest_logo_upload');
                var removeBtn = document.getElementById('bh_contest_logo_remove');
                var idField = document.getElementById('bh_contest_logo_id');
                var img = document.getElementById('bh_contest_logo_img');
                var empty = document.getElementById('bh_contest_logo_empty');
                var frame = null;
                if (uploadBtn && window.wp && window.wp.media) {
                    uploadBtn.addEventListener('click', function () {
                        if (frame) { frame.open(); return; }
                        frame = wp.media({ title: 'Choose a logo', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
                        frame.on('select', function () {
                            var att = frame.state().get('selection').first().toJSON();
                            idField.value = att.id;
                            img.src = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                            img.style.display = ''; empty.style.display = 'none'; removeBtn.style.display = '';
                        });
                        frame.open();
                    });
                }
                if (removeBtn) removeBtn.addEventListener('click', function () {
                    idField.value = ''; img.src = ''; img.style.display = 'none'; empty.style.display = ''; removeBtn.style.display = 'none';
                });

                var themePick = document.getElementById('bh_style_theme_pick');
                if (themePick) {
                    themePick.addEventListener('change', function () {
                        var opt = themePick.options[themePick.selectedIndex];
                        if (!opt || !opt.dataset.set) return;
                        var data = JSON.parse(opt.dataset.set);
                        Object.keys(data).forEach(function (key) {
                            var fieldId = 'bh_style_' + (key.indexOf('cat_color_') === 0 ? 'cat_' + key.replace('cat_color_', '') : key.replace('color_', ''));
                            var input = document.getElementById(fieldId);
                            if (!input) return;
                            input.value = data[key];
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        });
                    });
                }
            })();
            </script>
            <?php
        }, 'bh_contest', 'normal', 'default');
    }

    public static function save_contest_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bh_contest_nonce']) || !wp_verify_nonce($_POST['bh_contest_nonce'], 'bh_save_contest')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $posted_style = (string) ($_POST['bh_share_card_style'] ?? '');
        update_post_meta($post_id, '_bh_share_card_style', (class_exists('BH_ShareCard') && BH_ShareCard::is_valid_style($posted_style)) ? $posted_style : 'brand');

        if (!empty($_POST['bh_sub_always_open'])) {
            // Toggle checked — always-open, regardless of whatever might
            // still be sitting in the (hidden, but still submitted)
            // date fields from a previous explicit schedule.
            update_post_meta($post_id, '_bh_sub_start', '');
            update_post_meta($post_id, '_bh_sub_end', '');
        } else {
            if (isset($_POST['bh_sub_start'])) update_post_meta($post_id, '_bh_sub_start', sanitize_text_field($_POST['bh_sub_start']));
            if (isset($_POST['bh_sub_end']))   update_post_meta($post_id, '_bh_sub_end', sanitize_text_field($_POST['bh_sub_end']));
        }
        update_post_meta($post_id, '_bh_allow_audio_optional', !empty($_POST['bh_allow_audio_optional']) ? '1' : '');
        if (isset($_POST['bh_start'])) update_post_meta($post_id, '_bh_start', sanitize_text_field($_POST['bh_start']));
        if (isset($_POST['bh_end']))   update_post_meta($post_id, '_bh_end', sanitize_text_field($_POST['bh_end']));
        update_post_meta($post_id, '_bh_results_published', isset($_POST['bh_results_published']) ? '1' : '0');
        if (isset($_POST['bh_vote_base']))  update_post_meta($post_id, '_bh_vote_base', max(0, (int) $_POST['bh_vote_base']));
        if (isset($_POST['bh_vote_bonus'])) update_post_meta($post_id, '_bh_vote_bonus', max(0, (int) $_POST['bh_vote_bonus']));

        // Sanitized against the known field list rather than trusted
        // as-is — a stray/unexpected value in bh_contact_show[] should
        // never end up persisted just because it showed up in $_POST.
        $shown = array_values(array_intersect(
            (array) ($_POST['bh_contact_show'] ?? []),
            BH_Helpers::CONTACT_FIELDS
        ));
        $contact_config = [
            'show' => $shown,
            'require_real_name' => !empty($_POST['bh_require_real_name']) && in_array('real_name', $shown, true),
            'require_handle'    => !empty($_POST['bh_require_handle']),
            'require_phone'     => !empty($_POST['bh_require_phone']) && in_array('phone', $shown, true),
        ];
        update_post_meta($post_id, '_bh_contact_config', wp_json_encode($contact_config));

        if (isset($_POST['bh_discord_webhook'])) {
            $webhook = esc_url_raw(trim($_POST['bh_discord_webhook']));
            update_post_meta($post_id, '_bh_discord_webhook', $webhook);
        }

        if (isset($_POST['bh_categories'])) {
            $cats = BH_Helpers::parse_categories_input(wp_unslash($_POST['bh_categories']));
            update_post_meta($post_id, '_bh_categories', $cats ? wp_json_encode($cats) : '');
        }

        if (isset($_POST['bh_contest_format'])) {
            $format = in_array($_POST['bh_contest_format'], ['judges', 'hybrid'], true) ? $_POST['bh_contest_format'] : 'public';
            update_post_meta($post_id, '_bh_contest_format', $format);
        }
        if (isset($_POST['bh_rubric'])) {
            $rubric = BH_Judging::parse_rubric_input(wp_unslash($_POST['bh_rubric']));
            update_post_meta($post_id, '_bh_rubric', $rubric ? wp_json_encode($rubric) : '');
        }
        if (isset($_POST['bh_round_name'])) {
            $names = (array) $_POST['bh_round_name'];
            $count = max(1, min(4, (int) ($_POST['bh_round_count'] ?? 1)));
            $sub_starts = (array) ($_POST['bh_round_sub_start'] ?? []);
            $sub_ends   = (array) ($_POST['bh_round_sub_end'] ?? []);
            $vote_starts = (array) ($_POST['bh_round_vote_start'] ?? []);
            $vote_ends   = (array) ($_POST['bh_round_vote_end'] ?? []);
            $cuts = (array) ($_POST['bh_round_cut'] ?? []);

            $rounds = [];
            for ($i = 0; $i < $count; $i++) {
                $name = sanitize_text_field($names[$i] ?? ('Round ' . ($i + 1)));
                $rounds[] = [
                    'name' => $name !== '' ? $name : ('Round ' . ($i + 1)),
                    'sub_start' => sanitize_text_field($sub_starts[$i] ?? ''),
                    'sub_end' => sanitize_text_field($sub_ends[$i] ?? ''),
                    'vote_start' => sanitize_text_field($vote_starts[$i] ?? ''),
                    'vote_end' => sanitize_text_field($vote_ends[$i] ?? ''),
                    'cut_count' => max(1, (int) ($cuts[$i] ?? 8)),
                ];
            }
            // Exactly 1 round stored as an EMPTY meta value, not a
            // single-item array — is_multi_round()'s count() > 1 check
            // (class-rounds.php) means this is functionally identical
            // either way, but storing '' for the common single-round
            // case keeps get_post_meta() cheap-and-empty for every
            // contest that never touches this feature, matching
            // _bh_categories'/_bh_rubric's own "blank means off" convention.
            update_post_meta($post_id, '_bh_rounds', $count > 1 ? wp_json_encode($rounds) : '');
        }

        if (isset($_POST['bh_judges'])) {
            // Usernames -> IDs, resolved (not trusted) on the way in — a
            // typo'd or since-deleted username just silently drops from
            // the list rather than storing a dangling/invalid entry.
            $ids = [];
            foreach (preg_split('/[\r\n]+/', wp_unslash($_POST['bh_judges'])) as $line) {
                $login = trim($line);
                if ($login === '') continue;
                $u = get_user_by('login', $login);
                if ($u) $ids[] = $u->ID;
            }
            update_post_meta($post_id, '_bh_judges', $ids ? wp_json_encode(array_values(array_unique($ids))) : '');
        }

        update_post_meta($post_id, '_bhy_style_override', isset($_POST['bh_style_override']) ? '1' : '');

        // Only fields the admin actually filled in get stored — a blank
        // field means "use the site-wide value", not "override with an
        // empty string". See BHY_Style::entity_overrides().
        $style = [];
        if (!empty($_POST['bh_style_logo_id']))     $style['brand_logo_id']    = (int) $_POST['bh_style_logo_id'];
        if (!empty($_POST['bh_style_brand1']))      $style['brand_part1']      = sanitize_text_field($_POST['bh_style_brand1']);
        if (!empty($_POST['bh_style_brand2']))      $style['brand_part2']      = sanitize_text_field($_POST['bh_style_brand2']);
        if (!empty($_POST['bh_style_bg']))          $style['color_bg']          = sanitize_text_field($_POST['bh_style_bg']);
        if (!empty($_POST['bh_style_surface']))     $style['color_surface']     = sanitize_text_field($_POST['bh_style_surface']);
        if (!empty($_POST['bh_style_surface_2']))   $style['color_surface_2']   = sanitize_text_field($_POST['bh_style_surface_2']);
        if (!empty($_POST['bh_style_border']))      $style['color_border']      = sanitize_text_field($_POST['bh_style_border']);
        if (!empty($_POST['bh_style_text']))        $style['color_text']        = sanitize_text_field($_POST['bh_style_text']);
        if (!empty($_POST['bh_style_text_dim']))    $style['color_text_dim']    = sanitize_text_field($_POST['bh_style_text_dim']);
        if (!empty($_POST['bh_style_accent']))      $style['color_accent']      = sanitize_text_field($_POST['bh_style_accent']);
        if (!empty($_POST['bh_style_accent_soft'])) $style['color_accent_soft'] = sanitize_text_field($_POST['bh_style_accent_soft']);
        if (!empty($_POST['bh_style_overlay']))     $style['color_overlay']     = sanitize_text_field($_POST['bh_style_overlay']);
        for ($i = 1; $i <= 8; $i++) {
            if (!empty($_POST['bh_style_cat_' . $i])) $style['cat_color_' . $i] = sanitize_text_field($_POST['bh_style_cat_' . $i]);
        }
        update_post_meta($post_id, '_bhy_style_json', $style ? wp_json_encode($style) : '');

        // Real OUS_Revisions consumer — versioning matters most for
        // anything that's a post, like contests and lessons. Lessons
        // get WordPress core's own NATIVE post-
        // revisions for free (bh_lesson's real post_content, just
        // needed 'revisions' support added — see class-post-types.php).
        // A contest is different: its real configuration (dates, rounds,
        // rubric, contact requirements, brand style) lives entirely in
        // postmeta, never post_content/title — native WP revisions
        // would capture nothing meaningful for it. get_post_meta()'s
        // full flat dump is the honest "complete current state" here,
        // rather than hand-curating a field list that would silently
        // drift out of sync with this save method's own field list.
        update_post_meta($post_id, '_bh_show_in_menu', !empty($_POST['bh_show_in_menu']) ? '1' : '');
        if (isset($_POST['bh_menu_label'])) {
            update_post_meta($post_id, '_bh_menu_label', sanitize_text_field($_POST['bh_menu_label']));
        }

        if (class_exists('OUS_Revisions')) {
            $all_meta = get_post_meta($post_id);
            $flat = [];
            foreach ($all_meta as $key => $values) {
                if (strpos($key, '_bh_') === 0 || $key === '_bhy_style_json') $flat[$key] = $values[0] ?? '';
            }
            OUS_Revisions::snapshot('bh_contest', $post_id, $flat);
        }

        BH_AdminMenus::maybe_create_contest_page($post_id);
        BH_AdminMenus::resync_menu();
    }

    // <input type="datetime-local"> requires "YYYY-MM-DDTHH:MM" (a literal
    // T, optionally with seconds). Values written by "Start now"/"End now"
    // come from current_time('mysql') ("YYYY-MM-DD HH:MM:SS", a space) —
    // convert back so the field re-populates instead of showing blank.
    private static function dt_for_input($v) {
        if (!$v) return '';
        $v = str_replace(' ', 'T', trim($v));
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}):\d{2}$/', $v, $m)) $v = $m[1];
        return $v;
    }
}
