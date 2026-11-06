"use strict";
/**
 * Editor registration for 'bhc/catalog' and 'bhc/course' (class-blocks.php). Both are fully
 * server-rendered, so wp.serverSideRender shows the real final HTML.
 */
(function (blocks, element, blockEditor, components, i18n, apiFetch, serverSideRender) {
    'use strict';
    if (!blocks || !element || !blockEditor || !serverSideRender)
        return;
    const el = element.createElement;
    const useState = element.useState;
    const useEffect = element.useEffect;
    const __ = i18n.__;
    const InspectorControls = blockEditor.InspectorControls;
    const PanelBody = components.PanelBody;
    const SelectControl = components.SelectControl;
    const Placeholder = components.Placeholder;
    const ServerSideRender = serverSideRender.default || serverSideRender;
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
    blocks.registerBlockType('bhc/course', {
        apiVersion: 3,
        title: __('Single Course (BH Courses)', 'bh-courses'),
        description: __('A single course’s detail page — the same [bh_course] shortcode, as a real block with a live preview.', 'bh-courses'),
        icon: 'welcome-learn-more',
        category: 'widgets',
        attributes: { id: { type: 'number', default: 0 } },
        edit: function (props) {
            const attributes = props.attributes;
            const setAttributes = props.setAttributes;
            const [courses, setCourses] = useState([]);
            const [loading, setLoading] = useState(true);
            useEffect(() => {
                apiFetch({ path: '/bhc/v1/courses-picker' })
                    .then((list) => {
                    setCourses(Array.isArray(list) ? list : []);
                    setLoading(false);
                })
                    .catch(() => { setLoading(false); });
            }, []);
            const options = [{ label: __('— Select a course —', 'bh-courses'), value: 0 }].concat(courses.map((c) => ({ label: c.title, value: c.id })));
            return el('div', {}, el(InspectorControls, {}, el(PanelBody, { title: __('Course', 'bh-courses') }, el(SelectControl, {
                label: __('Which course', 'bh-courses'),
                value: attributes.id,
                options: options,
                disabled: loading,
                onChange: function (val) { setAttributes({ id: parseInt(val, 10) || 0 }); },
            }))), attributes.id
                ? el(ServerSideRender, { block: 'bhc/course', attributes: attributes })
                : el(Placeholder, {
                    icon: 'welcome-learn-more',
                    label: __('Single Course', 'bh-courses'),
                    instructions: __('Choose a course in the block sidebar (Inspector panel) to preview it here.', 'bh-courses'),
                }));
        },
        save: function () { return null; },
    });
})(window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.blockEditor, window.wp && window.wp.components, window.wp && window.wp.i18n, window.wp && window.wp.apiFetch, window.wp && window.wp.serverSideRender);
