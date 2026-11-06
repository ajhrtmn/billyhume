"use strict";
/**
 * Editor registration for the bh-contest blocks (class-blocks.php), previewed via
 * wp.serverSideRender. The editor shows the static container only; front-end JS
 * hydrates it. wp.* globals are typed loosely and locally (separate tsc project).
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
    const ServerSideRender = serverSideRender.default || serverSideRender;
    // Published-contest picker shared by the contest-scoped blocks.
    function useContestPicker() {
        const [contests, setContests] = useState([]);
        const [loading, setLoading] = useState(true);
        useEffect(() => {
            apiFetch({ path: '/bh/v1/contests-picker' })
                .then((list) => {
                setContests(Array.isArray(list) ? list : []);
                setLoading(false);
            })
                .catch(() => { setLoading(false); });
        }, []);
        return [contests, loading];
    }
    blocks.registerBlockType('bh/contest-player', {
        apiVersion: 3,
        title: __('Contest Player (BH Contest)', 'bh-contest'),
        description: __('The contest voting player — the same [bh_contest_player] shortcode, as a real block with a live preview.', 'bh-contest'),
        icon: 'playlist-audio',
        category: 'widgets',
        attributes: { contest: { type: 'string', default: '' } },
        edit: function (props) {
            const attributes = props.attributes;
            const setAttributes = props.setAttributes;
            const [contests, loading] = useContestPicker();
            const options = [{ label: __('— Most recently published contest —', 'bh-contest'), value: '' }].concat(contests.map((c) => ({ label: c.title, value: c.slug })));
            return el('div', {}, el(InspectorControls, {}, el(PanelBody, { title: __('Contest', 'bh-contest') }, el(SelectControl, {
                label: __('Which contest (blank = most recent)', 'bh-contest'),
                value: attributes.contest,
                options: options,
                disabled: loading,
                onChange: function (val) { setAttributes({ contest: val }); },
            }))), el(ServerSideRender, { block: 'bh/contest-player', attributes: attributes }));
        },
        save: function () { return null; },
    });
    blocks.registerBlockType('bh/results-reveal', {
        apiVersion: 3,
        title: __('Results Reveal Display (BH Contest)', 'bh-contest'),
        description: __('The public Results Reveal display (what OBS captures) — the same [bh_results_reveal] shortcode, as a real block with a live preview.', 'bh-contest'),
        icon: 'megaphone',
        category: 'widgets',
        attributes: { contest: { type: 'string', default: '' } },
        edit: function (props) {
            const attributes = props.attributes;
            const setAttributes = props.setAttributes;
            const [contests, loading] = useContestPicker();
            const options = [{ label: __('— Most recently published contest —', 'bh-contest'), value: '' }].concat(contests.map((c) => ({ label: c.title, value: c.slug })));
            return el('div', {}, el(InspectorControls, {}, el(PanelBody, { title: __('Contest', 'bh-contest') }, el(SelectControl, {
                label: __('Which contest (blank = most recent)', 'bh-contest'),
                value: attributes.contest,
                options: options,
                disabled: loading,
                onChange: function (val) { setAttributes({ contest: val }); },
            }))), el(ServerSideRender, { block: 'bh/results-reveal', attributes: attributes }));
        },
        save: function () { return null; },
    });
    blocks.registerBlockType('bh/archive', {
        apiVersion: 3,
        title: __('Archive (BH Contest)', 'bh-contest'),
        description: __('The public past-contests archive browser — the same [bh_archive] shortcode, as a real block with a live preview.', 'bh-contest'),
        icon: 'archive',
        category: 'widgets',
        // No attributes: the archive always spans every contest.
        edit: function () {
            return el(ServerSideRender, { block: 'bh/archive' });
        },
        save: function () { return null; },
    });
})(window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.blockEditor, window.wp && window.wp.components, window.wp && window.wp.i18n, window.wp && window.wp.apiFetch, window.wp && window.wp.serverSideRender);
