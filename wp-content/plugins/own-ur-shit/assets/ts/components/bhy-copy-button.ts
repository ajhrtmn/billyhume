import { LitElement, html, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';

/**
 * <bhy-copy-button value="…">Copy</bhy-copy-button>
 *
 * Progressive enhancement, deliberately: the server sends a real, styled
 * <button> inside this element. If this module never loads, that button is
 * still there and still styled — it just does not copy. Nothing renders
 * "blank until hydrated".
 *
 * Light DOM on purpose. Shadow DOM would encapsulate styles and cut the
 * button off from the design tokens and admin-skin.css, which is exactly the
 * thing that would make Lit a bad fit for this design system.
 * See CONVENTIONS.md, "The rendering layers".
 *
 * NOTE: no `star-slash` sequence in this comment, deliberately — writing the
 * token prefixes as a glob pair closed the block early and broke the parse.
 * Same failure shape as an unescaped apostrophe inside a single-quoted PHP
 * string, which took this codebase down once already.
 */
@customElement('bhy-copy-button')
export class BhyCopyButton extends LitElement {
    /** Text to place on the clipboard. */
    @property({ type: String }) value = '';

    /** Label shown briefly after a successful copy. */
    @property({ type: String, attribute: 'copied-label' }) copiedLabel = 'Copied';

    @state() private justCopied = false;

    private timer?: number;

    // WHY: render into light DOM so the ecosystem's tokens and stylesheets
    // still apply. Returning `this` is the documented Lit escape hatch.
    protected createRenderRoot(): HTMLElement {
        return this;
    }

    private async copy(): Promise<void> {
        try {
            await navigator.clipboard.writeText(this.value);
        } catch {
            // Clipboard can be blocked by permissions or a non-secure context.
            // Fail quietly: the value is already visible on the page, so the
            // user can select it by hand. Better than an alert() they cannot act on.
            return;
        }
        this.justCopied = true;
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => { this.justCopied = false; }, 1600);
    }

    disconnectedCallback(): void {
        window.clearTimeout(this.timer);
        super.disconnectedCallback();
    }

    protected render(): TemplateResult {
        // Reuses the existing .bhy-copy-btn class rather than inventing a look.
        return html`
            <button type="button" class="bhy-copy-btn" @click=${this.copy}
                    aria-live="polite">
                ${this.justCopied ? this.copiedLabel : (this.textContent?.trim() || 'Copy')}
            </button>
        `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'bhy-copy-button': BhyCopyButton;
    }
}
