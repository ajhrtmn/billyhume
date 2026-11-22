<?php
if (!defined('ABSPATH')) exit;

/**
 * Static style previews (player, forms, results, reveal, library) registered
 * into the Styles gallery via the bhy_style_surfaces filter.
 */
class BH_StyleSurfaces {
    public static function init(): void {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register($surfaces): array {
        $surfaces['bh-contest-player'] = [
            'group' => 'Contest', 'label' => BHCON_Words::get('admin.style_surfaces.player'),
            'render' => [self::class, 'player_preview'],
        ];
        $surfaces['bh-contest-forms'] = [
            'group' => 'Contest', 'label' => BHCON_Words::get('admin.style_surfaces.sign_up_submit'),
            'render' => [self::class, 'forms_preview'],
        ];
        $surfaces['bh-contest-results'] = [
            'group' => 'Contest', 'label' => BHCON_Words::get('admin.admin_metaboxes.results'),
            'render' => [self::class, 'results_preview'],
        ];
        // Static markup mirroring reveal.ts's category_reveal step; keep in sync.
        $surfaces['bh-contest-reveal'] = [
            'group' => 'Contest', 'label' => BHCON_Words::get('admin.style_surfaces.results_reveal'),
            'render' => [self::class, 'reveal_preview'],
        ];
        // Static markup mirroring BH_Contest_Library's card grid; keep in sync.
        $surfaces['bh-contest-library'] = [
            'group' => 'Contest', 'label' => BHCON_Words::get('admin.style_surfaces.contest_library_catalog'),
            'render' => [self::class, 'library_preview'],
        ];
        return $surfaces;
    }

    /** @return array{css_url:string, html:string|false} */
    public static function library_preview(): array {
        ob_start();
        ?>
<div class="bh-container bh-contest-library">
    <div class="bh-contests-landing">
        <p class="bh-contest-library-lead"><?php echo BHCON_Words::get('admin.style_surfaces.2_contests_tracked_from_first'); ?></p>

        <div class="bh-contests-landing-section is-open">
            <h2 class="bh-contests-landing-heading"><span class="bh-contests-landing-heading-dot" aria-hidden="true"></span><?php echo BHCON_Words::get('admin.style_surfaces.open_now'); ?><span class="bh-contests-landing-count">1</span></h2>
            <div class="bh-contests-landing-grid">
                <a href="#" class="bh-contest-card tone-live is-ending">
                    <div class="bh-contest-card-cover" data-placeholder="1" style="background:linear-gradient(135deg,var(--bh-accent),var(--bh-cat-2,var(--bh-accent-soft)));">
                        <span class="bh-contest-phase-pill"><span class="bh-contest-phase-dot" aria-hidden="true"></span><?php echo BHCON_Words::get('admin.helpers.voting_open'); ?></span>
                        <span class="bh-contest-countdown"><span class="bh-contest-countdown-label"><?php echo BHCON_Words::get('admin.style_surfaces.voting_closes'); ?></span><span class="bh-contest-countdown-value">in 2 days</span></span>
                    </div>
                    <div class="bh-contest-card-body">
                        <div class="bh-contest-card-headline">
                            <h3 class="bh-contest-card-title"><?php echo BHCON_Words::get('admin.style_surfaces.summer_anthem_contest'); ?></h3>
                            <div class="bh-contest-card-badges"><span class="bh-contest-badge">2 categories</span></div>
                        </div>
                        <p class="bh-contest-card-excerpt"><?php echo BHCON_Words::get('admin.style_surfaces.submit_best_summer_vibe_track'); ?></p>
                        <ul class="bh-contest-card-stats">
                            <li><span class="dashicons dashicons-groups" aria-hidden="true"></span>18 entries</li>
                            <li><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>212 votes</li>
                        </ul>
                    </div>
                    <ol class="bh-contest-track" aria-label="Contest progress">
                        <li class="is-done"><span class="bh-contest-track-dot" aria-hidden="true"></span><span class="bh-contest-track-label"><?php echo BHCON_Words::get('admin.admin_metaboxes.submissions'); ?></span></li>
                        <li class="is-current"><span class="bh-contest-track-dot" aria-hidden="true"></span><span class="bh-contest-track-label"><?php echo BHCON_Words::get('admin.admin_metaboxes.voting'); ?></span></li>
                        <li class="is-todo"><span class="bh-contest-track-dot" aria-hidden="true"></span><span class="bh-contest-track-label"><?php echo BHCON_Words::get('admin.admin_metaboxes.results'); ?></span></li>
                    </ol>
                </a>
            </div>
        </div>

        <div class="bh-contests-landing-section is-past">
            <h2 class="bh-contests-landing-heading"><span class="bh-contests-landing-heading-dot" aria-hidden="true"></span><?php echo BHCON_Words::get('admin.style_surfaces.wrapped'); ?><span class="bh-contests-landing-count">1</span></h2>
            <div class="bh-contests-landing-grid">
                <a href="#" class="bh-contest-card tone-done">
                    <div class="bh-contest-card-cover" data-placeholder="1" style="background:linear-gradient(135deg,var(--bh-cat-3,var(--bh-accent)),var(--bh-accent-soft));">
                        <span class="bh-contest-phase-pill"><span class="bh-contest-phase-dot" aria-hidden="true"></span><?php echo BHCON_Words::get('admin.style_surfaces.results'); ?></span>
                    </div>
                    <div class="bh-contest-card-body">
                        <div class="bh-contest-card-headline"><h3 class="bh-contest-card-title"><?php echo BHCON_Words::get('admin.style_surfaces.spring_songwriting_sprint'); ?></h3></div>
                        <div class="bh-contest-card-feature"><?php echo BHCON_Words::get('admin.style_surfaces.winner_glass_horizon_echo_parade'); ?></div>
                    </div>
                    <ol class="bh-contest-track" aria-label="Contest progress">
                        <li class="is-done"><span class="bh-contest-track-dot" aria-hidden="true"></span><span class="bh-contest-track-label"><?php echo BHCON_Words::get('admin.admin_metaboxes.submissions'); ?></span></li>
                        <li class="is-done"><span class="bh-contest-track-dot" aria-hidden="true"></span><span class="bh-contest-track-label"><?php echo BHCON_Words::get('admin.admin_metaboxes.voting'); ?></span></li>
                        <li class="is-done"><span class="bh-contest-track-dot" aria-hidden="true"></span><span class="bh-contest-track-label"><?php echo BHCON_Words::get('admin.admin_metaboxes.results'); ?></span></li>
                    </ol>
                </a>
            </div>
        </div>
    </div>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }

    private static function css_url(): string {
        return BH_URL . 'assets/css/player.css';
    }

    /** @return array{css_url:string, html:string|false} */
    public static function player_preview(): array {
        ob_start();
        ?>
<div class="bh-container">
    <div class="bh-header">
        <div class="bh-brand" id="bh-brand"><span id="bh-brand-1"><?php echo BHCON_Words::get('admin.style_surfaces.text'); ?></span><span id="bh-brand-2"><?php echo BHCON_Words::get('admin.style_surfaces.brand'); ?></span></div>
        <div class="bh-header-actions">
            <button class="bh-results-btn bh-btn bh-btn-results"><?php echo BHCON_Words::get('admin.admin_metaboxes.results'); ?></button>
            <button class="bh-submit-btn bh-btn bh-btn-primary"><?php echo BHCON_Words::get('admin.style_surfaces.submit_song'); ?></button>
            <a href="#" class="bh-logout-btn bh-btn bh-btn-outline">Log Out</a>
        </div>
    </div>

    <div class="bh-category-tabs">
        <button class="bh-cat-tab active" style="--bh-cat-color:var(--bh-cat-1)"><?php echo BHCON_Words::get('admin.style_surfaces.pop'); ?></button>
        <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-2)"><?php echo BHCON_Words::get('admin.style_surfaces.rock'); ?></button>
        <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-3)"><?php echo BHCON_Words::get('admin.style_surfaces.electronic'); ?></button>
    </div>

