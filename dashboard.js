'use strict';

(() => {
    const roles = [...document.querySelectorAll('.role-select')];
    const points = document.getElementById('chart-points');
    const period = document.getElementById('chart-period');
    const startInput = document.getElementById('chart-start');
    const endInput = document.getElementById('chart-end');
    const message = document.getElementById('chart-message');
    const charts = document.getElementById('charts');
    const colors = ['#087f8c', '#c14953', '#7156a5', '#b36b00', '#2865a3', '#47813c'];
    let requestNumber = 0;

    function utcTime(value) {
        return value ? Date.parse(value.replace(' ', 'T') + 'Z') : NaN;
    }

    for (const select of roles) {
        const key = 'nibe-role-' + select.dataset.role;
        try {
            const saved = localStorage.getItem(key);
            if (saved && [...select.options].some(option => option.value === saved)) select.value = saved;
        } catch (_) {
            // The dashboard still works when browser storage is unavailable.
        }
        function updateRole() {
            const option = select.selectedOptions[0];
            const container = select.closest('.role');
            const value = container.querySelector('.role-value');
            const time = container.querySelector('.role-time');
            if (!select.value) {
                value.textContent = 'Niet ingesteld';
                time.textContent = '';
                return;
            }
            value.textContent = option.dataset.hasValue === '1'
                ? option.dataset.value + (option.dataset.unit ? ' ' + option.dataset.unit : '')
                : 'Geen meting';
            const source = option.dataset.sourceTime;
            const collected = option.dataset.collectedAt;
            const observed = utcTime(source || collected);
            const freshness = !Number.isFinite(observed) ? 'Geen meting beschikbaar'
                : Date.now() - observed > 86400000 ? 'Verouderd (>24 uur)' : 'Binnen 24 uur';
            time.textContent = 'Bron tijd (UTC): ' + (source || 'Niet aangeleverd')
                + ' · Verzameld (UTC): ' + (collected || 'Niet beschikbaar') + ' · ' + freshness;
        }
        select.addEventListener('change', () => {
            try { localStorage.setItem(key, select.value); } catch (_) {}
            updateRole();
        });
        updateRole();
    }

    document.getElementById('chart-use-roles').addEventListener('click', () => {
        const chosen = [...new Set(roles.map(role => role.value).filter(Boolean))].slice(0, 8);
        for (const option of points.options) option.selected = chosen.includes(option.value);
        message.textContent = chosen.length ? 'Meetpunten geselecteerd. Kies een periode en toon de grafiek.' : 'Stel eerst metingen in.';
    });
    period.addEventListener('change', () => {
        startInput.disabled = endInput.disabled = period.value !== 'custom';
    });

    const svgNS = 'http://www.w3.org/2000/svg';
    function element(name, attributes = {}, text = null) {
        const node = document.createElementNS(svgNS, name);
        for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
        if (text !== null) node.textContent = text;
        return node;
    }

    function drawGroup(unit, series, start, end) {
        const section = document.createElement('section');
        const heading = document.createElement('h3');
        heading.textContent = unit ? 'Eenheid: ' + unit : 'Zonder eenheid';
        section.append(heading);
        const legend = document.createElement('ul');
        legend.className = 'legend';
        const detail = document.createElement('p');
        detail.className = 'muted';
        detail.textContent = 'Wijs een punt aan of selecteer het met Tab voor details.';
        const svg = element('svg', {viewBox: '0 0 1000 340', class: 'chart', role: 'img', 'aria-label': 'Waarnemingen, ' + (unit || 'zonder eenheid')});
        const all = series.flatMap(item => item.values.map(point => point.value));
        if (!all.length) return null;
        let min = Math.min(...all), max = Math.max(...all);
        if (min === max) { min -= 1; max += 1; }
        const x = t => 75 + (t - start) / (end - start) * 900;
        const y = v => 285 - (v - min) / (max - min) * 250;
        for (let i = 0; i <= 4; i++) {
            const yy = 35 + i * 62.5;
            svg.append(element('line', {x1: 75, y1: yy, x2: 975, y2: yy, stroke: '#d8e0e6'}));
            svg.append(element('text', {x: 68, y: yy + 4, 'text-anchor': 'end'}, (max - i * (max - min) / 4).toPrecision(4)));
        }
        for (let i = 0; i <= 4; i++) {
            const xx = 75 + i * 225;
            const date = new Date(start + i * (end - start) / 4);
            svg.append(element('text', {x: xx, y: 310, 'text-anchor': i === 0 ? 'start' : i === 4 ? 'end' : 'middle'}, date.toISOString().slice(0, 16).replace('T', ' ')));
        }
        svg.append(element('text', {x: 975, y: 334, 'text-anchor': 'end'}, 'UTC'));
        for (const item of series) {
            const li = document.createElement('li');
            const swatch = document.createElement('span');
            swatch.style.backgroundColor = item.color;
            const label = document.createElement('label');
            const connect = document.createElement('input');
            connect.type = 'checkbox';
            connect.checked = ['°C', 'Hz'].includes(unit);
            label.append(connect, document.createTextNode(' Lijn: ' + item.label));
            li.append(swatch, label);
            legend.append(li);
            let path = '', previous = null;
            for (const point of item.values) {
                path += (previous === null || point.time - previous > 86400000 ? ' M ' : ' L ')
                    + x(point.time) + ' ' + y(point.value);
                previous = point.time;
            }
            const line = element('path', {d: path, fill: 'none', stroke: item.color, 'stroke-width': 2});
            line.style.display = connect.checked ? '' : 'none';
            connect.addEventListener('change', () => { line.style.display = connect.checked ? '' : 'none'; });
            svg.append(line);
            for (const point of item.values) {
                const description = item.label + ' — ' + point.raw + (unit ? ' ' + unit : '')
                    + ' — ' + point.timeLabel + ' (UTC): ' + point.timestamp;
                const circle = element('circle', {cx: x(point.time), cy: y(point.value), r: 4, fill: item.color, tabindex: '0', 'aria-label': description});
                circle.append(element('title', {}, description));
                circle.addEventListener('focus', () => { detail.textContent = description; });
                circle.addEventListener('mouseenter', () => { detail.textContent = description; });
                svg.append(circle);
            }
        }
        section.append(legend, detail, svg);
        return section;
    }

    document.getElementById('chart-show').addEventListener('click', async () => {
        const ids = [...points.selectedOptions].map(option => option.value);
        charts.replaceChildren();
        const currentRequest = ++requestNumber;
        if (!ids.length || ids.length > 8) {
            message.textContent = 'Selecteer één tot acht meetpunten.';
            return;
        }
        const end = period.value === 'custom' ? utcTime(endInput.value) : Date.now();
        const start = period.value === 'custom' ? utcTime(startInput.value) : end - Number(period.value) * 86400000;
        if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start || end - start > 30 * 86400000) {
            message.textContent = 'Kies een geldige periode van maximaal 30 dagen.';
            return;
        }
        const startSeconds = Math.floor(start / 1000), endSeconds = Math.floor(end / 1000);
        message.textContent = 'Historie laden…';
        try {
            const query = new URLSearchParams({points: ids.join(','), start: String(startSeconds), end: String(endSeconds)});
            const response = await fetch('history.php?' + query);
            const data = await response.json();
            if (currentRequest !== requestNumber) return;
            if (!response.ok) throw new Error(data.error || 'Historie is niet beschikbaar.');
            const groups = new Map();
            let truncated = false, missing = 0;
            data.series.forEach((item, index) => {
                const unit = item.point.unit || '';
                const values = item.observations.flatMap(observation => {
                    const raw = observation.raw_value;
                    const timestamp = observation.source_timestamp || observation.collected_at;
                    const time = utcTime(timestamp);
                    if (typeof raw !== 'string' || !/^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i.test(raw.trim())
                        || !Number.isFinite(Number(raw)) || !Number.isFinite(time)) return [];
                    return [{raw, value: Number(raw), timestamp, time, timeLabel: observation.source_timestamp ? 'Bron tijd' : 'Verzameld'}];
                });
                if (!values.length) missing++;
                truncated ||= item.truncated;
                if (!groups.has(unit)) groups.set(unit, []);
                groups.get(unit).push({values, color: colors[index], label: item.point.name + ' — ' + item.point.device_name + ' [' + item.point.source_point_id + ']'});
            });
            for (const [unit, group] of groups) {
                const chart = drawGroup(unit, group, startSeconds * 1000, endSeconds * 1000);
                if (chart) charts.append(chart);
            }
            message.textContent = (charts.childElementCount ? 'Wijs een punt aan of gebruik Tab voor de waarde en tijd.' : 'Geen numerieke waarnemingen in deze periode.')
                + (missing ? ' Meetpunten zonder numerieke waarnemingen: ' + missing + '.' : '')
                + (truncated ? ' Let op: per meetpunt worden maximaal de 3000 recentste waarnemingen getoond; de periode is onvolledig.' : '')
                + ' Gaten groter dan 24 uur worden niet verbonden.';
        } catch (error) {
            if (currentRequest === requestNumber) message.textContent = error.message || 'Historie is niet beschikbaar.';
        }
    });
    document.getElementById('chart-show').click();
})();
