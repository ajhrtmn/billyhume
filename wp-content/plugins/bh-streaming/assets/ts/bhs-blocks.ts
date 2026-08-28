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
 *
 * TypeScript pilot conversion — same posture as this plugin's own
 * stats-charts.ts, and the same local-ambient-types pattern
 * bh-contest-blocks.ts already established for this exact
 * registerBlockType/serverSideRender IIFE shape.
 */

type BHSElementType = unknown;
type BHSNode = unknown;

interface BHSElementApi {
    createElement(type: BHSElementType, props?: Record<string, unknown> | null, ...children: BHSNode[]): BHSNode;
}

interface BHSBlocksApi {
    registerBlockType(name: string, settings: Record<string, unknown>): void;
}

interface BHSWpGlobal {
    blocks?: BHSBlocksApi;
    element?: BHSElementApi;
    serverSideRender?: unknown;
}

interface BHSBlocksWindow extends Window {
    wp?: BHSWpGlobal;
}

(function (blocks: BHSBlocksApi | undefined, element: BHSElementApi | undefined, serverSideRender: unknown) {
    'use strict';
    if (!blocks || !element || !serverSideRender) return;

    const el = element.createElement;
    const ServerSideRender = (serverSideRender as { default?: unknown }).default || serverSideRender;

    blocks.registerBlockType('bhs/player', {
        apiVersion: 3,
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
    (window as BHSBlocksWindow).wp && (window as BHSBlocksWindow).wp!.blocks,
    (window as BHSBlocksWindow).wp && (window as BHSBlocksWindow).wp!.element,
    (window as BHSBlocksWindow).wp && (window as BHSBlocksWindow).wp!.serverSideRender
);
