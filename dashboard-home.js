'use strict';

(() => {
    const data = document.getElementById('reading-data');
    const slider = document.getElementById('timeline-slider');
    if (!data || !slider) return;

    const pointIds = JSON.parse(data.dataset.pointIds || '{}');
    const cycles = JSON.parse(data.dataset.cycles || '[]');
    const timeLabel = document.getElementById('timeline-time');
    const status = document.getElementById('timeline-status');
    const contextPanel = document.getElementById('context-panel');
    const contextTitle = document.getElementById('context-title');
    const contextContent = document.getElementById('context-content');
    const contextLink = document.getElementById('context-link');
    const twin = document.querySelector('.twin');
    const badge = document.querySelector('.live-badge');
    const initialReadings = [...document.querySelectorAll('[data-reading]')].map(element => [element, element.innerHTML]);
    const initialStatus = badge.textContent;
    const initialClass = badge.className;
    const initialContext = contextContent.innerHTML;
    const initialUpdated = document.querySelector('.hero-heading .updated').innerHTML;
    let requestId = 0;
    let timer = null;

    const localDateTime = timestamp => new Intl.DateTimeFormat('nl-NL', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(timestamp));
    const oneDecimal = new Intl.NumberFormat('nl-NL', {maximumFractionDigits: 1});
    const numberFormat = new Intl.NumberFormat('nl-NL', {maximumFractionDigits: 2});

    function powerKw(role, value, unit) {
        if (!Number.isFinite(value) || (role === 'electric_power' && value <= 0)
            || (role === 'heat_power' && value < 0)) return null;
        switch ((unit || '').trim().toLowerCase()) {
            case 'w':
            case 'watts': return value / 1000;
            case 'kw':
            case 'kilowatts': return value;
            default: return null;
        }
    }

    function display(role, point) {
        if (!point) return 'Niet beschikbaar';
        if (role === 'electric_power' || role === 'heat_power') {
            const value = powerKw(role, point.value, point.unit);
            return value === null ? 'Niet beschikbaar' : numberFormat.format(value) + ' kW';
        }
        const format = ['frequency', 'indoor', 'outdoor', 'hot_water'].includes(role) ? oneDecimal : numberFormat;
        return format.format(point.value) + (point.unit ? ' ' + point.unit : '');
    }

    function updateView(values) {
        const electric = powerKw('electric_power', values.electric_power?.value, values.electric_power?.unit);
        const heat = powerKw('heat_power', values.heat_power?.value, values.heat_power?.unit);
        const cop = electric !== null && electric > 0 && heat !== null ? heat / electric : null;
        const formatted = {
            indoor: 'Niet beschikbaar',
            outdoor: 'Niet beschikbaar',
            hot_water: 'Niet beschikbaar',
            frequency: 'Niet beschikbaar',
            ...Object.fromEntries(Object.entries(values).map(([role, point]) => [role, display(role, point)])),
            electric_power: electric === null ? 'Niet beschikbaar' : numberFormat.format(electric) + ' kW',
            heat_power: heat === null ? 'Niet beschikbaar' : numberFormat.format(heat) + ' kW',
            cop: cop === null ? 'Niet beschikbaar' : numberFormat.format(cop),
        };
        for (const [role, value] of Object.entries(formatted)) {
            for (const element of document.querySelectorAll('[data-reading="' + role + '"]')) {
                element.textContent = value;
            }
        }

        const frequency = values.frequency?.value;
        const defrost = values.defrost?.value;
        const cycle = cycles.find(item => timestampInCycle(Number(slider.value), item));
        const operation = cycle?.kind || 'unknown';
        const systemStatus = frequency === undefined || frequency < 0
            ? 'Status niet beschikbaar'
            : defrost > 0 ? 'Ontdooien' : frequency > 0
                ? operation === 'both' ? 'Verwarming en tapwater'
                    : operation === 'tapwater' ? 'Tapwater wordt verwarmd'
                    : operation === 'heating' ? 'Verwarming actief' : 'Compressor actief'
                : 'Stand-by';
        const state = frequency === undefined || frequency < 0 ? 'unknown'
            : defrost > 0 ? 'defrost' : frequency > 0 ? 'active' : 'standby';
        twin.dataset.state = state;
        twin.dataset.operation = operation;
        badge.className = 'live-badge ' + state;
        badge.textContent = systemStatus;
        const pumpStatus = document.querySelector('.node.pump .node-value');
        if (pumpStatus) pumpStatus.textContent = systemStatus;
        const headlineStatus = document.querySelector('.hero-heading .updated');
        if (headlineStatus) headlineStatus.textContent = localDateTime(Number(slider.value) * 1000) + ' · ' + systemStatus;
    }

    function timestampInCycle(timestamp, cycle) {
        return timestamp >= cycle.start && timestamp <= cycle.end;
    }

    async function loadAt(timestamp, currentRequest) {
        const roles = Object.keys(pointIds);
        if (roles.length === 0) {
            status.textContent = 'Geen meetpunten beschikbaar voor tijdselectie.';
            updateView({});
            return;
        }
        const query = new URLSearchParams({
            points: roles.map(role => pointIds[role]).join(','),
            start: String(timestamp - 300),
            end: String(timestamp + 300),
        });
        try {
            const response = await fetch('history.php?' + query, {headers: {'Accept': 'application/json'}});
            const result = await response.json();
            if (!response.ok || !Array.isArray(result.series)) throw new Error('Historie is niet beschikbaar.');
            if (currentRequest !== requestId) return;

            const values = {};
            for (const series of result.series) {
                const role = roles.find(key => String(pointIds[key]) === String(series.point?.id));
                if (!role || !Array.isArray(series.observations)) continue;
                let nearest = null;
                let distance = 301;
                for (const observation of series.observations) {
                    const raw = observation.raw_value;
                    const sourceTime = observation.source_timestamp || observation.collected_at;
                    const observedAt = sourceTime ? Date.parse(sourceTime.replace(' ', 'T') + 'Z') / 1000 : NaN;
                    if (typeof raw !== 'string' || raw.trim() === '' || !Number.isFinite(Number(raw)) || !Number.isFinite(observedAt)) continue;
                    const candidateDistance = Math.abs(observedAt - timestamp);
                    if (candidateDistance < distance) {
                        nearest = {value: Number(raw), unit: series.point.unit || ''};
                        distance = candidateDistance;
                    }
                }
                if (nearest !== null) values[role] = nearest;
            }
            updateView(values);
            status.textContent = timestamp === Number(slider.max)
                ? 'Huidige waarden uit de verzamelde historie.'
                : 'Historische waarden; metingen maximaal 5 minuten van dit tijdstip.';
        } catch {
            if (currentRequest !== requestId) return;
            status.textContent = 'Historie is niet beschikbaar.';
            updateView({});
        }
    }

    slider.addEventListener('input', () => {
        if (contextPanel.classList.contains('details-open')) document.getElementById('context-close').click();
        const timestamp = Number(slider.value);
        const currentRequest = ++requestId;
        timeLabel.textContent = localDateTime(timestamp * 1000);
        status.textContent = 'Historie laden…';
        clearTimeout(timer);
        if (timestamp === Number(slider.max)) {
            initialReadings.forEach(([element, html]) => { element.innerHTML = html; });
            badge.className = initialClass;
            badge.textContent = initialStatus;
            twin.dataset.state = initialClass.split(' ').pop();
            twin.dataset.operation = 'unknown';
            document.querySelector('.node.pump .node-value').textContent = initialStatus;
            document.querySelector('.hero-heading .updated').innerHTML = initialUpdated;
            status.textContent = 'Actuele metingen.';
            return;
        }
        updateView({});
        timer = setTimeout(() => {
            loadAt(timestamp, currentRequest);
        }, 250);
    });

    const labels = {
        warmtepomp: 'Warmtepomp',
        woning: 'Temperatuur in huis',
        tapwater: 'Tapwater',
        buitenunit: 'Weer in Grootegast',
    };
    const details = {
        warmtepomp: 'Compressorfrequentie, actueel elektrisch vermogen en COP op basis van beschikbare metingen.',
        woning: 'De meest recente binnentemperatuur en de temperatuurhistorie van de installatie.',
        tapwater: 'De meest recente gemeten tapwatertemperatuur en bijbehorende historie.',
        buitenunit: 'Actuele weersomstandigheden bij je installatie.',
    };
    for (const button of document.querySelectorAll('.node[data-panel]')) {
        button.addEventListener('click', () => {
            const key = button.dataset.panel;
            contextTitle.textContent = labels[key] || 'Installatieonderdeel';
            contextContent.textContent = details[key] || '';
            if (key === 'buitenunit') {
                if (!button.dataset.weatherCondition) {
                    contextContent.textContent = 'Weerdata wordt zichtbaar na de eerste geslaagde verzameling.';
                } else {
                    const summary = document.createElement('p');
                    summary.className = 'weather-condition';
                    summary.textContent = button.dataset.weatherCondition + ' · ' + button.dataset.weatherTemperature;
                    const weatherDetails = document.createElement('div');
                    weatherDetails.className = 'weather-details';
                    for (const [label, value] of [
                        ['Gevoel', button.dataset.weatherApparent],
                        ['Luchtvochtigheid', button.dataset.weatherHumidity],
                        ['Wind', button.dataset.weatherWind],
                        ['Neerslag', button.dataset.weatherPrecipitation],
                    ]) {
                        const detail = document.createElement('div');
                        detail.className = 'weather-detail';
                        detail.textContent = label;
                        const strong = document.createElement('strong');
                        strong.textContent = value;
                        detail.append(strong);
                        weatherDetails.append(detail);
                    }
                    const updated = document.createElement('p');
                    updated.className = 'weather-updated';
                    updated.textContent = 'Waargenomen ' + localDateTime(Date.parse(button.dataset.weatherTimestamp.replace(' ', 'T') + 'Z'));
                    contextContent.replaceChildren(summary, weatherDetails, updated);
                }
            } else {
                const roles = {warmtepomp: ['frequency', 'electric_power', 'cop'], woning: ['indoor'], tapwater: ['hot_water']};
                const names = {frequency: 'Compressor', electric_power: 'Elektriciteit', cop: 'COP', indoor: 'Binnen', hot_water: 'Tapwater'};
                for (const role of roles[key] || []) {
                    const paragraph = document.createElement('p');
                    paragraph.textContent = names[role] + ': ' + (document.querySelector('[data-reading="' + role + '"]')?.textContent.trim() || 'Niet beschikbaar');
                    contextContent.append(paragraph);
                }
            }
            contextLink.href = button.dataset.target;
            contextPanel.hidden = false;
            contextPanel.classList.add('details-open');
            document.querySelectorAll('.node[data-panel]').forEach(node => node.setAttribute('aria-pressed', String(node === button)));
        });
    }
    document.getElementById('context-close')?.addEventListener('click', () => {
        contextPanel.classList.remove('details-open');
        contextTitle.textContent = 'Inzichten uit je systeem';
        contextContent.innerHTML = initialContext;
        contextLink.href = 'index_old.php#performance-title';
        document.querySelectorAll('.node[data-panel]').forEach(node => node.setAttribute('aria-pressed', 'false'));
    });
})();
