/**
 * bh-contest-blocks.js — editor-side registration for 'bh/contest-
 * player', 'bh/results-reveal', and 'bh/archive' (class-blocks.php).
 * Plain ES5-safe JS against WP core's own globals, no build step, same
 * convention as bh-monetization-woo's bhm-blocks.js (which this file
 * mirrors closely) and own-ur-shit's element-prefab-block.js.
 *
 * All three use wp.serverSideRender — the real render_callback output
 * (the exact same static container div the front end gets) shown live
 * in the canvas. This does NOT preview voting/playback/the reveal
 * sequence/the archive grid interactively inside the editor — those are
 * entirely player.js/reveal.js/archive.js hydrating that container on a
 * REAL front-end page load, which the editor canvas never runs. What
 * this DOES fix is the exact problem AJ originally flagged: a contest
 * shortcode rendering as raw bracket text with zero visual feedback in
 * the post editor. The container now shows correctly-styled real markup
 * instead of nothing.
 */
(function (blocks, element, blockEditor, components, i18n, apiFetch, serverSideRender) {
    'use strict';
    if (!blocks || !element || !blockEditor || !serverSideRender) return;

    var el = element.createElement;
    var useState = element.useState;
    var useEffect = element.useEffect;
    var __ = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var ServerSideRender = serverSideRender.default || serverSideRender;

    // Shared by both contest-scoped blocks below — a dropdown of every
    // published contest, same picker shape bh-monetization-woo's
    // bhm/buy block already established for tracks/releases.
    function useContestPicker() {
        var state = useState([]);
        var contests = state[0];
        var setContests = state[1];
        var loadState = useState(true);
        var loading = loadState[0];
        var setLoading = loadState[1];

        useEffect(function () {
            apiFetch({ path: '/bh/v1/contests-picker' })
                .then(function (list) {
                    setContests(Array.isArray(list) ? list : []);
                    setLoading(false);
                })
                .catch(function () { setLoading(false); });
        }, []);

        return [contests, loading];
    }

    blocks.registerBlockType('bh/contest-player', {
        title: __('Contest Player (BH Contest)', 'bh-contest'),
        description: __('The contest voting player — the same [bh_contest_player] shortcode, as a real block with a live preview.', 'bh-contest'),
        icon: 'playlist-audio',
        category: 'widgets',
        attributes: { contest: { type: 'string', default: '' } },

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var picker = useContestPicker();
            var contests = picker[0];
            var loading = picker[1];

            var options = [{ label: __('— Most recently published contest —', 'bh-contest'), value: '' }].concat(
                contests.map(function (c) { return { label: c.title, value: c.slug }; })
            );

            return el('div', {},
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Contest', 'bh-contest') },
                        el(SelectControl, {
                            label: __('Which contest (blank = most recent)', 'bh-contest'),
                            value: attributes.contest,
                            options: options,
                            disabled: loading,
                            onChange: function (val) { setAttributes({ contest: val }); },
                        })
                    )
                ),
                el(ServerSideRender, { block: 'bh/contest-player', attributes: attributes })
            );
        },

        save: function () { return null; },
    });

    blocks.registerBlockType('bh/results-reveal', {
        title: __('Results Reveal Display (BH Contest)', 'bh-contest'),
        description: __('The public Results Reveal display (what OBS captures) — the same [bh_results_reveal] shortcode, as a real block with a live preview.', 'bh-contest'),
        icon: 'megaphone',
        category: 'widgets',
        attributes: { contest: { type: 'string', default: '' } },

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var picker = useContestPicker();
            var contests = picker[0];
            var loading = picker[1];

            var options = [{ label: __('— Most recently published contest —', 'bh-contest'), value: '' }].concat(
                contests.map(function (c) { return { label: c.title, value: c.slug }; })
            );

            return el('div', {},
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Contest', 'bh-contest') },
                        el(SelectControl, {
                            label: __('Which contest (blank = most recent)', 'bh-contest'),
                            value: attributes.contest,
                            options: options,
                            disabled: loading,
                            onChange: function (val) { setAttributes({ contest: val }); },
                        })
                    )
                ),
                el(ServerSideRender, { block: 'bh/results-reveal', attributes: attributes })
            );
        },

        save: function () { return null; },
    });

    blocks.registerBlockType('bh/archive', {
        title: __('Archive (BH Contest)', 'bh-contest'),
        description: __('The public past-contests archive browser — the same [bh_archive] shortcode, as a real block with a live preview.', 'bh-contest'),
        icon: 'archive',
        category: 'widgets',

        // No attributes — the archive is always every past contest,
        // site-wide, same as the shortcode itself takes no atts.
        edit: function () {
            return el(ServerSideRender, { block: 'bh/archive' });
        },

        save: function () { return null; },
    });
})(
    window.wp && window.wp.blocks,
    window.wp && window.wp.element,
    window.wp && window.wp.blockEditor,
    window.wp && window.wp.components,
    window.wp && window.wp.i18n,
    window.wp && window.wp.apiFetch,
    window.wp && window.wp.serverSideRender
);
