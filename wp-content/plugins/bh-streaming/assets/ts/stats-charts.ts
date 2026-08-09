/**
 * Real D3 charts for the Metrics dashboard (class-stats.php), replacing
 * the previous hand-rolled inline-CSS div bar chart and plain HTML
 * tables for the time-series/categorical breakdowns. Top Tracks and
 * Most Skipped stay plain tables — title + count lists, not naturally
 * chart-shaped data. D3 is vendored plugin-local (assets/js/vendor/
 * d3.min.js, v7.9.0) since this is the first real consumer in the
 * ecosystem; class-stats.php passes the exact same server-aggregated
 * rows this file used to loop over as `echo` calls, just JSON-encoded
 * into each chart container's data attribute instead.
 *
 * TypeScript pilot conversion (bh-streaming's first), same posture as
 * own-ur-shit/assets/ts/*.ts: plain `tsc`, compiled to assets/js/
 * stats-charts.js, which is what's actually enqueued. Run
 * `npm run build:bh-streaming` after editing. `d3` is declared as a
 * loose `any` global below — a real @types/d3 package would be a much
 * heavier/precise dependency than this pilot's "catch typos in our own
 * code" goal calls for.
 */

declare const d3: any;

interface BHSTimeSeriesRow {
    date: string;
    value: number;
}

(function () {
    'use strict';
    if (typeof d3 === 'undefined') return;

    document.addEventListener('DOMContentLoaded', function () {
        renderTimeSeries('bhs-chart-plays-per-day');
        renderBarChart('bhs-chart-region', 'country', 'plays');
        renderBarChart('bhs-chart-referrer', 'label', 'plays');
    });

    function readData(el: Element): any[] {
        try {
            return JSON.parse(el.getAttribute('data-chart') || '[]');
        } catch (e) {
            return [];
        }
    }

    function renderTimeSeries(id: string): void {
        var el = document.getElementById(id);
        if (!el) return;
        var data = readData(el).map(function (d: BHSTimeSeriesRow) { return { date: new Date(d.date), value: +d.value }; });
        if (!data.length) return;

        var width = el.clientWidth || 640, height = 160, margin = { top: 10, right: 10, bottom: 24, left: 32 };
        var svg = d3.select(el).append('svg').attr('width', width).attr('height', height).attr('viewBox', '0 0 ' + width + ' ' + height);

        var x = d3.scaleUtc().domain(d3.extent(data, function (d: any) { return d.date; })).range([margin.left, width - margin.right]);
        var y = d3.scaleLinear().domain([0, d3.max(data, function (d: any) { return d.value; }) || 1]).nice().range([height - margin.bottom, margin.top]);

        var area = d3.area()
            .x(function (d: any) { return x(d.date); })
            .y0(y(0))
            .y1(function (d: any) { return y(d.value); });

        svg.append('path').datum(data).attr('fill', '#C1503A').attr('fill-opacity', 0.25).attr('d', area);

        var line = d3.line().x(function (d: any) { return x(d.date); }).y(function (d: any) { return y(d.value); });
        svg.append('path').datum(data).attr('fill', 'none').attr('stroke', '#C1503A').attr('stroke-width', 2).attr('d', line);

        svg.append('g').attr('transform', 'translate(0,' + (height - margin.bottom) + ')')
            .call(d3.axisBottom(x).ticks(Math.min(data.length, 8)).tickSizeOuter(0));
        svg.append('g').attr('transform', 'translate(' + margin.left + ',0)')
            .call(d3.axisLeft(y).ticks(4).tickSizeOuter(0));

        svg.selectAll('circle').data(data).enter().append('circle')
            .attr('cx', function (d: any) { return x(d.date); })
            .attr('cy', function (d: any) { return y(d.value); })
            .attr('r', 3)
            .attr('fill', '#C1503A')
            .append('title')
            .text(function (d: any) { return d.date.toISOString().slice(0, 10) + ': ' + d.value + ' plays'; });
    }

    function renderBarChart(id: string, labelKey: string, valueKey: string): void {
        var el = document.getElementById(id);
        if (!el) return;
        var data = readData(el);
        if (!data.length) return;

        var width = el.clientWidth || 640;
        var barHeight = 22, height = data.length * barHeight + 10;
        var margin = { top: 5, right: 40, bottom: 5, left: 140 };
        var svg = d3.select(el).append('svg').attr('width', width).attr('height', height).attr('viewBox', '0 0 ' + width + ' ' + height);

        var x = d3.scaleLinear().domain([0, d3.max(data, function (d: any) { return +d[valueKey]; }) || 1]).range([margin.left, width - margin.right]);
        var y = d3.scaleBand().domain(data.map(function (d: any) { return d[labelKey]; })).range([margin.top, height - margin.bottom]).padding(0.2);

        svg.selectAll('rect').data(data).enter().append('rect')
            .attr('x', margin.left)
            .attr('y', function (d: any) { return y(d[labelKey]); })
            .attr('width', function (d: any) { return x(+d[valueKey]) - margin.left; })
            .attr('height', y.bandwidth())
            .attr('fill', '#C1503A');

        svg.selectAll('.bhs-bar-label').data(data).enter().append('text')
            .attr('x', margin.left - 6)
            .attr('y', function (d: any) { return y(d[labelKey]) + y.bandwidth() / 2; })
            .attr('dy', '0.35em')
            .attr('text-anchor', 'end')
            .attr('font-size', '12px')
            .text(function (d: any) { return d[labelKey]; });

        svg.selectAll('.bhs-bar-value').data(data).enter().append('text')
            .attr('x', function (d: any) { return x(+d[valueKey]) + 4; })
            .attr('y', function (d: any) { return y(d[labelKey]) + y.bandwidth() / 2; })
            .attr('dy', '0.35em')
            .attr('font-size', '12px')
            .text(function (d: any) { return d[valueKey]; });
    }
})();
