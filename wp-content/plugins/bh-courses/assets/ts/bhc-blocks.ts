/**
 * bhc-blocks.js — editor-side registration for 'bhc/catalog' and
 * 'bhc/course' (class-blocks.php). Plain ES5-safe JS against WP core's
 * own globals, no build step, same convention as this ecosystem's other
 * WYSIWYG block conversions (bh-monetization-woo's bhm-blocks.js,
 * bh-contest's bh-contest-blocks.js, bh-streaming's bhs-blocks.js).
 *
 * wp.serverSideRender shows the REAL final rendered HTML here (both
 * blocks are fully server-rendered, unlike bh-contest's/bh-streaming's
 * JS-hydrated mount divs) — this is the actual catalog grid / course
 * page a real visitor sees, live in the editor canvas.
 *
 * TypeScript pilot conversion — same local-ambient-types pattern
 * bh-contest-blocks.ts already established for this exact
 * registerBlockType/serverSideRender IIFE shape.
 */

type BHCElementType = unknown;
type BHCNode = unknown;

interface BHCElementApi {
    createElement(type: BHCElementType, props?: Record<string, unknown> | null, ...children: BHCNode[]): BHCNode;
    useState<T>(initial: T): [T, (next: T) => void];
    useEffect(effect: () => void | (() => void), deps?: unknown[]): void;
}

interface BHCBlocksApi {
    registerBlockType(name: string, settings: Record<string, unknown>): void;
}

interface BHCBlockEditorApi {
    InspectorControls: BHCElementType;
}

interface BHCComponentsApi {
    PanelBody: BHCElementType;
    SelectControl: BHCElementType;
    Placeholder: BHCElementType;
}

interface BHCI18nApi {
    __(text: string, domain?: string): string;
}

type BHCApiFetch = (options: { path: string }) => Promise<unknown>;

interface BHCCourseOption {
    title: string;
    id: number;
}

interface BHCWpGlobal {
    blocks?: BHCBlocksApi;
    element?: BHCElementApi;
    blockEditor?: BHCBlockEditorApi;
    components?: BHCComponentsApi;
    i18n?: BHCI18nApi;
    apiFetch?: BHCApiFetch;
    serverSideRender?: unknown;
}

interface BHCBlocksWindow extends Window {
    wp?: BHCWpGlobal;
}

(function (
    blocks: BHCBlocksApi | undefined,
    element: BHCElementApi | undefined,
    blockEditor: BHCBlockEditorApi | undefined,
    components: BHCComponentsApi | undefined,
    i18n: BHCI18nApi | undefined,
    apiFetch: BHCApiFetch | undefined,
    serverSideRender: unknown
) {
    'use strict';
    if (!blocks || !element || !blockEditor || !serverSideRender) return;

    const el = element.createElement;
    const useState = element.useState;
    const useEffect = element.useEffect;
    const __ = i18n!.__;
    const InspectorControls = blockEditor.InspectorControls;
    const PanelBody = components!.PanelBody;
    const SelectControl = components!.SelectControl;
    const Placeholder = components!.Placeholder;
    const ServerSideRender = (serverSideRender as { default?: unknown }).default || serverSideRender;

    blocks.registerBlockType('bhc/catalog', {
        apiVersion: 3,
        title: __('Course Catalog (BH Courses)', 'bh-courses'),
        description: __('The full course catalog grid — the same [bh_courses] shortcode, as a real block with a live preview.', 'bh-courses'),
        icon: 'welcome-learn-more',
        category: 'widgets',

        // No attributes — always the full catalog, same as the
        // shortcode itself takes no atts.
        edit: function () {
            return el(ServerSideRender, { block: 'bhc/catalog' });
        },

        save: function () { return null; },
    });

    blocks.registerBlockType('bhc/course', {
        apiVersion: 3,
        title: __('Single Course (BH Courses)', 'bh-courses'),
        description: __('A single course’s detail page — the same [bh_course] shortcode, as a real block with a live preview.', 'bh-courses'),
        icon: 'welcome-learn-more',
        category: 'widgets',
        attributes: { id: { type: 'number', default: 0 } },

        edit: function (props: { attributes: { id: number }; setAttributes: (attrs: Partial<{ id: number }>) => void }) {
            const attributes = props.attributes;
            const setAttributes = props.setAttributes;
            const [courses, setCourses] = useState<BHCCourseOption[]>([]);
            const [loading, setLoading] = useState(true);

            useEffect(() => {
                apiFetch!({ path: '/bhc/v1/courses-picker' })
                    .then((list) => {
                        setCourses(Array.isArray(list) ? (list as BHCCourseOption[]) : []);
                        setLoading(false);
                    })
                    .catch(() => { setLoading(false); });
            }, []);

            const options = [{ label: __('— Select a course —', 'bh-courses'), value: 0 }].concat(
                courses.map((c) => ({ label: c.title, value: c.id }))
            );

            return el('div', {},
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Course', 'bh-courses') },
                        el(SelectControl, {
                            label: __('Which course', 'bh-courses'),
                            value: attributes.id,
                            options: options,
                            disabled: loading,
                            onChange: function (val: string) { setAttributes({ id: parseInt(val, 10) || 0 }); },
                        })
                    )
                ),
                attributes.id
                    ? el(ServerSideRender, { block: 'bhc/course', attributes: attributes })
                    : el(Placeholder, {
                        icon: 'welcome-learn-more',
                        label: __('Single Course', 'bh-courses'),
                        instructions: __('Choose a course in the block sidebar (Inspector panel) to preview it here.', 'bh-courses'),
                    })
            );
        },

        save: function () { return null; },
    });
})(
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.blocks,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.element,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.blockEditor,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.components,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.i18n,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.apiFetch,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.serverSideRender
);