    <div class="bh-tracklist">
        <div class="bh-track-row">
            <div class="bh-disc spinning" style="--bh-hue:20;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title"><?php echo BHCON_Words::get('admin.style_surfaces.midnight_static'); ?></div>
                <div class="bh-track-artist">Nova Bloom</div>
            </div>
            <button class="bh-vote-btn voted" style="--bh-cat-color:var(--bh-cat-1)"><span class="bh-check">&#10003;</span> <?php echo BHCON_Words::get('admin.admin_list_tables.vote'); ?></button>
        </div>
        <div class="bh-track-row">
            <div class="bh-disc" style="--bh-hue:190;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title"><?php echo BHCON_Words::get('admin.style_surfaces.glass_horizon'); ?></div>
                <div class="bh-track-artist"><?php echo BHCON_Words::get('admin.style_surfaces.echo_parade'); ?></div>
            </div>
            <button class="bh-vote-btn" style="--bh-cat-color:var(--bh-cat-1)"><?php echo BHCON_Words::get('admin.admin_list_tables.vote'); ?></button>
        </div>
        <div class="bh-track-row">
            <div class="bh-disc" style="--bh-hue:300;"></div>
            <div class="bh-track-details">
                <div class="bh-track-title"><?php echo BHCON_Words::get('admin.style_surfaces.paper_satellites'); ?></div>
                <div class="bh-track-artist"><?php echo BHCON_Words::get('admin.style_surfaces.low_reply'); ?></div>
            </div>
            <button class="bh-vote-btn" style="--bh-cat-color:var(--bh-cat-1)"><?php echo BHCON_Words::get('admin.admin_list_tables.vote'); ?></button>
        </div>
    </div>

