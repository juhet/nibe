'use strict';

(() => {
    function parseUtc(value) {
        return value ? Date.parse(value.replace(' ', 'T') + 'Z') : NaN;
    }

    const localDateTime = timestamp => new Intl.DateTimeFormat('nl-NL', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(timestamp));
    for (const time of document.querySelectorAll('.local-time')) {
        const timestamp = parseUtc(time.dateTime);
        if (Number.isFinite(timestamp)) time.textContent = localDateTime(timestamp);
    }

    const settingDialog = document.getElementById('setting-dialog');
    const settingForm = document.getElementById('setting-form');
    if (settingDialog && settingForm) {
        const fields = Object.fromEntries([...settingForm.elements].map(field => [field.name, field]));
        document.getElementById('setting-add').addEventListener('click', () => {
            settingForm.reset();
            fields.id.value = '';
            fields.changed_at.value = new Date().toISOString().slice(0, 16);
            document.getElementById('setting-dialog-title').textContent = 'Instelling vastleggen';
            settingDialog.showModal();
        });
        for (const button of document.querySelectorAll('.setting-edit')) {
            button.addEventListener('click', () => {
                fields.id.value = button.dataset.id;
                fields.setting.value = button.dataset.setting;
                fields.old_value.value = button.dataset.oldValue;
                fields.new_value.value = button.dataset.newValue;
                fields.note.value = button.dataset.note;
                fields.changed_at.value = button.dataset.changedAt;
                document.getElementById('setting-dialog-title').textContent = 'Instelling bewerken';
                settingDialog.showModal();
            });
        }
        document.getElementById('setting-cancel').addEventListener('click', () => settingDialog.close());
        for (const form of document.querySelectorAll('.setting-delete')) {
            form.addEventListener('submit', event => {
                if (!window.confirm('Weet je zeker dat je deze instelling wilt verwijderen?')) event.preventDefault();
            });
        }
    }

    const charts = document.getElementById('charts');
    if (!charts) return;
    const points = charts.dataset.points.split(',').filter(Boolean);
    const period = document.getElementById('chart-period');
    const startInput = document.getElementById('chart-start');
    const endInput = document.getElementById('chart-end');
    const message = document.getElementById('chart-message');
    const zoomIn = document.getElementById('chart-zoom-in');
    const zoomOut = document.getElementById('chart-zoom-out');
    const colors = ['#087f8c', '#c14953', '#7156a5', '#b36b00', '#2865a3', '#47813c', '#8a4c7d', '#527b8b'];
    let requestNumber = 0;
    let currentRange = null;

    period.addEventListener('change', () => {
        startInput.disabled = endInput.disabled = period.value !== 'custom';
    });

    function zoom(factor) {
        if (currentRange === null) {
            document.getElementById('chart-show').click();
            return;
        }
        const center = (currentRange.start + currentRange.end) / 2;
        const duration = Math.max(5 * 60 * 1000, Math.min(30 * 86400000,
            (currentRange.end - currentRange.start) * factor));
        currentRange = {start: center - duration / 2, end: center + duration / 2};
        const localInputValue = timestamp => {
            const date = new Date(timestamp - new Date(timestamp).getTimezoneOffset() * 60000);
            return date.toISOString().slice(0, 16);
        };
        period.value = 'custom';
        startInput.disabled = endInput.disabled = false;
        startInput.value = localInputValue(currentRange.start);
        endInput.value = localInputValue(currentRange.end);
        document.getElementById('chart-show').click();
    }

    zoomIn.addEventListener('click', () => zoom(0.5));
    zoomOut.addEventListener('click', () => zoom(2));

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

    function valueAt(item, time, maxGap) {
        const values = item.values;
        let low = 0, high = values.length;
        while (low < high) {
            const middle = Math.floor((low + high) / 2);
            if (values[middle].time < time) low = middle + 1;
            else high = middle;
        }
        const after = values[low];
        if (after && after.time === time) return {value: after.value, raw: after.raw};
        const before = values[low - 1];
        if (!before || !after || after.time - before.time > maxGap) {
            const nearest = !before ? after : !after ? before
                : time - before.time <= after.time - time ? before : after;
            return nearest ? {value: nearest.value, raw: nearest.raw, observedTime: nearest.time} : null;
        }
        const fraction = (time - before.time) / (after.time - before.time);
        const value = before.value + (after.value - before.value) * fraction;
        return Number.isFinite(value) ? {value, estimated: true} : null;
    }

    function drawGroup(unit, series, start, end) {
        const section = document.createElement('section');
        const heading = document.createElement('h3');
        heading.textContent = unit ? 'Eenheid: ' + unit : 'Zonder eenheid';
        section.append(heading);
        const legend = document.createElement('ul');
        legend.className = 'legend';
        const detail = document.createElement('p');
        detail.className = 'muted comparison';
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
        const normalizedUnit = unit.trim().toLowerCase();
        const maxLineGap = ['°c', 'c', 'celsius'].includes(normalizedUnit)
            ? 24 * 3600000
            : 3600000;
        for (let value = axisMin, i = 0; value <= axisMax + interval / 1000 && i < 10; value += interval, i++) {
            const yy = y(value);
            svg.append(element('line', {x1: 75, y1: yy, x2: 975, y2: yy, stroke: '#d8e0e6'}));
            svg.append(element('text', {x: 68, y: yy + 4, 'text-anchor': 'end'}, numberFormat.format(value)));
        }
        for (let i = 0; i <= 4; i++) {
            const xx = 75 + i * 225;
            const date = new Date(start + i * (end - start) / 4);
            svg.append(element('text', {x: xx, y: 310, 'text-anchor': i === 0 ? 'start' : i === 4 ? 'end' : 'middle'}, localDateTime(date.getTime())));
        }
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
                path += (previous === null || point.time - previous > maxLineGap ? ' M ' : ' L ')
                    + x(point.time) + ' ' + y(point.value);
                previous = point.time;
            }
            const seriesGroup = element('g');
            const line = element('path', {d: path, fill: 'none', stroke: item.color, 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round'});
            seriesGroup.append(line);
            for (const point of item.values) {
                const description = item.label + ' — ' + point.raw + (unit ? ' ' + unit : '')
                    + ' — ' + point.timeLabel + ': ' + localDateTime(point.time);
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
            const descriptions = [localDateTime(time)];
            for (const item of series) {
                if (!item.visible) {
                    item.comparisonMarker.setAttribute('visibility', 'hidden');
                    continue;
                }
                const value = valueAt(item, time, maxLineGap);
                if (value === null) {
                    item.comparisonMarker.setAttribute('visibility', 'hidden');
                    descriptions.push(item.label + ': geen meting');
                    continue;
                }
                item.comparisonMarker.setAttribute('cx', x(value.observedTime ?? time));
                item.comparisonMarker.setAttribute('cy', y(value.value));
                item.comparisonMarker.setAttribute('visibility', 'visible');
                descriptions.push(item.label + ': '
                    + (value.estimated ? numberFormat.format(value.value) : value.raw)
                    + (unit ? ' ' + unit : '')
                    + (value.observedTime === undefined ? '' : ' — waargenomen: ' + localDateTime(value.observedTime)));
            }
            detail.textContent = descriptions.join('\n');
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

    function chartSeries(item, index) {
        const values = item.observations.flatMap(observation => {
            const raw = observation.raw_value;
            const timestamp = observation.source_timestamp || observation.collected_at;
            const time = parseUtc(timestamp);
            if (typeof raw !== 'string' || !/^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i.test(raw.trim())
                || !Number.isFinite(Number(raw)) || !Number.isFinite(time)) return [];
            return [{raw, value: Number(raw), timestamp, time, timeLabel: observation.source_timestamp ? 'Bron tijd' : 'Verzameld'}];
        });
        values.sort((left, right) => left.time - right.time);
        return {
            values,
            color: colors[index],
            label: item.point.display_name || item.point.name,
        };
    }

    for (const button of document.querySelectorAll('.important-chart-toggle')) {
        const panel = document.getElementById(button.getAttribute('aria-controls'));
        button.addEventListener('click', async () => {
            const request = Number(panel.dataset.request || 0) + 1;
            panel.dataset.request = String(request);
            if (!panel.hidden) {
                panel.hidden = true;
                panel.replaceChildren();
                button.textContent = 'Toon 24-uursgrafiek';
                button.setAttribute('aria-expanded', 'false');
                return;
            }

            panel.hidden = false;
            panel.textContent = 'Historie laden…';
            button.textContent = 'Verberg 24-uursgrafiek';
            button.setAttribute('aria-expanded', 'true');
            const end = Date.now();
            const start = end - 86400000;
            try {
                const query = new URLSearchParams({
                    points: button.dataset.pointId,
                    start: String(Math.floor(start / 1000)),
                    end: String(Math.floor(end / 1000)),
                });
                const response = await fetch('history.php?' + query);
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Historie is niet beschikbaar.');
                if (panel.hidden || panel.dataset.request !== String(request)) return;
                const item = data.series[0];
                const series = chartSeries(item, 0);
                if (!series.values.length) {
                    panel.textContent = 'Geen numerieke waarnemingen in de afgelopen 24 uur.';
                    return;
                }
                panel.replaceChildren(drawGroup(item.point.unit || '', [series], start, end));
                if (item.truncated) {
                    const warning = document.createElement('p');
                    warning.className = 'muted';
                    warning.textContent = 'Niet alle waarnemingen worden getoond.';
                    panel.append(warning);
                }
            } catch (error) {
                if (!panel.hidden && panel.dataset.request === String(request)) {
                    panel.textContent = error.message || 'Historie is niet beschikbaar.';
                }
            }
        });
    }

    document.getElementById('chart-show').addEventListener('click', async () => {
        const ids = points;
        charts.replaceChildren();
        const currentRequest = ++requestNumber;
        if (!ids.length || ids.length > 8) {
            message.textContent = 'Selecteer één tot acht meetpunten.';
            return;
        }
        const end = period.value === 'custom' ? Date.parse(endInput.value) : Date.now();
        const duration = period.value === '6h' ? 6 * 3600000 : Number(period.value) * 86400000;
        const start = period.value === 'custom' ? Date.parse(startInput.value) : end - duration;
        if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start || end - start > 30 * 86400000) {
            message.textContent = 'Kies een geldige periode van maximaal 30 dagen.';
            return;
        }
        currentRange = {start, end};
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
                const series = chartSeries(item, index);
                if (!series.values.length) missing++;
                truncated ||= item.truncated;
                if (!groups.has(unit)) groups.set(unit, []);
                groups.get(unit).push(series);
            });
            for (const [unit, group] of groups) {
                const chart = drawGroup(unit, group, startSeconds * 1000, endSeconds * 1000);
                if (chart) charts.append(chart);
            }
            message.textContent = (charts.childElementCount ? 'Beweeg over de grafiek of gebruik Tab om meetpunten op hetzelfde tijdstip te vergelijken.' : 'Geen numerieke waarnemingen in deze periode.')
                + (missing ? ' Meetpunten zonder numerieke waarnemingen: ' + missing + '.' : '')
                + (truncated ? ' Let op: per meetpunt worden maximaal de 3000 recentste waarnemingen getoond; de periode is onvolledig.' : '')
                + ' Gaten groter dan 1 uur (of 24 uur bij temperaturen) worden niet verbonden; ontbrekende waarden worden niet geschat.';
        } catch (error) {
            if (currentRequest === requestNumber) message.textContent = error.message || 'Historie is niet beschikbaar.';
        }
    });
    document.getElementById('chart-show').click();
})();
