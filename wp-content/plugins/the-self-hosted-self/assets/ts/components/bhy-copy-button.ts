import { LitElement, html, type TemplateResult } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';

/**
 * <bhy-copy-button value="…">Copy</bhy-copy-button>: progressive enhancement of a
 * server-rendered button. Light DOM so the design tokens still reach it.
 * NOTE: never write a star followed by a slash inside this comment; it ends the block.
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
