/**
 * Editor registration for 'bhs/player' (class-blocks.php). wp.serverSideRender
 * shows the static mount div, not the interactive app. Edit this .ts and
 * recompile; assets/js/bhs-blocks.js is generated.
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

        // No attributes, like the shortcode.
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
