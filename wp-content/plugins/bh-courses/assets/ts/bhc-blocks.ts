/**
 * Editor registration for 'bhc/catalog', 'bhc/course' and 'bhc/course-landing' (class-blocks.php). Both are fully
 * server-rendered, so wp.serverSideRender shows the real final HTML.
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

        // No attributes: always the full catalog.
        edit: function () {
            return el(ServerSideRender, { block: 'bhc/catalog' });
        },

        save: function () { return null; },
    });

    // Both course-picking blocks share one editor: a course dropdown plus a live preview.
    function registerCourseBlock(name: string, title: string, description: string, placeholderLabel: string) {
        blocks!.registerBlockType(name, {
            apiVersion: 3,
            title: title,
            description: description,
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
                        ? el(ServerSideRender, { block: name, attributes: attributes })
                        : el(Placeholder, {
                            icon: 'welcome-learn-more',
                            label: placeholderLabel,
                            instructions: __('Choose a course in the block sidebar (Inspector panel) to preview it here.', 'bh-courses'),
                        })
                );
            },

            save: function () { return null; },
        });
    }

    registerCourseBlock('bhc/course', __('Single Course (BH Courses)', 'bh-courses'),
        __('A single course’s detail page — the same [bh_course] shortcode, as a real block with a live preview.', 'bh-courses'),
        __('Single Course', 'bh-courses'));
    registerCourseBlock('bhc/course-landing', __('Course Sales Page (BH Courses)', 'bh-courses'),
        __('A course’s sales page: hero, what’s inside, testimonials, FAQ and the buy button. Fill it in under the course’s Sales page fields.', 'bh-courses'),
        __('Course Sales Page', 'bh-courses'));

})(
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.blocks,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.element,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.blockEditor,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.components,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.i18n,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.apiFetch,
    (window as BHCBlocksWindow).wp && (window as BHCBlocksWindow).wp!.serverSideRender
);
