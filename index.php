<?php

$nibeDashboardDataOnly = true;
require __DIR__ . '/index_old.php';
unset($nibeDashboardDataOnly);

$measurementById ??= [];
$defaultRoleMapping ??= [];
$roleUnits ??= [];
$performance ??= [];
$suggestions ??= [];
$databaseReady ??= false;
$installationLabel ??= 'NIBE-installatie';
$readings = [];
foreach ($defaultRoleMapping as $role => $measurementId) {
    $measurement = $measurementById[$measurementId] ?? null;
    if ($measurement === null) {
        continue;
    }
    $timestamp = $measurement['source_timestamp'] ?? $measurement['collected_at'] ?? null;
    $freshness = nibe_dashboard_measurement_freshness(
        is_string($timestamp) ? $timestamp : null,
        $now
    );
    $raw = $measurement['raw_value'] ?? null;
    $numericValue = is_string($raw) && is_numeric($raw) ? (float) $raw : null;
    $readings[$role] = [
        'value' => $numericValue !== null && is_finite($numericValue) ? $numericValue : null,
        'raw' => is_string($raw) ? $raw : null,
        'unit' => (string) ($roleUnits[$role] ?? ''),
        'fresh' => $freshness['status'] === 'live',
        'age_seconds' => $freshness['age_seconds'],
        'displayable' => $freshness['age_seconds'] !== null
            && $freshness['age_seconds'] >= 0
            && $freshness['age_seconds'] <= 86400
            && $raw !== null,
        'timestamp' => $timestamp,
    ];
}
$formatNumber = static fn (?float $value, int $digits = 1): string => $value === null
    ? 'Niet beschikbaar'
    : number_format($value, $digits, ',', '.');