    <div class="bh-now-playing-bar">
        <div class="bh-np-track">
            <div class="bh-disc bh-np-disc spinning" style="--bh-hue:20;"></div>
            <div class="bh-np-info"><strong><?php echo BHCON_Words::get('admin.style_surfaces.midnight_static'); ?></strong><br><small>Nova Bloom</small></div>
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

    /** @return array{css_url:string, html:string|false} */
    public static function forms_preview(): array {
        ob_start();
        ?>
<div style="display:flex;gap:20px;flex-wrap:wrap;padding:4px;">
    <div style="flex:1;min-width:280px;">
        <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
            <span class="bh-close">&times;</span>
            <h2><?php echo BHCON_Words::get('admin.style_surfaces.sign_up'); ?></h2>
            <input type="text" placeholder="Username">
            <input type="password" placeholder="Password">
            <input type="email" placeholder="Email (sign up only)">
            <div class="bh-reg-extra" style="display:flex;">
                <small><?php echo BHCON_Words::get('admin.style_surfaces.optional_helps_us_credit_if'); ?></small>
                <div class="bh-field-row">
                    <input type="text" placeholder="Real name">
                    <label class="bh-pub-toggle"><input type="checkbox"> public</label>
                </div>
                <div class="bh-field-row">
                    <input type="text" placeholder="Discord username">
                    <label class="bh-pub-toggle"><input type="checkbox" checked> public</label>
                </div>
                <div class="bh-select-wrap">
                    <button type="button" class="bh-select-trigger"><span><?php echo BHCON_Words::get('admin.style_surfaces.where_do_usually_watch'); ?></span>
                        <svg class="bh-select-chevron" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                    </button>
                </div>
            </div>
            <button class="bh-auth-submit bh-btn bh-btn-primary"><?php echo BHCON_Words::get('admin.style_surfaces.continue'); ?></button>
            <p><a href="#"><?php echo BHCON_Words::get('admin.style_surfaces.need_account_sign_up'); ?></a></p>
        </div>
    </div>

    <div style="flex:1;min-width:280px;">
        <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
            <span class="bh-close">&times;</span>
            <h2><?php echo BHCON_Words::get('admin.style_surfaces.submit_track'); ?></h2>
            <input type="text" placeholder="Song title">
            <input type="text" placeholder="Artist name">
            <textarea placeholder="Note to admins (optional)" rows="2"></textarea>
            <small><?php echo BHCON_Words::get('admin.style_surfaces.we_need_real_name_at'); ?></small>
            <div class="bh-field-row">
                <input type="text" placeholder="Real name" value="Nova Bloom">
                <label class="bh-pub-toggle"><input type="checkbox" checked> public</label>
            </div>
            <div class="bh-select-wrap open">
                <button type="button" class="bh-select-trigger"><span><?php echo BHCON_Words::get('admin.style_surfaces.youtube'); ?></span>
                    <svg class="bh-select-chevron" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                </button>
                <div class="bh-select-menu" style="display:block;">
                    <div class="bh-select-option"><?php echo BHCON_Words::get('admin.style_surfaces.where_do_usually_watch'); ?></div>
                    <div class="bh-select-option selected"><?php echo BHCON_Words::get('admin.style_surfaces.youtube'); ?></div>
                    <div class="bh-select-option"><?php echo BHCON_Words::get('admin.style_surfaces.twitch'); ?></div>
                </div>
            </div>
            <div style="height:100px;"></div>
            <label class="bh-file-label"><span><?php echo BHCON_Words::get('admin.style_surfaces.choose_audio_file'); ?></span></label>
            <small><?php echo BHCON_Words::get('admin.style_surfaces.mp3_m4a_max_20mb'); ?></small>
            <button class="bh-upload-btn bh-btn bh-btn-primary"><?php echo BHCON_Words::get('admin.style_surfaces.upload'); ?></button>
        </div>
    </div>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }

