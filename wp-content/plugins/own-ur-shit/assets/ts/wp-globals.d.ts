/**
 * Shared, hand-written ambient types for the subset of WordPress's
 * window.wp.* global that this ecosystem's TypeScript-pilot editor files
 * actually call. Deliberately NOT a full @wordpress/* type package (none
 * is installed — see search.ts's docblock for why this pilot stays on
 * plain `tsc` with no npm dependency beyond typescript itself). Widen an
 * interface here when a new file's conversion needs a wp.* member that
 * isn't covered yet; don't reach for @types/wordpress__* by default.
 *
 * Component props below are typed loosely (Record<string, unknown> /
 * unknown) rather than modeling each Gutenberg component's real prop
 * shape — the goal is catching typos in OUR code (wrong wp.* member
 * name, wrong argument count, wrong local variable type), not fully
 * type-checking core's own React tree.
 *
 * This file has no imports/exports, matching every other file in this
 * pilot (module: "none" in tsconfig.json) — its declarations are ambient
 * and global by TypeScript's own rule for a script-mode .d.ts.
 */

type WpComponentType = (props?: Record<string, unknown>) => unknown;

interface WpElementApi {
    createElement(type: unknown, props?: Record<string, unknown> | null, ...children: unknown[]): unknown;
    Fragment: unknown;
    useState<T>(initial: T): [T, (next: T | ((prev: T) => T)) => void];
    useEffect(effect: () => void | (() => void), deps?: unknown[]): void;
    render(element: unknown, container: Element | null): void;
}

interface WpUseBlockProps {
    (props?: Record<string, unknown>): Record<string, unknown>;
    save(props?: Record<string, unknown>): Record<string, unknown>;
}

interface WpUseInnerBlocksProps {
    (blockProps: Record<string, unknown>, opts?: Record<string, unknown>): Record<string, unknown>;
    save(blockProps: Record<string, unknown>): Record<string, unknown>;
}

interface WpRichTextComponent extends Record<string, unknown> {
    Content: WpComponentType;
}

interface WpBlockEditorApi {
    InspectorControls: WpComponentType;
    BlockControls: WpComponentType;
    useBlockProps: WpUseBlockProps;
    useInnerBlocksProps: WpUseInnerBlocksProps;
    InnerBlocks: { ButtonBlockAppender: unknown };
    RichText: WpRichTextComponent;
    MediaPlaceholder: WpComponentType;
    BlockTools: WpComponentType;
    WritingFlow: WpComponentType;
    ObserveTyping: WpComponentType;
    BlockList: WpComponentType;
    BlockInspector: WpComponentType;
    BlockEditorProvider: WpComponentType;
    Inserter: WpComponentType;
    ListView?: WpComponentType;
    __experimentalListView?: WpComponentType;
    [key: string]: unknown;
}

interface WpComponentsApi {
    PanelBody: WpComponentType;
    SelectControl: WpComponentType;
    TextControl: WpComponentType;
    Placeholder: WpComponentType;
    Spinner: WpComponentType;
    Button: WpComponentType;
    ToolbarGroup: WpComponentType;
    ToolbarButton: WpComponentType;
    Popover: { Slot: WpComponentType };
    [key: string]: unknown;
}

interface WpI18nApi {
    __(text: string, domain?: string): string;
}

interface WpComposeApi {
    createHigherOrderComponent(
        create: (Inner: unknown) => (props: Record<string, unknown>) => unknown,
        name: string
    ): unknown;
}

interface WpHooksApi {
    addFilter(
        hookName: string,
        namespace: string,
        callback: (...args: unknown[]) => unknown,
        priority?: number
    ): void;
}

interface WpApiFetchOptions {
    path: string;
    method?: string;
    data?: unknown;
}

type WpApiFetch = (options: WpApiFetchOptions) => Promise<unknown>;

interface WpBlockInstance {
    name: string;
    attributes: Record<string, unknown>;
    innerBlocks: WpBlockInstance[];
}

interface WpBlocksApi {
    registerBlockType(name: string, settings: Record<string, unknown>): void;
    createBlock(name: string, attributes?: Record<string, unknown>, innerBlocks?: unknown[]): WpBlockInstance;
}

interface WpGlobal {
    element?: WpElementApi;
    blocks?: WpBlocksApi;
    blockEditor?: WpBlockEditorApi;
    components?: WpComponentsApi;
    compose?: WpComposeApi;
    hooks?: WpHooksApi;
    i18n?: WpI18nApi;
    apiFetch?: WpApiFetch;
}

interface Window {
    wp?: WpGlobal;
}