$formatReading = static function (string $role) use ($readings): string {
    $reading = $readings[$role] ?? null;
    if ($reading === null || !$reading['displayable'] || $reading['raw'] === null) {
        return 'Niet beschikbaar';
    }
    return $reading['raw'] . ($reading['unit'] !== '' ? ' ' . $reading['unit'] : '');
};
$readingIndicator = static function (string $role) use ($readings): string {
    $reading = $readings[$role] ?? null;
    $class = $reading === null || $reading['raw'] === null || $reading['age_seconds'] === null
        || $reading['age_seconds'] < 0
        ? 'missing'
        : (!$reading['displayable'] ? 'unavailable' : ($reading['fresh'] ? 'fresh' : 'stale'));
    $label = match ($class) {
        'fresh' => 'Meting jonger dan 15 minuten',
        'stale' => 'Laatste waarde, ouder dan 15 minuten',
        'unavailable' => 'Laatste meting is ouder dan 24 uur',
        default => $reading !== null && $reading['raw'] !== null
            ? 'Meetwaarde heeft geen geldige meettijd'
            : 'Geen meting beschikbaar',
    };
    return '<span class="reading-status ' . $class . '" role="img" aria-label="' . $label . '" title="' . $label . '"></span>';
};
$powerKw = static function (string $role) use ($readings): ?float {
    $reading = $readings[$role] ?? null;
    if ($reading === null || !$reading['displayable'] || $reading['value'] === null) {
        return null;
    }
    if (($role === 'electric_power' && $reading['value'] <= 0)
        || ($role === 'heat_power' && $reading['value'] < 0)) {
        return null;
    }
    return match (strtolower(trim($reading['unit']))) {
        'w', 'watts' => $reading['value'] / 1000,
        'kw', 'kilowatts' => $reading['value'],
        default => null,
    };
};
$electricKw = $powerKw('electric_power');
$heatKw = $powerKw('heat_power');
$currentCop = $performance['cop_now'] ?? null;
$frequency = $readings['frequency'] ?? null;
$defrost = $readings['defrost'] ?? null;
$lastUpdate = $frequency['timestamp'] ?? null;
$systemStatus = 'Status niet beschikbaar';
if ($frequency !== null && $frequency['fresh'] && $frequency['value'] !== null && $frequency['value'] >= 0) {
    $systemStatus = $defrost !== null && $defrost['fresh'] && $defrost['value'] > 0
        ? 'Ontdooien'
        : ($frequency['value'] > 0 ? 'Compressor actief' : 'Stand-by');
}
$statusClass = match ($systemStatus) {
    'Compressor actief' => 'active',
    'Ontdooien' => 'defrost',
    'Stand-by' => 'standby',
    default => 'unknown',
};
$measurementIds = [];
foreach (['indoor', 'outdoor', 'hot_water', 'frequency', 'electric_power', 'heat_power', 'defrost'] as $role) {
    if (isset($defaultRoleMapping[$role])) {
        $measurementIds[$role] = $defaultRoleMapping[$role];
    }
}
$availableInsights = array_slice($suggestions, 0, 3);
$weatherSymbol = $weather === null ? '◌' : match (true) {
    in_array($weather['weather_code'], [0, 1], true) => $weather['is_day'] ? '☀' : '☾',
    in_array($weather['weather_code'], [2, 3, 45, 48], true) => '☁',
    in_array($weather['weather_code'], [71, 73, 75, 77, 85, 86], true) => '❄',
    in_array($weather['weather_code'], [95, 96, 99], true) => '⚡',
    default => '☂',
};
$compressor24h = $performance['compressor_periods']['24h'] ?? [];
if (count($availableInsights) < 3 && ($compressor24h['complete'] ?? false)
    && is_numeric($compressor24h['average_run_seconds'] ?? null)) {
    $availableInsights[] = [
        'title' => 'Compressorcycli',
        'evidence' => sprintf(
            'Gemiddelde looptijd: %.0f minuten in de afgelopen 24 uur (%s%% dekking).',
            $compressor24h['average_run_seconds'] / 60,
            number_format((float) ($compressor24h['coverage_percentage'] ?? 0), 1, ',', '')
        ),
    ];
}
$timelineStart = $now->getTimestamp() - 86400;
$timelineSegments = [];
foreach ($performance['cycles'] ?? [] as $cycle) {
    if ($cycle['end'] < $timelineStart || $cycle['start'] > $now->getTimestamp()) {
        continue;
    }
    $operation = (string) ($cycle['operation'] ?? '');
    $hasWater = stripos($operation, 'warm water') !== false || stripos($operation, 'tapwater') !== false;
    $hasHeating = stripos($operation, 'verwarming') !== false;
    $kind = $hasWater && $hasHeating ? 'both' : ($hasWater ? 'tapwater' : ($hasHeating ? 'heating' : 'unknown'));
    $start = max($timelineStart, (int) $cycle['start']);
    $end = min($now->getTimestamp(), (int) $cycle['end']);
    $timelineSegments[] = [
        'kind' => $kind,
        'left' => 100 * ($start - $timelineStart) / 86400,
        'width' => max(0.35, 100 * ($end - $start) / 86400),
        'label' => $operation !== '' ? $operation : 'Compressorcyclus',
        'start' => $start,
        'end' => $end,
    ];
}
foreach ($historyByRole['auxiliary_heat_power'] ?? [] as $index => $observation) {
    $raw = $observation['raw_value'] ?? null;
    $timestamp = $observation['source_timestamp'] ?? $observation['collected_at'] ?? null;
    $next = $historyByRole['auxiliary_heat_power'][$index + 1] ?? null;
    if (!is_string($raw) || !is_numeric($raw) || (float) $raw <= 0 || !is_string($timestamp) || $next === null) {
        continue;
    }
    $start = strtotime($timestamp . ' UTC');
    $endValue = $next['source_timestamp'] ?? $next['collected_at'] ?? null;
    $end = is_string($endValue) ? strtotime($endValue . ' UTC') : false;
    if ($start === false || $end === false || $end <= $start || $end - $start > 3600 || $end < $timelineStart) {
        continue;
    }
    $start = max($timelineStart, $start);
    $end = min($now->getTimestamp(), $end);
    if ($end > $start) {
        $timelineSegments[] = [
            'kind' => 'auxiliary',
            'left' => 100 * ($start - $timelineStart) / 86400,
            'width' => max(0.35, 100 * ($end - $start) / 86400),
            'label' => 'Elektrische bijverwarming waargenomen',
        ];
    }
}
$defrostStartTimes = $performance['defrost_periods']['24h']['start_times'] ?? [];
$lastDefrostTime = $defrostStartTimes === [] ? null : end($defrostStartTimes);
$latestCycles = array_slice(array_reverse($performance['cycles'] ?? []), 0, 3);
$eventItems = [];
foreach ($latestCycles as $cycle) {
    $eventItems[] = [
        'time' => (int) $cycle['end'],
        'label' => ($cycle['operation'] ?? null) ?: 'Compressorcyclus afgerond',
    ];
}
if ($lastDefrostTime !== null) {
    $eventItems[] = ['time' => $lastDefrostTime, 'label' => 'Defrost waargenomen'];
}
usort($eventItems, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
$eventItems = array_slice($eventItems, 0, 3);
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#08111f">
    <title><?= nibe_escape($installationLabel) ?> — Energie</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #eef4ff;
            background: #08111f;
            font-synthesis: none;
            --muted: #91a2ba;
            --line: #ffffff16;
            --panel: #111d2d;
            --blue: #70a9ff;
            --cyan: #66e0d1;
            --warm: #ffba78;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(ellipse at 56% -20%, #1b3853 0, #0b1727 45%, #08111f 80%); }
        a { color: inherit; text-decoration: none; }
        button, input { font: inherit; }
        button, a { -webkit-tap-highlight-color: transparent; }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 220px minmax(0, 1fr); }
        .side-nav { position: sticky; top: 0; height: 100vh; padding: 2rem 1rem; border-right: 1px solid var(--line); display: flex; flex-direction: column; gap: 2.5rem; }
        .brand { display: flex; align-items: center; gap: .75rem; font-weight: 700; letter-spacing: .02em; }
        .brand-mark { width: 2rem; height: 2rem; border-radius: .7rem; display: grid; place-items: center; color: #08111f; background: linear-gradient(140deg, #72b4ff, #8ce9d5); }
        .nav-links { display: grid; gap: .5rem; }
        .nav-links a, .mobile-nav a { color: var(--muted); padding: .8rem .9rem; border-radius: .8rem; }
        .nav-links a[aria-current="page"], .nav-links a:hover { color: #f5f8ff; background: #ffffff0d; }
        .nav-caption { margin: auto 0 0; color: var(--muted); font-size: .8rem; line-height: 1.6; }
        main { width: min(100%, 1500px); margin: 0 auto; padding: 2.1rem clamp(1rem, 3vw, 3rem) 4rem; }
        .topbar { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
        .eyebrow { color: var(--muted); text-transform: uppercase; letter-spacing: .13em; font-size: .72rem; font-weight: 700; }
        h1 { margin: .45rem 0 .4rem; font-size: clamp(1.7rem, 3vw, 2.5rem); letter-spacing: -.04em; }
        .subtitle { color: var(--muted); margin: 0; }
        .notice { margin: 0 0 1rem; padding: 1rem 1.1rem; border: 1px solid #ffca7a40; border-radius: .9rem; color: #ffe0aa; background: #d78c2315; line-height: 1.5; }
        .live-badge { border: 1px solid #ffffff20; color: #bac8d9; background: #ffffff09; border-radius: 999px; padding: .55rem .8rem; font-size: .82rem; white-space: nowrap; }
        .live-badge.active { border-color: #68dfb43a; color: #93ebca; background: #49bf8d12; }
        .live-badge.defrost { border-color: #b79bff45; color: #d3c4ff; background: #8868d91a; }
        .live-badge.standby { border-color: #70a9ff38; color: #a9cbff; background: #4b8fd115; }
        .live-badge::before { content: ""; display: inline-block; width: .45rem; height: .45rem; margin-right: .45rem; border-radius: 50%; background: currentColor; box-shadow: 0 0 12px currentColor; vertical-align: .05rem; }
        .overview-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 1rem; align-items: start; }
        .hero, .context, .card, .timeline { background: linear-gradient(145deg, #142236e8, #0d1929e8); border: 1px solid var(--line); border-radius: 1.25rem; box-shadow: 0 18px 50px #00000020; }
        .hero { min-width: 0; overflow: hidden; padding: clamp(1rem, 3vw, 2rem); }
        .hero-heading { display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
        .hero-heading h2, .section-heading h2 { margin: 0; font-size: 1.1rem; }
        .updated { color: var(--muted); font-size: .82rem; text-align: right; }
        .twin { position: relative; min-height: 350px; margin: 1.3rem 0 1.5rem; display: grid; grid-template-columns: 1fr 1.15fr 1fr; grid-template-rows: 1fr 1fr; gap: .8rem; align-items: center; }
        .twin::before { content: ""; position: absolute; inset: 12% 13%; border: 1px solid #8cc8ff16; border-radius: 50%; filter: drop-shadow(0 0 35px #4ba9ff12); pointer-events: none; }
        .node { position: relative; z-index: 1; min-height: 112px; padding: 1rem; border: 1px solid #ffffff19; border-radius: 1rem; background: linear-gradient(150deg, #1a2c41, #101c2c); color: inherit; text-align: left; cursor: pointer; transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease; }
        .node:hover, .node:focus-visible { transform: translateY(-2px); border-color: #75b8ff85; box-shadow: 0 8px 26px #1e82d522; outline: none; }
        .node-icon { display: block; color: #9dcaff; font-size: 1.3rem; margin-bottom: .45rem; }
        .node-label { color: var(--muted); font-size: .78rem; display: block; }
        .node-value { display: block; margin-top: .2rem; font-size: 1.15rem; font-weight: 650; }
        .reading-status { display: inline-block; width: .5rem; height: .5rem; margin-left: .35rem; border-radius: 50%; vertical-align: .08rem; background: #8492a6; }
        .reading-status.fresh { background: #39c887; box-shadow: 0 0 8px #39c88780; }
        .reading-status.stale { background: #f0a43b; box-shadow: 0 0 8px #f0a43b70; }
        .reading-status.unavailable, .reading-status.missing { background: #8492a6; }
        .node.outdoor { grid-column: 1; grid-row: 1; }
        .node.pump { grid-column: 2; grid-row: 1 / 3; min-height: 190px; text-align: center; display: grid; place-content: center; background: linear-gradient(150deg, #1a3c58, #12253a); border-color: #77bdff38; box-shadow: inset 0 0 45px #4f9bd51a, 0 12px 40px #0003; }
        .pump-symbol { width: 74px; height: 74px; margin: 0 auto .8rem; border: 1px solid #8dc8ff5c; border-radius: 1.4rem; display: grid; place-items: center; color: #91c8ff; font-size: 2rem; background: linear-gradient(145deg, #244c6a, #142d45); box-shadow: 0 0 32px #5db2ff20; }
        .node.pump .node-value { font-size: 1.4rem; }
        .node.home { grid-column: 3; grid-row: 1; }
        .node.water { grid-column: 3; grid-row: 2; }
        .flow { position: absolute; z-index: 0; width: 18%; border-top: 2px solid #65d8d1a0; filter: drop-shadow(0 0 5px #55ddd0); }
        .flow::after { content: ""; position: absolute; right: -1px; top: -5px; border: 4px solid transparent; border-left-color: #83f0dd; }
        .flow.electric { top: 31%; left: 22%; width: 17%; border-color: #ffbb786e; filter: drop-shadow(0 0 5px #ffbb78); }
        .flow.electric::after { border-left-color: #ffc98f; }
        .flow.heat { top: 50%; left: 62%; width: 15%; }
        .flow.water-flow { top: 72%; left: 62%; width: 15%; }
        .flow-label { position: absolute; z-index: 1; color: var(--muted); font-size: .72rem; }
        .flow-label.electric-label { top: 21%; left: 20%; color: var(--warm); }
        .flow-label.heat-label { top: 40%; left: 61%; color: var(--cyan); }
        .flow-label.water-label { top: 62%; left: 61%; color: var(--cyan); }
        .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
        .kpi { padding: .95rem; border-radius: .9rem; background: #ffffff08; border: 1px solid #ffffff0c; }
        .kpi-label { color: var(--muted); font-size: .78rem; }
        .kpi-value { margin-top: .35rem; font-size: clamp(1.2rem, 2vw, 1.7rem); font-weight: 680; letter-spacing: -.04em; overflow-wrap: anywhere; }
        .kpi-value small { color: var(--muted); font-size: .74rem; font-weight: 500; letter-spacing: 0; }
        .context { padding: 1.3rem; min-height: 100%; }
        .context h2 { font-size: 1rem; margin: 0 0 1rem; }
        .context-content { color: #c3d0e1; line-height: 1.6; font-size: .9rem; }
        .context-content p { margin: .55rem 0; }
        .context-link { display: inline-flex; margin-top: .8rem; color: #91c7ff; font-weight: 600; }
        .context-close { display: none; }
        .context.details-open .context-close { display: inline-flex; float: right; border: 0; background: transparent; color: var(--muted); padding: .3rem; cursor: pointer; }
        .lower-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(260px, .75fr); gap: 1rem; margin-top: 1rem; }
        .card, .timeline { padding: 1.25rem; }
        .weather-card { position: relative; overflow: hidden; background: radial-gradient(circle at 88% 18%, #70a9ff26, transparent 34%), linear-gradient(145deg, #19334a, #111d2d 72%); }
        .weather-summary { display: flex; align-items: center; gap: 1rem; }
        .weather-symbol { color: #b9ddff; font-size: 3.4rem; line-height: 1; text-shadow: 0 0 28px #70a9ff55; }
        .weather-temperature { font-size: clamp(2.2rem, 5vw, 3rem); font-weight: 700; letter-spacing: -.06em; }
        .weather-condition { color: #c3d0e1; }
        .weather-details { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .6rem; margin-top: 1rem; }
        .weather-detail { padding: .7rem; border-radius: .75rem; background: #ffffff08; color: var(--muted); font-size: .75rem; }
        .weather-detail strong { display: block; margin-top: .25rem; color: #eef4ff; font-size: .9rem; }
        .weather-updated { margin: 1rem 0 0; color: var(--muted); font-size: .75rem; }
        .section-heading { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
        .section-heading a { color: #91c7ff; font-size: .85rem; }
        .insights, .events { list-style: none; padding: 0; margin: 0; display: grid; gap: .7rem; }
        .insights li, .events li { padding: .8rem; border-radius: .8rem; background: #ffffff08; border: 1px solid #ffffff0c; }
        .insights strong, .events strong { display: block; font-size: .9rem; }
        .insights p, .events span { color: var(--muted); font-size: .8rem; margin: .35rem 0 0; line-height: 1.45; }
        .events time { color: #b6c8df; font-size: .75rem; }
        .empty-events { margin: 0; color: var(--muted); font-size: .88rem; line-height: 1.55; }
        .timeline { margin-top: 1rem; }
        .timeline-controls { display: flex; align-items: center; gap: 1rem; }
        .timeline input { flex: 1; accent-color: #70b4ff; min-width: 0; }
        .timeline time { color: #ced9e8; font-variant-numeric: tabular-nums; min-width: 7rem; text-align: right; }
        .timeline-track { height: .7rem; position: relative; overflow: hidden; border-radius: 999px; background: #ffffff10; margin: .2rem 0 1rem; }
        .timeline-segment { position: absolute; top: .18rem; height: .34rem; min-width: 3px; border-radius: 999px; background: var(--cyan); }
        .timeline-segment.tapwater { background: var(--warm); }
        .timeline-segment.auxiliary { background: #f08080; }
        .timeline-segment.defrost { width: 3px; min-width: 3px; height: .7rem; top: 0; border-radius: 0; background: #b79bff; }
        .legend { display: flex; flex-wrap: wrap; gap: 1rem; padding: 0; margin: .7rem 0 0; list-style: none; color: var(--muted); font-size: .75rem; }
        .legend i { width: .5rem; height: .5rem; display: inline-block; border-radius: 50%; margin-right: .35rem; background: var(--cyan); }
        .legend li:nth-child(2) i { background: var(--warm); }
        .legend li:nth-child(3) i { background: #b79bff; }
        .legend li:nth-child(4) i { background: #f08080; }
        .mobile-nav { display: none; }
        /* The installation is an illustration, not a schematic: only observed flows light up. */
        .twin { min-height: 390px; grid-template-columns: 1fr 1.25fr 1fr; }
        .twin::before { inset: 9% 15%; border: 0; background: radial-gradient(ellipse, #3e799d24, transparent 70%); }
        .node, .node.pump { background: transparent; border: 1px solid transparent; box-shadow: none; text-align: center; display: grid; justify-items: center; align-content: center; min-width: 0; }
        .node:hover, .node:focus-visible, .node[aria-pressed="true"] { transform: scale(1.035); border-color: #8acbff36; box-shadow: 0 0 30px #4ba9ff22; background: #ffffff06; }
        .node-value { overflow-wrap: anywhere; }
        .outdoor-unit-illustration { display: block; width: clamp(80px, 13vw, 150px); height: auto; margin: 0 auto .8rem; filter: drop-shadow(5px 9px 8px #0007); }
        .home-illustration { display: block; width: min(100%, 145px); height: auto; margin: .5rem auto; filter: drop-shadow(5px 8px 7px #0006); }
        .boiler-symbol { position: relative; display: block; width: 64px; height: 103px; margin: 5px auto 10px; border-radius: 50% / 12%; background: linear-gradient(90deg, #849da8, #e6eeee 45%, #9cb1b8); box-shadow: 7px 10px 20px #0006; }
        .boiler-symbol span { position: absolute; top: 40%; left: 37%; width: 26%; aspect-ratio: 1; border-radius: 50%; background: #81babb; box-shadow: 0 0 12px #72d8d2; }
        .flow { height: 4px; border: 0; border-radius: 99px; opacity: .14; background: #ffb66d; filter: drop-shadow(0 0 7px #ff9a42); overflow: hidden; transition: opacity .3s; }
        .flow::after { content: ""; position: absolute; inset: 0; width: 35%; border: 0; background: linear-gradient(90deg, transparent, #fff4d8, transparent); animation: energy-flow 1.5s linear infinite; }
        .flow.electric { top: 29%; left: 21%; background: #64e4dc; filter: drop-shadow(0 0 7px #4be2d7); }
        .flow.heat { top: 31%; left: 61%; width: 18%; }
        .flow.water-flow { top: 72%; left: 61%; width: 18%; }
        .twin[data-state="active"] .flow.electric, .twin[data-state="defrost"] .flow.electric,
        .twin[data-state="active"][data-operation="heating"] .flow.heat,
        .twin[data-state="active"][data-operation="tapwater"] .flow.water-flow,
        .twin[data-state="active"][data-operation="both"] .flow.heat,
        .twin[data-state="active"][data-operation="both"] .flow.water-flow { opacity: 1; }
        .flow-label { display: none; }
        .timeline-segment.unknown { background: #8c9fb3; }
        .timeline-segment.both { background: linear-gradient(90deg, var(--cyan), var(--warm)); }
        .lower-grid { grid-template-columns: minmax(0, 1fr); }
        .kpi-value { overflow-wrap: normal; }
        .kpi:nth-child(-n+2) .kpi-value { color: #fff; font-size: clamp(1.5rem, 2.5vw, 2.2rem); }
        .live-badge.active::before, .live-badge.defrost::before { animation: status-glow 2.5s ease-in-out infinite; }
        @keyframes energy-flow { from { transform: translateX(-100%); } to { transform: translateX(300%); } }
        @keyframes status-glow { 50% { opacity: .5; box-shadow: 0 0 3px currentColor; } }
        [hidden] { display: none !important; }
        @media (max-width: 1050px) {
            .layout { grid-template-columns: 78px minmax(0, 1fr); }
            .side-nav { align-items: center; padding-inline: .5rem; }
            .brand-name, .nav-label, .nav-caption { display: none; }
            .nav-links a { font-size: 1.1rem; padding: .8rem; }
            .overview-grid { grid-template-columns: minmax(0, 1fr); }
            .context { min-height: auto; }
            .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 650px) {
            .layout { display: block; }
            .side-nav { display: none; }
            main { padding: 1.2rem .9rem calc(6rem + env(safe-area-inset-bottom)); }
            .topbar { margin-bottom: 1rem; }
            .live-badge { font-size: .72rem; padding: .45rem .6rem; }
            .overview-grid, .lower-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: .8rem; }
            .hero { padding: 1rem; border-radius: 1rem; }
            .hero-heading { align-items: flex-start; }
            .updated { font-size: .7rem; }
            .twin { min-height: 310px; grid-template-columns: 1fr 1.2fr 1fr; grid-template-rows: 1fr 1fr; gap: 0; margin: 1rem 0; }
            .twin::before { inset: 10% 5%; }
            .node { min-height: 95px; padding: .7rem; border-radius: .8rem; }
            .node.outdoor { grid-column: 1; grid-row: 1; }
            .node.pump { grid-column: 2; grid-row: 1 / 3; min-height: 130px; }
            .node.home { grid-column: 3; grid-row: 1; }
            .node.water { grid-column: 3; grid-row: 2; }
            .outdoor-unit-illustration { width: 78px; margin-bottom: .45rem; }
            .home-illustration { width: min(100%, 88px); margin: .2rem auto; }
            .boiler-symbol { transform: scale(.7); margin: -10px 0 -8px; }
            .node { padding: .2rem; }
            .node-value, .node.pump .node-value { font-size: .83rem; }
            .node-label { font-size: .68rem; }
            .flow-label { display: none; }
            .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
            .kpi { padding: .75rem; }
            .kpi-value, .kpi:nth-child(-n+2) .kpi-value { font-size: clamp(1rem, 4.5vw, 1.35rem); overflow-wrap: anywhere; }
            .context.details-open { position: fixed; z-index: 10; inset: auto .6rem calc(4.7rem + env(safe-area-inset-bottom)) .6rem; border-radius: 1rem; box-shadow: 0 20px 70px #000b; }
            .context.details-open .context-close { display: inline-flex; float: right; border: 0; background: transparent; color: var(--muted); padding: .3rem; cursor: pointer; }
            .card, .timeline { padding: 1rem; border-radius: 1rem; }
            .timeline-controls { gap: .5rem; }
            .timeline time { min-width: 5.5rem; font-size: .75rem; }
            .mobile-nav { position: fixed; z-index: 9; inset: auto 0 0; display: grid; grid-template-columns: repeat(4, 1fr); padding: .45rem .35rem calc(.45rem + env(safe-area-inset-bottom)); border-top: 1px solid var(--line); background: #091422ed; backdrop-filter: blur(18px); }
            .mobile-nav a { text-align: center; font-size: .67rem; padding: .5rem .15rem; }
            .mobile-nav a[aria-current="page"] { color: #eef4ff; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="side-nav">
        <a class="brand" href="index.php"><span class="brand-mark">N</span><span class="brand-name">NIBE Energy</span></a>
        <nav class="nav-links" aria-label="Hoofdnavigatie">
            <a href="index.php" aria-current="page">⌂ <span class="nav-label">Overzicht</span></a>
            <a href="#energy">◉ <span class="nav-label">Energie</span></a>
            <a href="index_old.php#performance-title">⌁ <span class="nav-label">Analyse</span></a>
            <a href="index_old.php#measurement-list">⚙ <span class="nav-label">Technisch</span></a>
        </nav>
        <p class="nav-caption">Alle waarden komen uit de verzamelde installatiegegevens.</p>
    </aside>
    <main>
        <header class="topbar">
            <div>
                <div class="eyebrow">Energie · Overzicht</div>
                <h1><?= nibe_escape($installationLabel) ?></h1>
                <p class="subtitle">In één oogopslag wat je warmtepomp doet.</p>
            </div>
            <span class="live-badge <?= nibe_escape($statusClass) ?>"><?= nibe_escape($systemStatus) ?></span>
        </header>
        <?php if (!$databaseReady): ?>
            <p class="notice" role="alert">De database is nog niet beschikbaar. Controleer de configuratie en installatie-instructies.</p>
        <?php elseif ($measurements === []): ?>
            <p class="notice" role="status">Er zijn nog geen meetpunten of metingen beschikbaar.</p>
        <?php endif; ?>
        <div class="overview-grid">
            <section class="hero" aria-labelledby="system-title">
                <div class="hero-heading">
                    <h2 id="system-title">Systeemoverzicht</h2>
                    <span class="updated"><?php if (is_string($lastUpdate) && $lastUpdate !== ''): ?>Compressor bijgewerkt <time class="local-time" datetime="<?= nibe_escape($lastUpdate) ?>">tijd laden…</time><?php else: ?>Actuele metingen<?php endif; ?></span>
                </div>
                <div class="twin" data-state="<?= nibe_escape($statusClass) ?>" data-operation="unknown" aria-label="Interactief systeemmodel">
                    <span class="flow electric" aria-hidden="true"></span>
                    <span class="flow heat" aria-hidden="true"></span>
                    <span class="flow water-flow" aria-hidden="true"></span>
                    <span class="flow-label electric-label">Elektriciteit <span data-reading="electric_power"><?= $electricKw === null ? '—' : nibe_escape($formatNumber($electricKw, 2) . ' kW') ?></span></span>
                    <span class="flow-label heat-label">Warmte <span data-reading="heat_power"><?= $heatKw === null ? '—' : nibe_escape($formatNumber($heatKw, 2) . ' kW') ?></span></span>
                    <span class="flow-label water-label">Tapwater</span>
                    <button class="node outdoor" data-panel="buitenunit" data-target="index_old.php#chart-title">
                        <span class="node-icon" aria-hidden="true">☼</span><span class="node-label">Buitenlucht</span>
                        <span class="node-value" data-reading="outdoor"><?= nibe_escape($formatReading('outdoor')) ?><?= $readingIndicator('outdoor') ?></span>
                    </button>
                    <button class="node pump" data-panel="warmtepomp" data-target="index_old.php#performance-title">
                        <svg class="outdoor-unit-illustration" viewBox="0 0 160 128" aria-hidden="true" focusable="false">
                            <path d="M22 18h105l14 9v82l-14 8H22a7 7 0 0 1-7-7V25a7 7 0 0 1 7-7Z" fill="#d4d9d7" stroke="#f0f3f0" stroke-width="2"/>
                            <path d="m127 18 14 9v82l-14 8Z" fill="#aab5b5"/>
                            <path d="M24 24h7v84h-7z" fill="#eef0ec" opacity=".8"/>
                            <circle class="unit-fan" cx="77" cy="63" r="36" fill="#283940" stroke="#78888a" stroke-width="4"/>
                            <circle cx="77" cy="63" r="29" fill="#344950" stroke="#a9b5b3" stroke-width="2"/>
                            <g stroke="#829294" stroke-width="2" opacity=".85">
                                <path d="m77 36v54m-27-27h54M58 44l38 38m0-38L58 82"/>
                            </g>
                            <circle cx="77" cy="63" r="7" fill="#aeb9b6"/>
                            <rect x="119" y="46" width="5" height="13" rx="1" fill="#718083"/>
                            <text x="114" y="94" fill="#52656a" font-size="7" font-weight="700" letter-spacing="1">NIBE</text>
                            <path d="M32 117v5m83-5v5" stroke="#596b70" stroke-width="4" stroke-linecap="round"/>
                        </svg><span class="node-label">Warmtepomp</span>
                        <span class="node-value"><?= nibe_escape($systemStatus) ?></span>
                        <span class="node-label">Compressor <span data-reading="frequency"><?= nibe_escape($formatReading('frequency')) ?><?= $readingIndicator('frequency') ?></span></span>
                    </button>
                    <button class="node home" data-panel="woning" data-target="index_old.php#chart-title">
                        <svg class="home-illustration" viewBox="0 0 160 130" aria-hidden="true" focusable="false">
                            <path d="M61 52 102 24l48 30v65H61Z" fill="#a96e4f" stroke="#d3a078" stroke-width="2"/>
                            <path d="m52 54 49-36 54 34-7 9-47-29-43 32Z" fill="#35414a" stroke="#69747a" stroke-width="2"/>
                            <path d="m69 46 31-23 39 23-3 13-38-24-27 21Z" fill="#263d4d" stroke="#8ba1a5" stroke-width="2"/>
                            <g class="solar-panel" fill="none" stroke="#92b9c9" stroke-width="1">
                                <path d="m77 42 23-17m-12 20 24-17m-12 20 23-17M74 42l40 7m-28-15 41 7"/>
                            </g>
                            <path d="M113 20v-9h9v16" fill="#b9a58f" stroke="#e1d0b6" stroke-width="2"/>
                            <path d="M74 65h20v22H74z" fill="#344c56" stroke="#d4a47b" stroke-width="2"/>
                            <path d="M77 68h14v16H77z" fill="#b4ccd0"/>
                            <path class="front-window" d="M100 72h38v38h-38z" fill="#172b32" stroke="#d3a078" stroke-width="3"/>
                            <path d="M119 74v34m-17-17h34" stroke="#8ba4a3" stroke-width="2"/>
                            <g stroke="#8b583e" stroke-width="1" opacity=".75">
                                <path d="M62 64h36m-36 9h36m-36 9h36m-36 9h36m-36 9h36m-36 9h36"/>
                                <path d="M72 61v3m14 0v9m-14 0v9m14 0v9m-14 0v9m14 0v9"/>
                            </g>
                            <path d="M27 8v108" stroke="#a8b2b1" stroke-width="2"/>
                            <path d="M29 12h10v45H29z" fill="#e24f42"/>
                            <path d="M29 12h10v15H29z" fill="#527eae"/>
                            <path d="M29 27h10v15H29z" fill="#f3f0d9"/>
                            <path d="M29 42h10v15H29z" fill="#4a8f69"/>
                            <path d="M44 117h108" stroke="#68866c" stroke-width="3"/>
                            <g fill="#4e7758">
                                <circle cx="58" cy="111" r="9"/><circle cx="67" cy="114" r="7"/>
                                <circle cx="143" cy="111" r="11"/><circle cx="134" cy="115" r="8"/>
                            </g>
                            <g fill="#94a86f">
                                <circle cx="53" cy="110" r="3"/><circle cx="143" cy="108" r="3"/><circle cx="136" cy="113" r="2"/>
                            </g>
                        </svg><span class="node-label">Woning</span>
                        <span class="node-value" data-reading="indoor"><?= nibe_escape($formatReading('indoor')) ?><?= $readingIndicator('indoor') ?></span>
                    </button>
                    <button class="node water" data-panel="tapwater" data-target="index_old.php#chart-title">
                        <span class="boiler-symbol" aria-hidden="true"><span></span></span><span class="node-label">Tapwater</span>
                        <span class="node-value" data-reading="hot_water"><?= nibe_escape($formatReading('hot_water')) ?><?= $readingIndicator('hot_water') ?></span>
                    </button>
                </div>
                <div class="kpis" id="energy">
                    <div class="kpi"><div class="kpi-label">COP nu</div><div class="kpi-value" data-reading="cop"><?= nibe_escape($formatNumber($currentCop, 2)) ?></div></div>
                    <div class="kpi"><div class="kpi-label">Warmtevermogen<?= $readingIndicator('heat_power') ?></div><div class="kpi-value" data-reading="heat_power"><?= $heatKw === null ? 'Niet beschikbaar' : nibe_escape($formatNumber($heatKw, 2)) ?> <small><?= $heatKw === null ? '' : 'kW' ?></small></div></div>
                    <div class="kpi"><div class="kpi-label">Binnentemperatuur<?= $readingIndicator('indoor') ?></div><div class="kpi-value" data-reading="indoor"><?= nibe_escape($formatReading('indoor')) ?></div></div>
                    <div class="kpi"><div class="kpi-label">Elektrisch vermogen<?= $readingIndicator('electric_power') ?></div><div class="kpi-value" data-reading="electric_power"><?= $electricKw === null ? 'Niet beschikbaar' : nibe_escape($formatNumber($electricKw, 2)) ?> <small><?= $electricKw === null ? '' : 'kW' ?></small></div></div>
                </div>
                <div id="reading-data" hidden data-point-ids="<?= nibe_escape(json_encode($measurementIds, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>" data-cycles="<?= nibe_escape(json_encode(array_values(array_filter($timelineSegments, static fn (array $segment): bool => isset($segment['start']))), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"></div>
            </section>
            <aside class="context" id="context-panel" aria-live="polite">
                <button class="context-close" id="context-close" type="button" aria-label="Paneel sluiten">✕</button>
                <h2 id="context-title">Inzichten uit je systeem</h2>
                <div class="context-content" id="context-content">
                    <?php if ($availableInsights === []): ?>
                        <p>Er zijn nog geen onderbouwde inzichten beschikbaar. Meer meetgegevens kunnen nieuwe inzichten opleveren.</p>
                    <?php else: ?>
                        <ul class="insights">
                            <?php foreach ($availableInsights as $insight): ?>
                                <li><strong><?= nibe_escape($insight['title']) ?></strong><p><?= nibe_escape($insight['evidence']) ?></p></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <a class="context-link" id="context-link" href="index_old.php#performance-title">Bekijk analyse →</a>
            </aside>
        </div>
        <div class="lower-grid">
            <section class="card" aria-labelledby="events-title">
                <div class="section-heading"><h2 id="events-title">Laatste gebeurtenissen</h2><a href="index_old.php#performance-title">Meer →</a></div>
                <?php if ($eventItems !== []): ?>
                    <ul class="events">
                        <?php foreach ($eventItems as $event): ?>
                            <li><time class="local-time" datetime="<?= gmdate('Y-m-d H:i:s', $event['time']) ?>">Tijd laden…</time><strong><?= nibe_escape($event['label']) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="empty-events">Er zijn geen recente compressorcycli beschikbaar in de verzamelde historie.</p>
                <?php endif; ?>
            </section>
            <section class="card weather-card" aria-labelledby="weather-title">
                <div class="section-heading"><h2 id="weather-title">Weer in Grootegast</h2><span class="eyebrow">Open-Meteo</span></div>
                <?php if ($weather === null): ?>
                    <p class="empty-events">Weerdata wordt zichtbaar na de eerste geslaagde verzameling.</p>
                <?php else: ?>
                    <div class="weather-summary">
                        <span class="weather-symbol" aria-hidden="true"><?= nibe_escape($weatherSymbol) ?></span>
                        <div>
                            <div class="weather-temperature"><?= nibe_escape(number_format($weather['temperature'], 1, ',', '') . ' ' . $weather['temperature_unit']) ?></div>
                            <div class="weather-condition"><?= nibe_escape(nibe_dashboard_weather_condition($weather['weather_code'])) ?></div>
                        </div>
                    </div>
                    <div class="weather-details">
                        <div class="weather-detail">Gevoel<strong><?= nibe_escape(number_format($weather['apparent_temperature'], 1, ',', '') . ' ' . $weather['temperature_unit']) ?></strong></div>
                        <div class="weather-detail">Luchtvochtigheid<strong><?= nibe_escape(number_format($weather['humidity'], 0, ',', '') . $weather['humidity_unit']) ?></strong></div>
                        <div class="weather-detail">Wind<strong><?= nibe_escape(number_format($weather['wind_speed'], 1, ',', '') . ' ' . $weather['wind_speed_unit']) ?></strong></div>
                    </div>
                    <p class="weather-updated">Neerslag <?= nibe_escape(number_format($weather['precipitation'], 1, ',', '') . ' ' . $weather['precipitation_unit']) ?>
                        · Waargenomen <time class="local-time" datetime="<?= nibe_escape($weather['timestamp']) ?>">tijd laden…</time></p>
                <?php endif; ?>
            </section>
        </div>
        <section class="timeline" aria-labelledby="timeline-title">
            <div class="section-heading"><h2 id="timeline-title">Tijdmachine</h2><time id="timeline-time" class="local-time" datetime="<?= gmdate('Y-m-d H:i:s', $now->getTimestamp()) ?>">Tijd laden…</time></div>
            <div class="timeline-track" role="img" aria-label="Gemeten verwarmings-, tapwater-, defrost- en bijverwarmingsmomenten in de afgelopen 24 uur">
                <?php foreach ($timelineSegments as $segment): ?>
                    <span class="timeline-segment <?= nibe_escape($segment['kind']) ?>"
                          style="left:<?= nibe_escape(number_format($segment['left'], 4, '.', '')) ?>%;width:<?= nibe_escape(number_format($segment['width'], 4, '.', '')) ?>%"
                          title="<?= nibe_escape($segment['label']) ?>"></span>
                <?php endforeach; ?>
                <?php foreach ($defrostStartTimes as $defrostTime): ?>
                    <span class="timeline-segment defrost"
                          style="left:<?= nibe_escape(number_format(100 * ($defrostTime - $timelineStart) / 86400, 4, '.', '')) ?>%"
                          title="Defrost waargenomen"></span>
                <?php endforeach; ?>
            </div>
            <div class="timeline-controls">
                <span class="eyebrow">24 uur geleden</span>
                <input id="timeline-slider" type="range" min="<?= $now->getTimestamp() - 86400 ?>" max="<?= $now->getTimestamp() ?>" value="<?= $now->getTimestamp() ?>" step="300" aria-label="Bekijk historische installatiewaarden">
                <span class="eyebrow">Nu</span>
            </div>
            <p id="timeline-status" class="empty-events" role="status" aria-live="polite"></p>
            <ul class="legend" aria-label="Legenda">
                <li><i></i>Verwarming</li><li><i></i>Tapwater</li><li><i></i>Defrost</li><li><i></i>Bijverwarming</li>
            </ul>
        </section>
    </main>
</div>
<nav class="mobile-nav" aria-label="Navigatie">
    <a href="index.php" aria-current="page">⌂<br>Home</a>
    <a href="#energy">◉<br>Energie</a>
    <a href="index_old.php#performance-title">⌁<br>Analyse</a>
    <a href="index_old.php#measurement-list">⚙<br>Technisch</a>
</nav>
<script src="dashboard.js" defer></script>
<script src="dashboard-home.js" defer></script>
</body>
</html>
