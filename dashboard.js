'use strict';

(() => {
    const roles = [...document.querySelectorAll('.role-select')];
    const points = document.getElementById('chart-points');
    const period = document.getElementById('chart-period');
    const startInput = document.getElementById('chart-start');
    const endInput = document.getElementById('chart-end');
    const message = document.getElementById('chart-message');
    const charts = document.getElementById('charts');
    const colors = ['#087f8c', '#c14953', '#7156a5', '#b36b00', '#2865a3', '#47813c', '#8a4c7d', '#527b8b'];
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

    function tickInterval(range, unit) {
        if (unit === '°C') return 10;
        const target = range / 4;
        const magnitude = 10 ** Math.floor(Math.log10(target));
        const normalized = target / magnitude;
        return (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10) * magnitude;
    }

    function valueAt(item, time) {
        const values = item.values;
        let next = values.findIndex(point => point.time >= time);
        if (next !== -1 && Math.abs(values[next].time - time) < 1) {
            return {value: values[next].value, raw: values[next].raw, estimated: false};
        }
        if (next <= 0 || next === -1) return null;
        const before = values[next - 1], after = values[next];
        if (after.time - before.time > 86400000) return null;
        const fraction = (time - before.time) / (after.time - before.time);
        return {value: before.value + fraction * (after.value - before.value), estimated: true};
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
        detail.textContent = 'Beweeg over de grafiek of selecteer een punt met Tab om de meetpunten op hetzelfde tijdstip te vergelijken.';
        const svg = element('svg', {viewBox: '0 0 1000 340', class: 'chart', role: 'img', 'aria-label': 'Waarnemingen, ' + (unit || 'zonder eenheid')});
        const all = series.flatMap(item => item.values.map(point => point.value));
        if (!all.length) return null;
        let min = Math.min(...all), max = Math.max(...all);
        const interval = tickInterval(max - min || Math.abs(max) || 1, unit);
        let axisMin = Math.floor(min / interval) * interval;
        let axisMax = Math.ceil(max / interval) * interval;
        if (axisMin === axisMax) {
            axisMin -= interval;
            axisMax += interval;
        }
        const x = t => 75 + (t - start) / (end - start) * 900;
        const y = v => 285 - (v - axisMin) / (axisMax - axisMin) * 250;
        const numberFormat = new Intl.NumberFormat('nl-NL', {maximumFractionDigits: 2});
        for (let value = axisMin, i = 0; value <= axisMax + interval / 1000 && i < 10; value += interval, i++) {
            const yy = y(value);
            svg.append(element('line', {x1: 75, y1: yy, x2: 975, y2: yy, stroke: '#d8e0e6'}));
            svg.append(element('text', {x: 68, y: yy + 4, 'text-anchor': 'end'}, numberFormat.format(value)));
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
            connect.checked = true;
            item.visible = true;
            label.append(connect, document.createTextNode(' ' + item.label));
            li.append(swatch, label);
            legend.append(li);
            let path = '', previous = null;
            for (const point of item.values) {
                path += (previous === null || point.time - previous > 86400000 ? ' M ' : ' L ')
                    + x(point.time) + ' ' + y(point.value);
                previous = point.time;
            }
            const seriesGroup = element('g');
            const line = element('path', {d: path, fill: 'none', stroke: item.color, 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round'});
            seriesGroup.append(line);
            for (const point of item.values) {
                const description = item.label + ' — ' + point.raw + (unit ? ' ' + unit : '')
                    + ' — ' + point.timeLabel + ' (UTC): ' + point.timestamp;
                const circle = element('circle', {cx: x(point.time), cy: y(point.value), r: 2.5, fill: item.color, tabindex: '0', 'aria-label': description});
                circle.append(element('title', {}, description));
                circle.addEventListener('focus', () => showComparison(point.time));
                seriesGroup.append(circle);
            }
            item.comparisonMarker = element('circle', {r: 4, fill: item.color, stroke: '#fff', 'stroke-width': 1, 'pointer-events': 'none', visibility: 'hidden'});
            seriesGroup.append(item.comparisonMarker);
            connect.addEventListener('change', () => {
                item.visible = connect.checked;
                seriesGroup.style.display = item.visible ? '' : 'none';
            });
            svg.append(seriesGroup);
        }
        const comparisonLine = element('line', {y1: 35, y2: 285, stroke: '#526170', 'stroke-dasharray': '4 4', 'pointer-events': 'none', visibility: 'hidden'});
        svg.append(comparisonLine);
        function showComparison(time) {
            comparisonLine.setAttribute('x1', x(time));
            comparisonLine.setAttribute('x2', x(time));
            comparisonLine.setAttribute('visibility', 'visible');
            const descriptions = [new Date(time).toISOString().slice(0, 16).replace('T', ' ') + ' UTC'];
            for (const item of series) {
                if (!item.visible) {
                    item.comparisonMarker.setAttribute('visibility', 'hidden');
                    continue;
                }
                const value = valueAt(item, time);
                if (value === null) {
                    item.comparisonMarker.setAttribute('visibility', 'hidden');
                    descriptions.push(item.label + ': geen meting');
                    continue;
                }
                item.comparisonMarker.setAttribute('cx', x(time));
                item.comparisonMarker.setAttribute('cy', y(value.value));
                item.comparisonMarker.setAttribute('visibility', 'visible');
                descriptions.push(item.label + ': ' + (value.estimated ? numberFormat.format(value.value) : value.raw)
                    + (unit ? ' ' + unit : '') + (value.estimated ? ' (geschat)' : ' (gemeten)'));
            }
            detail.textContent = descriptions.join(' · ');
        }
        const hitArea = element('rect', {x: 75, y: 35, width: 900, height: 250, fill: 'transparent', 'pointer-events': 'all', 'aria-label': 'Vergelijk meetpunten op dit tijdstip'});
        hitArea.style.cursor = 'crosshair';
        hitArea.addEventListener('pointermove', event => {
            const point = svg.createSVGPoint();
            point.x = event.clientX;
            point.y = event.clientY;
            const chartX = point.matrixTransform(svg.getScreenCTM().inverse()).x;
            showComparison(start + (chartX - 75) / 900 * (end - start));
        });
        hitArea.addEventListener('pointerleave', () => {
            comparisonLine.setAttribute('visibility', 'hidden');
            for (const item of series) item.comparisonMarker.setAttribute('visibility', 'hidden');
        });
        svg.append(hitArea);
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
                values.sort((left, right) => left.time - right.time);
                groups.get(unit).push({values, color: colors[index], label: item.point.display_name || item.point.name});
            });
            for (const [unit, group] of groups) {
                const chart = drawGroup(unit, group, startSeconds * 1000, endSeconds * 1000);
                if (chart) charts.append(chart);
            }
            message.textContent = (charts.childElementCount ? 'Beweeg over de grafiek of gebruik Tab om meetpunten op hetzelfde tijdstip te vergelijken.' : 'Geen numerieke waarnemingen in deze periode.')
                + (missing ? ' Meetpunten zonder numerieke waarnemingen: ' + missing + '.' : '')
                + (truncated ? ' Let op: per meetpunt worden maximaal de 3000 recentste waarnemingen getoond; de periode is onvolledig.' : '')
                + ' Gaten groter dan 24 uur worden niet verbonden of geschat.';
        } catch (error) {
            if (currentRequest === requestNumber) message.textContent = error.message || 'Historie is niet beschikbaar.';
        }
    });
    document.getElementById('chart-show').click();
})();