    /** @return array{css_url:string, html:string|false} */
    public static function reveal_preview(): array {
        ob_start();
        ?>
<div class="bh-reveal-wrap">
    <button type="button" class="bh-reveal-sound-toggle" aria-label="Toggle reveal sound effects" aria-pressed="true">🔊</button>
    <span class="bh-reveal-live"><span class="bh-reveal-live-dot"></span><span class="bh-reveal-live-label"><?php echo BHCON_Words::get('admin.reveal.live'); ?></span></span>
    <div class="bh-container bh-reveal-stage">
        <div class="bh-reveal-board">
            <div class="bh-reveal-kicker"><?php echo BHCON_Words::get('admin.style_surfaces.overall'); ?></div>
            <div class="bh-reveal-entries">
                <div class="bh-reveal-entry bh-reveal-entry-winner bh-reveal-entry-new">
                    <span class="bh-reveal-medal">🥇</span>
                    <span class="bh-reveal-entry-info">
                        <span class="bh-reveal-entry-title"><?php echo BHCON_Words::get('admin.style_surfaces.midnight_static'); ?></span>
                        <span class="bh-reveal-entry-artist">Nova Bloom</span>
                    </span>
                    <span class="bh-reveal-entry-votes">128 votes</span>
                </div>
                <div class="bh-reveal-entry">
                    <span class="bh-reveal-medal">🥈</span>
                    <span class="bh-reveal-entry-info">
                        <span class="bh-reveal-entry-title"><?php echo BHCON_Words::get('admin.style_surfaces.glass_horizon'); ?></span>
                        <span class="bh-reveal-entry-artist"><?php echo BHCON_Words::get('admin.style_surfaces.echo_parade'); ?></span>
                    </span>
                    <span class="bh-reveal-entry-votes">96 votes</span>
                </div>
                <div class="bh-reveal-entry">
                    <span class="bh-reveal-medal">#3</span>
                    <span class="bh-reveal-entry-info">
                        <span class="bh-reveal-entry-title"><?php echo BHCON_Words::get('admin.style_surfaces.paper_satellites'); ?></span>
                        <span class="bh-reveal-entry-artist"><?php echo BHCON_Words::get('admin.style_surfaces.low_reply'); ?></span>
                    </span>
                    <span class="bh-reveal-entry-votes">54 votes</span>
                </div>
            </div>
        </div>
    </div>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }

    /** @return array{css_url:string, html:string|false} */
    public static function results_preview(): array {
        ob_start();
        ?>
<div style="padding:4px;">
    <div class="bh-modal-content" style="position:relative;max-width:100%;max-height:none;overflow-y:visible;">
        <span class="bh-close">&times;</span>
        <h2><?php echo BHCON_Words::get('admin.admin_metaboxes.results'); ?></h2>
        <div class="bh-category-tabs bh-results-tabs">
            <button class="bh-cat-tab active" style="--bh-cat-color:var(--bh-text-dim)"><?php echo BHCON_Words::get('admin.style_surfaces.all'); ?></button>
            <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-1)"><?php echo BHCON_Words::get('admin.style_surfaces.pop'); ?></button>
            <button class="bh-cat-tab" style="--bh-cat-color:var(--bh-cat-2)"><?php echo BHCON_Words::get('admin.style_surfaces.rock'); ?></button>
        </div>
        <ol class="bh-results-list">
            <li class="bh-results-top">
                <span class="bh-results-rank">🥇</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song"><?php echo BHCON_Words::get('admin.style_surfaces.midnight_static'); ?></span>
                    <span class="bh-results-artist">Nova Bloom</span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-1)"><?php echo BHCON_Words::get('admin.style_surfaces.pop'); ?></span>
                <span class="bh-results-votes">128 votes</span>
            </li>
            <li class="bh-results-top">
                <span class="bh-results-rank">🥈</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song"><?php echo BHCON_Words::get('admin.style_surfaces.glass_horizon'); ?></span>
                    <span class="bh-results-artist"><?php echo BHCON_Words::get('admin.style_surfaces.echo_parade'); ?></span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-2)"><?php echo BHCON_Words::get('admin.style_surfaces.rock'); ?></span>
                <span class="bh-results-votes">96 votes</span>
            </li>
            <li>
                <span class="bh-results-rank">#3</span>
                <span class="bh-results-meta">
                    <span class="bh-results-song"><?php echo BHCON_Words::get('admin.style_surfaces.paper_satellites'); ?></span>
                    <span class="bh-results-artist"><?php echo BHCON_Words::get('admin.style_surfaces.low_reply'); ?></span>
                </span>
                <span class="bh-results-cat" style="--bh-cat-color:var(--bh-cat-1)"><?php echo BHCON_Words::get('admin.style_surfaces.pop'); ?></span>
                <span class="bh-results-votes">54 votes</span>
            </li>
        </ol>
    </div>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }
}
