/**
 * bhs-blocks.js — editor-side registration for 'bhs/player'
 * (class-blocks.php). Plain ES5-safe JS against WP core's own globals,
 * no build step, same convention as bh-contest's bh-contest-blocks.js
 * and bh-monetization-woo's bhm-blocks.js.
 *
 * wp.serverSideRender shows the real render_callback output (the exact
 * same static mount div the front end gets) live in the canvas — not
 * an interactive preview of the actual streaming app, which is entirely
 * player.js hydrating that div on a real front-end page load. Same
 * honest scoping already established for bh-contest's blocks.
 */
(function (blocks, element, serverSideRender) {
    'use strict';
    if (!blocks || !element || !serverSideRender) return;

    var el = element.createElement;
    var ServerSideRender = serverSideRender.default || serverSideRender;

    blocks.registerBlockType('bhs/player', {
        title: 'Streaming Player (BH Streaming)',
        description: 'The streaming library/player app — the same [bh_streaming] shortcode, as a real block with a live preview.',
        icon: 'format-audio',
        category: 'widgets',

        // No attributes — always the one app-wide player, same as the
        // shortcode itself takes no atts.
        edit: function () {
            return el(ServerSideRender, { block: 'bhs/player' });
        },

        save: function () { return null; },
    });
})(
    window.wp && window.wp.blocks,
    window.wp && window.wp.element,
    window.wp && window.wp.serverSideRender
);
