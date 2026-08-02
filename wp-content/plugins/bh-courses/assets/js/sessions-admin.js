/**
 * Renders the Sessions admin screen's month-view calendar from the
 * events JSON already server-rendered into #bhc-sessions-calendar's own
 * data-events attribute — a one-time read-only render, no live server
 * round-trip needed here, so plain vanilla JS (not Datastar) is the
 * right tool per CLAUDE.md's "wp.element/Datastar/plain JS, pick based
 * on what the screen actually needs" convention.
 */
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('bhc-sessions-calendar');
    if (!el || typeof FullCalendar === 'undefined') return;

    var events = [];
    try {
        events = JSON.parse(el.getAttribute('data-events') || '[]');
    } catch (e) {
        return;
    }

    var calendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        height: 'auto',
        events: events,
    });
    calendar.render();
});
