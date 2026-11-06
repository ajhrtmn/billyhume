"use strict";
/**
 * Renders the Sessions admin month-view calendar from #bhc-sessions-calendar's data-events
 * JSON. One-time read-only render, so plain JS. FullCalendar is an untyped vendored global.
 */
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('bhc-sessions-calendar');
    if (!el || typeof FullCalendar === 'undefined')
        return;
    let events = [];
    try {
        events = JSON.parse(el.getAttribute('data-events') || '[]');
    }
    catch (e) {
        return;
    }
    const calendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        height: 'auto',
        events: events,
    });
    calendar.render();
});
