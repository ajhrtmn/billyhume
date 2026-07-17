<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers this plugin's three existing style previews (the player,
 * the sign-up/submit forms, the results modal) into bh-style's gallery
 * — same exact markup that used to be hardcoded into this plugin's own
 * settings page, now reached via the bhy_style_surfaces filter instead.
 * bh-style never needs to know these are "contest" previews specifically
 * — it just renders whatever HTML+CSS a registered surface hands it.
 */
class BH_StyleSurfaces {
    public static function init() {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    public static function register($surfaces) {
        $surfaces['bh-contest-player'] = [
            'group' => 'Contest', 'label' => 'Player',
            'render' => [self::class, 'player_preview'],
        ];
        $surfaces['bh-contest-forms'] = [
            'group' => 'Contest', 'label' => 'Sign Up & Submit',
            'render' => [self::class, 'forms_preview'],
        ];
        $surfaces['bh-contest-results'] = [
            'group' => 'Contest', 'label' => 'Results',
            'render' => [self::class, 'results_preview'],
        ];
        // Design Suite gallery gap: the guided "New Contest" wizard
        // (BH_ContestWizard, built this session — VISION.md's own
        // "it just works" principle) is a real wp-admin screen
        // (.wrap/.button/.notice — WP core's own admin chrome), so
        // this preview loads WP core's own common.min.css rather than
        // this plugin's player.css (which the OTHER three surfaces
        // above correctly use, since their real markup uses THIS
        // plugin's own custom classes instead).
        $surfaces['bh-contest-wizard'] = [
            'group' => 'Contest', 'label' => 'New Contest wizard',
            'render' => [self::class, 'wizard_preview'],
        ];
        return $surfaces;
    }

    public static function wizard_preview() {
        ob_start();
        ?>
<div class="wrap" style="background:#f0f0f1;color:#1d2327;padding:16px;margin:0;">
    <h1>New Contest &mdash; Guided Setup</h1>
    <p class="description">Covers what every contest needs to run. Rounds, Discord notifications, contact-field customization, and branding all stay on the real edit screen with sensible defaults.</p>

    <h2>1. Name</h2>
    <p><input type="text" style="width:100%;max-width:480px;" value="Summer Anthem Contest"></p>

    <h2>2. Submissions</h2>
    <p><label><input type="checkbox" checked> Open the moment this contest is published (recommended)</label></p>

    <h2>3. Voting</h2>
    <p>Opens: <input type="datetime-local"> <button class="button button-small">When submissions close</button></p>
    <p>Closes: <input type="datetime-local"></p>

    <h2>4. Categories <span class="description">(optional)</span></h2>
    <textarea rows="3" style="width:100%;max-width:480px;font-family:inherit;">Best Vocals
Best Production</textarea>

    <p style="margin-top:20px;"><button class="button button-primary button-hero">Create contest</button></p>
</div>
        <?php
        return ['css_url' => admin_url('css/common.min.css'), 'html' => ob_get_clean()];
    }

    private static function css_url() {
        return BH_URL . 'assets/css/player.css';
    }

    public static function player_preview() {
        ob_start();
        ?>
<div class="bh-container">
    <div class="bh-header">
        <div class="bh-brand" id="bh-brand"><span id="bh-brand-1">Your</span><span id="bh-brand-2">Brand</span></div>
        <div class="bh-header-actions">
            <button class="bh-results-btn bh-btn bh-btn-results">Results</button>
            <button class="bh-submit-btn bh-btn bh-btn-primary">Submit a Song</button>
            <a href="#" class="bh-logout-btn bh-btn bh-btn-outline">Log Out</a>
        </div>
    </div>

    <div class="bh-category-tabs">
        <button class="bh-cat-tab active" style="--bh-cat-color:var(--bh-cat-1)">Pop</button>
        <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-2)">Rock</button>
        <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-3)">Electronic</button>
    </div>

    <div class="bh-tracklist">
        <div class="bh-track-row">
            <div class="bh-disc spinning" style="--bh-hue:20;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title">Midnight Static</div>
                <div class="bh-track-artist">Nova Bloom</div>
            </div>
            <button class="bh-vote-btn voted" style="--bh-cat-color:var(--bh-cat-1)"><span class="bh-check">&#10003;</span> Vote</button>
        </div>
        <div class="bh-track-row">
            <div class="bh-disc" style="--bh-hue:190;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title">Glass Horizon</div>
                <div class="bh-track-artist">Echo Parade</div>
            </div>
            <button class="bh-vote-btn" style="--bh-cat-color:var(--bh-cat-1)">Vote</button>
        </div>
        <div class="bh-track-row">
            <div class="bh-disc" style="--bh-hue:300;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title">Paper Satellites</div>
                <div class="bh-track-artist">The Low Reply</div>
            </div>
            <button class="bh-vote-btn" style="--bh-cat-color:var(--bh-cat-1)">Vote</button>
        </div>
    </div>

