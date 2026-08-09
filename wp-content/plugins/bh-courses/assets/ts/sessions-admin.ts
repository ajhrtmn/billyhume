/**
 * Renders the Sessions admin screen's month-view calendar from the
 * events JSON already server-rendered into #bhc-sessions-calendar's own
 * data-events attribute — a one-time read-only render, no live server
 * round-trip needed here, so plain vanilla JS (not Datastar) is the
 * right tool per CLAUDE.md's "wp.element/Datastar/plain JS, pick based
 * on what the screen actually needs" convention.
 *
 * TypeScript pilot conversion. FullCalendar (vendored assets/js/vendor/
 * fullcalendar.global.js) is a loosely-typed external global — not
 * worth a real @types/... package for this ecosystem's "catch typos in
 * our own code" goal.
 */

interface FullCalendarInstance {
    render(): void;
}

interface FullCalendarApi {
    Calendar: new (el: HTMLElement, options: Record<string, unknown>) => FullCalendarInstance;
}

declare const FullCalendar: FullCalendarApi | undefined;

document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('bhc-sessions-calendar');
    if (!el || typeof FullCalendar === 'undefined') return;

    let events: unknown[] = [];
    try {
        events = JSON.parse(el.getAttribute('data-events') || '[]');
    } catch (e) {
        return;
    }

    const calendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        height: 'auto',
        events: events,
    });
    calendar.render();
});
