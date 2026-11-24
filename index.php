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
        'timestamp' => $timestamp,
    ];
}
$formatNumber = static fn (?float $value, int $digits = 1): string => $value === null
    ? 'Niet beschikbaar'
    : number_format($value, $digits, ',', '.');
$formatReading = static function (string $role, int $digits = 1) use ($readings, $formatNumber): string {
    $reading = $readings[$role] ?? null;
    if ($reading === null || !$reading['fresh'] || $reading['value'] === null) {
        return 'Niet beschikbaar';
    }
    return $formatNumber($reading['value'], $digits) . ($reading['unit'] !== '' ? ' ' . $reading['unit'] : '');
};
$powerKw = static function (string $role) use ($readings): ?float {
    $reading = $readings[$role] ?? null;
    if ($reading === null || !$reading['fresh'] || $reading['value'] === null) {
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
$systemStatus = 'Status niet beschikbaar';
if ($frequency !== null && $frequency['fresh'] && $frequency['value'] !== null && $frequency['value'] >= 0) {
    $systemStatus = $defrost !== null && $defrost['fresh'] && $defrost['value'] > 0
        ? 'Ontdooien'
        : ($frequency['value'] > 0 ? 'Verwarming actief' : 'Stand-by');
}
$statusClass = match ($systemStatus) {
    'Verwarming actief' => 'active',
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
$timelineStart = $now->getTimestamp() - 86400;
$timelineSegments = [];
foreach ($performance['cycles'] ?? [] as $cycle) {
    if ($cycle['end'] < $timelineStart || $cycle['start'] > $now->getTimestamp()) {
        continue;
    }
    $operation = (string) ($cycle['operation'] ?? '');
    $kind = stripos($operation, 'warm water') !== false || stripos($operation, 'tapwater') !== false
        ? 'tapwater'
        : 'heating';
    $start = max($timelineStart, (int) $cycle['start']);
    $end = min($now->getTimestamp(), (int) $cycle['end']);
    $timelineSegments[] = [
        'kind' => $kind,
        'left' => 100 * ($start - $timelineStart) / 86400,
        'width' => max(0.35, 100 * ($end - $start) / 86400),
        'label' => $operation !== '' ? $operation : 'Compressorcyclus',
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
$lastDefrostSeconds = $performance['defrost_periods']['24h']['since_last_seconds'] ?? null;
$lastDefrostTime = is_numeric($lastDefrostSeconds) && $lastDefrostSeconds >= 0 && $lastDefrostSeconds <= 86400
    ? $now->getTimestamp() - (int) $lastDefrostSeconds
    : null;
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
        .lower-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(260px, .75fr); gap: 1rem; margin-top: 1rem; }
        .card, .timeline { padding: 1.25rem; }
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
        [hidden] { display: none !important; }
        @media (max-width: 1050px) {
            .layout { grid-template-columns: 78px minmax(0, 1fr); }
            .side-nav { align-items: center; padding-inline: .5rem; }
            .brand-name, .nav-label, .nav-caption { display: none; }
            .nav-links a { font-size: 1.1rem; padding: .8rem; }
            .overview-grid { grid-template-columns: minmax(0, 1fr); }
            .context { min-height: auto; }
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
            .twin { min-height: 300px; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: .6rem; margin: 1rem 0; }
            .twin::before { inset: 10% 5%; }
            .node { min-height: 95px; padding: .7rem; border-radius: .8rem; }
            .node.outdoor { grid-column: 1; grid-row: 1; }
            .node.pump { grid-column: 2; grid-row: 1; min-height: 130px; }
            .node.home { grid-column: 1; grid-row: 2; }
            .node.water { grid-column: 2; grid-row: 2; }
            .pump-symbol { width: 46px; height: 46px; border-radius: .9rem; font-size: 1.5rem; margin-bottom: .45rem; }
            .node-value { font-size: 1rem; }
            .node.pump .node-value { font-size: 1.08rem; }
            .flow { display: none; }
            .flow-label { display: none; }
            .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
            .kpi { padding: .75rem; }
            .kpi-value { font-size: 1.35rem; }
            .context { position: fixed; z-index: 10; inset: auto .6rem calc(4.7rem + env(safe-area-inset-bottom)) .6rem; border-radius: 1rem; box-shadow: 0 20px 70px #000b; }
            .context-close { display: inline-flex; float: right; border: 0; background: transparent; color: var(--muted); padding: .3rem; cursor: pointer; }
            .card, .timeline { padding: 1rem; border-radius: 1rem; }
            .timeline-controls { gap: .5rem; }
            .timeline time { min-width: 5.5rem; font-size: .75rem; }
            .mobile-nav { position: fixed; z-index: 9; inset: auto 0 0; display: grid; grid-template-columns: repeat(4, 1fr); padding: .45rem .35rem calc(.45rem + env(safe-area-inset-bottom)); border-top: 1px solid var(--line); background: #091422ed; backdrop-filter: blur(18px); }
            .mobile-nav a { text-align: center; font-size: .67rem; padding: .5rem .15rem; }
            .mobile-nav a[aria-current="page"] { color: #eef4ff; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; }
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
                    <span class="updated"><?= $now->format('H:i') ?> · <?= nibe_escape($systemStatus) ?></span>
                </div>
                <div class="twin" aria-label="Interactief systeemmodel">
                    <span class="flow electric" aria-hidden="true"></span>
                    <span class="flow heat" aria-hidden="true"></span>
                    <span class="flow water-flow" aria-hidden="true"></span>
                    <span class="flow-label electric-label">Elektriciteit <span data-reading="electric_power"><?= $electricKw === null ? '—' : nibe_escape($formatNumber($electricKw, 2) . ' kW') ?></span></span>
                    <span class="flow-label heat-label">Warmte <span data-reading="heat_power"><?= $heatKw === null ? '—' : nibe_escape($formatNumber($heatKw, 2) . ' kW') ?></span></span>
                    <span class="flow-label water-label">Comfort</span>
                    <button class="node outdoor" data-panel="buitenunit" data-target="index_old.php#chart-title">
                        <span class="node-icon" aria-hidden="true">☼</span><span class="node-label">Buitenlucht</span>
                        <span class="node-value" data-reading="outdoor"><?= nibe_escape($formatReading('outdoor')) ?></span>
                    </button>
                    <button class="node pump" data-panel="warmtepomp" data-target="index_old.php#performance-title">
                        <span class="pump-symbol" aria-hidden="true">⌁</span><span class="node-label">Warmtepomp</span>
                        <span class="node-value"><?= nibe_escape($systemStatus) ?></span>
                        <span class="node-label">Compressor <span data-reading="frequency"><?= nibe_escape($formatReading('frequency')) ?></span></span>
                    </button>
                    <button class="node home" data-panel="woning" data-target="index_old.php#chart-title">
                        <span class="node-icon" aria-hidden="true">⌂</span><span class="node-label">Woning</span>
                        <span class="node-value" data-reading="indoor"><?= nibe_escape($formatReading('indoor')) ?></span>
                    </button>
                    <button class="node water" data-panel="tapwater" data-target="index_old.php#chart-title">
                        <span class="node-icon" aria-hidden="true">◌</span><span class="node-label">Tapwater</span>
                        <span class="node-value" data-reading="hot_water"><?= nibe_escape($formatReading('hot_water')) ?></span>
                    </button>
                </div>
                <div class="kpis" id="energy">
                    <div class="kpi"><div class="kpi-label">COP nu</div><div class="kpi-value" data-reading="cop"><?= nibe_escape($formatNumber($currentCop, 2)) ?></div></div>
                    <div class="kpi"><div class="kpi-label">Warmtevermogen</div><div class="kpi-value" data-reading="heat_power"><?= $heatKw === null ? 'Niet beschikbaar' : nibe_escape($formatNumber($heatKw, 2)) ?> <small><?= $heatKw === null ? '' : 'kW' ?></small></div></div>
                    <div class="kpi"><div class="kpi-label">Elektrisch vermogen</div><div class="kpi-value" data-reading="electric_power"><?= $electricKw === null ? 'Niet beschikbaar' : nibe_escape($formatNumber($electricKw, 2)) ?> <small><?= $electricKw === null ? '' : 'kW' ?></small></div></div>
                    <div class="kpi"><div class="kpi-label">Compressorfrequentie</div><div class="kpi-value"><span data-reading="frequency"><?= nibe_escape($formatReading('frequency')) ?></span></div></div>
                </div>
                <div id="reading-data" hidden data-point-ids="<?= nibe_escape(json_encode($measurementIds, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"></div>
            </section>
            <aside class="context" id="context-panel" aria-live="polite">
                <button class="context-close" id="context-close" type="button" aria-label="Paneel sluiten">✕</button>
                <h2 id="context-title">Systeemstatus</h2>
                <div class="context-content" id="context-content">
                    <p><strong><?= nibe_escape($systemStatus) ?></strong></p>
                    <p>De status is gebaseerd op de meest recente compressor- en defrostmetingen.</p>
                    <?php if ($performance['cop_now'] !== null): ?>
                        <p>Gemeten actuele COP: <?= nibe_escape($formatNumber((float) $performance['cop_now'], 2)) ?></p>
                    <?php endif; ?>
                </div>
                <a class="context-link" id="context-link" href="index_old.php#performance-title">Bekijk analyse →</a>
            </aside>
        </div>
        <div class="lower-grid">
            <?php if ($availableInsights !== []): ?>
                <section class="card" aria-labelledby="insights-title">
                    <div class="section-heading"><h2 id="insights-title">Opvallend</h2><a href="index_old.php#suggestions-title">Alle analyse →</a></div>
                    <ul class="insights">
                        <?php foreach ($availableInsights as $insight): ?>
                            <li><strong><?= nibe_escape($insight['title']) ?></strong><p><?= nibe_escape($insight['evidence']) ?></p></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
            <section class="card" aria-labelledby="events-title">
                <div class="section-heading"><h2 id="events-title">Laatste gebeurtenissen</h2><a href="index_old.php#performance-title">Meer →</a></div>
                <?php if ($eventItems !== []): ?>
                    <ul class="events">
                        <?php foreach ($eventItems as $event): ?>
                            <li><time class="local-time" datetime="<?= gmdate('Y-m-d H:i:s', $event['time']) ?>"><?= gmdate('H:i', $event['time']) ?></time><strong><?= nibe_escape($event['label']) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="empty-events">Er zijn geen recente compressorcycli beschikbaar in de verzamelde historie.</p>
                <?php endif; ?>
            </section>
        </div>
        <section class="timeline" aria-labelledby="timeline-title">
            <div class="section-heading"><h2 id="timeline-title">Tijdmachine</h2><time id="timeline-time"><?= $now->format('H:i') ?></time></div>
            <div class="timeline-track" role="img" aria-label="Gemeten verwarmings-, tapwater-, defrost- en bijverwarmingsmomenten in de afgelopen 24 uur">
                <?php foreach ($timelineSegments as $segment): ?>
                    <span class="timeline-segment <?= nibe_escape($segment['kind']) ?>"
                          style="left:<?= nibe_escape(number_format($segment['left'], 4, '.', '')) ?>%;width:<?= nibe_escape(number_format($segment['width'], 4, '.', '')) ?>%"
                          title="<?= nibe_escape($segment['label']) ?>"></span>
                <?php endforeach; ?>
                <?php if ($lastDefrostTime !== null): ?>
                    <span class="timeline-segment defrost"
                          style="left:<?= nibe_escape(number_format(100 * ($lastDefrostTime - $timelineStart) / 86400, 4, '.', '')) ?>%"
                          title="Defrost waargenomen"></span>
                <?php endif; ?>
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