    <div class="bh-now-playing-bar">
        <div class="bh-np-track">
            <div class="bh-disc bh-np-disc spinning" style="--bh-hue:20;"></div>
            <div class="bh-np-info"><strong>Midnight Static</strong><br><small>Nova Bloom</small></div>
        </div>
        <div class="bh-scrubber-container">
            <span class="bh-time bh-time-elapsed">1:12</span>
            <input type="range" class="bh-scrubber" value="38" min="0" max="100" step="0.1">
            <span class="bh-time bh-time-duration">3:04</span>
        </div>
        <button class="bh-play-pause" aria-label="Play or pause">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
        </button>
    </div>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }

    public static function forms_preview() {
        ob_start();
        ?>
<div style="display:flex;gap:20px;flex-wrap:wrap;padding:4px;">
    <div style="flex:1;min-width:280px;">
        <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
            <span class="bh-close">&times;</span>
            <h2>Sign Up</h2>
            <input type="text" placeholder="Username">
            <input type="password" placeholder="Password">
            <input type="email" placeholder="Email (sign up only)">
            <div class="bh-reg-extra" style="display:flex;">
                <small>Optional — helps us credit you if you ever submit a track.</small>
                <div class="bh-field-row">
                    <input type="text" placeholder="Real name">
                    <label class="bh-pub-toggle"><input type="checkbox"> public</label>
                </div>
                <div class="bh-field-row">
                    <input type="text" placeholder="Discord username">
                    <label class="bh-pub-toggle"><input type="checkbox" checked> public</label>
                </div>
                <div class="bh-select-wrap">
                    <button type="button" class="bh-select-trigger"><span>Where do you usually watch?</span>
                        <svg class="bh-select-chevron" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                    </button>
                </div>
            </div>
            <button class="bh-auth-submit bh-btn bh-btn-primary">Continue</button>
            <p><a href="#">Need an account? Sign up</a></p>
        </div>
    </div>

    <div style="flex:1;min-width:280px;">
        <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
            <span class="bh-close">&times;</span>
            <h2>Submit Your Track</h2>
            <input type="text" placeholder="Song title">
            <input type="text" placeholder="Artist name">
            <textarea placeholder="Note to admins (optional)" rows="2"></textarea>
            <small>We need your real name and at least one way to reach you.</small>
            <div class="bh-field-row">
                <input type="text" placeholder="Real name" value="Nova Bloom">
                <label class="bh-pub-toggle"><input type="checkbox" checked> public</label>
            </div>
            <div class="bh-select-wrap open">
                <button type="button" class="bh-select-trigger"><span>YouTube</span>
                    <svg class="bh-select-chevron" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                </button>
                <div class="bh-select-menu" style="display:block;">
                    <div class="bh-select-option">Where do you usually watch?</div>
                    <div class="bh-select-option selected">YouTube</div>
                    <div class="bh-select-option">Twitch</div>
                </div>
            </div>
            <div style="height:100px;"></div>
            <label class="bh-file-label"><span>Choose an audio file…</span></label>
            <small>MP3 or M4A · Max 20MB</small>
            <button class="bh-upload-btn bh-btn bh-btn-primary">Upload</button>
        </div>
    </div>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }

    public static function results_preview() {
        ob_start();
        ?>
<div style="padding:4px;">
    <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
        <span class="bh-close">&times;</span>
        <h2>Results</h2>
        <div class="bh-category-tabs bh-results-tabs">
            <button class="bh-cat-tab active" style="--bh-cat-color:var(--bh-text-dim)">All</button>
            <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-1)">Pop</button>
            <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-2)">Rock</button>
        </div>
        <ol class="bh-results-list">
            <li class="bh-results-top">
                <span class="bh-results-rank">🥇</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">Midnight Static</span>
                    <span class="bh-results-artist">Nova Bloom</span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-1)">Pop</span>
                <span class="bh-results-votes">128 votes</span>
            </li>
            <li class="bh-results-top">
                <span class="bh-results-rank">🥈</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">Glass Horizon</span>
                    <span class="bh-results-artist">Echo Parade</span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-2)">Rock</span>
                <span class="bh-results-votes">96 votes</span>
            </li>
            <li>
                <span class="bh-results-rank">#3</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song">Paper Satellites</span>
                    <span class="bh-results-artist">The Low Reply</span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-1)">Pop</span>
                <span class="bh-results-votes">54 votes</span>
            </li>
        </ol>
    </div>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }
}
