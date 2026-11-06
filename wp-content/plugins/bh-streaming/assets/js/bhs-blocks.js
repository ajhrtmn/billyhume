"use strict";
/**
 * Editor registration for 'bhs/player' (class-blocks.php). wp.serverSideRender
 * shows the static mount div, not the interactive app. Edit this .ts and
 * recompile; assets/js/bhs-blocks.js is generated.
 */
(function (blocks, element, serverSideRender) {
    'use strict';
    if (!blocks || !element || !serverSideRender)
        return;
    const el = element.createElement;
    const ServerSideRender = serverSideRender.default || serverSideRender;
    blocks.registerBlockType('bhs/player', {
        apiVersion: 3,
        title: 'Streaming Player (BH Streaming)',
        description: 'The streaming library/player app — the same [bh_streaming] shortcode, as a real block with a live preview.',
        icon: 'format-audio',
        category: 'widgets',
        // No attributes, like the shortcode.
        edit: function () {
            return el(ServerSideRender, { block: 'bhs/player' });
        },
        save: function () { return null; },
    });
})(window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.serverSideRender);
