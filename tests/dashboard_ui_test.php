<?php

$directory = sys_get_temp_dir() . '/nibe-dashboard-' . bin2hex(random_bytes(8));
mkdir($directory);
mkdir($directory . '/app');
copy(__DIR__ . '/../index_old.php', $directory . '/index_old.php');
copy(__DIR__ . '/../index.php', $directory . '/index.php');
copy(__DIR__ . '/../app/dashboard.php', $directory . '/app/dashboard.php');
file_put_contents($directory . '/app/config.php', <<<'PHP'
<?php
function nibe_env(string $name, ?string $default = null): ?string
{
    return $name === 'NIBE_DASHBOARD_ROLE_MAP'
        ? '{"electric_power":"9","heat_power":"4","auxiliary_heat_power":"10","frequency":"5","supply":"1","return":"2","hot_water":"6","defrost":"11"}'
        : $default;
}

function nibe_database(): DashboardTestDatabase
{
    if (getenv('NIBE_TEST_DB_FAIL') === '1') {
        throw new RuntimeException('Simulated database error.');
    }
    return new DashboardTestDatabase();
}

class DashboardTestDatabase
{
    public function prepare(string $sql): DashboardTestStatement
    {
        return new DashboardTestStatement($sql);
    }
}

class DashboardTestStatement
{
    public function __construct(private string $sql) {}
    public function execute(array $parameters = []): void {}
    public function fetch(): array|false
    {
        if (str_contains($this->sql, 'FROM nibe_weather_observations') && getenv('NIBE_TEST_NO_WEATHER') !== '1') {
            return [
                'source_timestamp' => gmdate('Y-m-d H:i:s'),
                'raw_data' => json_encode([
                    'current' => [
                        'temperature_2m' => 8.5, 'relative_humidity_2m' => 85,
                        'apparent_temperature' => 6.2, 'precipitation' => 0.1,
                        'weather_code' => 61, 'wind_speed_10m' => 12.4,
                    ],
                    'current_units' => [
                        'temperature_2m' => '°C', 'relative_humidity_2m' => '%',
                        'apparent_temperature' => '°C', 'precipitation' => 'mm',
                        'weather_code' => 'wmo code', 'wind_speed_10m' => 'km/h',
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        }
        return false;
    }
    public function fetchAll(): array
    {
        $now = time();
        $device = 'emmy-n-20251117-06794225149019-fc-0f-e7-5e-ef-32';
        $points = [
            ['1', '44058', '40', '°C', $now - 600],
            ['2', '44055', '35', '°C', $now - 295],
            ['3', '999', '2', 'kW<script>', $now - 300],
            ['4', '598', '3', 'kW', $now - 901],
            ['5', '44701', '0', 'Hz', $now - 300],
            ['6', '40013', '46', '°C', $now - 300],
            ['9', '998', '1000', 'W', $now - 300],
            ['10', '43084', '0', 'kW', $now - 300],
            ['11', '44703', '0', '', $now - 300],
            ['12', '40941', '-180', 'GM', $now - 300],
            ['13', '44363', '-2.3', '°C', $now - 300],
            ['14', '44362', '0', '°C', $now - 300],
        ];
        if (getenv('NIBE_TEST_ACTIVE_CYCLE') === '1' || getenv('NIBE_TEST_ACTIVE_UNKNOWN') === '1') {
            $points[4][2] = '25';
        }
        if (getenv('NIBE_TEST_DEFROST') === '1') {
            $points[4][4] = $now - 3600;
            $points[8][2] = '1';
        }
        if (getenv('NIBE_TEST_STALE_READING') === '1') {
            $points[4][4] = $now - 3600;
        } elseif (getenv('NIBE_TEST_BOUNDARY_READING') === '1') {
            $points[4][4] = $now - 86400;
        } elseif (getenv('NIBE_TEST_EXPIRED_READING') === '1') {
            $points[4][4] = $now - 86401;
        }
        if (str_contains($this->sql, 'FROM nibe_measurement_points')) {
            if (getenv('NIBE_TEST_EMPTY_DATA') === '1') {
                return [];
            }
            return array_map(static fn (array $point): array => [
                'measurement_point_id' => $point[0], 'source_point_id' => $point[1],
                'raw_value' => $point[2], 'unit' => $point[3],
                'source_timestamp' => gmdate('Y-m-d H:i:s', $point[4]),
                'collected_at' => gmdate('Y-m-d H:i:s', $point[4]),
                'source_device_id' => $device, 'device_name' => 'Test', 'name' => 'Test',
            ], $points);
        }
        if (str_contains($this->sql, 'measurement_point_id IN')) {
            $cycleStart = $now - 3600;
            $events = [
                ['5', '0', $cycleStart - 300],
                ['5', '20', $cycleStart],
                ['5', '20', $cycleStart + 300],
                ['5', '0', $cycleStart + 600],
                ['1', '40', $cycleStart],
                ['1', '42', $cycleStart + 300],
                ['2', '35', $cycleStart],
                ['2', '36', $cycleStart + 300],
                ['6', '45', $cycleStart],
                ['6', '47', $cycleStart + 300],
                ['12', '-160', $cycleStart - 300],
                ['12', '-220', $cycleStart],
                ['12', '-200', $cycleStart + 300],
                ['12', '-180', $now - 300],
                ['9', '1000', $cycleStart],
                ['9', '1000', $cycleStart + 300],
                ['9', '1000', $cycleStart + 600],
                ['10', '0', $cycleStart - 300],
                ['10', '2', $cycleStart],
                ['10', '0', $cycleStart + 300],
                ['10', '0', $now - 300],
                ['4', '4', $cycleStart],
                ['4', '4', $cycleStart + 300],
                ['4', '4', $cycleStart + 600],
                ['4', '4', $now - 2800],
                ['4', '4', $now - 2500],
                ['4', '4', $now - 2200],
                ['4', '4', $now - 1900],
                ['1', '40', $now - 600],
                ['2', '35', $now - 295],
                ['9', '1000', $now - 300],
                ['9', '1000', $now - 2800],
                ['9', '1000', $now - 2500],
                ['9', '1000', $now - 2200],
                ['9', '1000', $now - 1900],
                ['4', '3', $now - 901],
                ['14', '0', $cycleStart],
                ['14', '0', $cycleStart + 300],
                ['14', '0', $cycleStart + 600],
                ['14', '0', $now - 2800],
                ['14', '0', $now - 2500],
                ['14', '0', $now - 2200],
                ['14', '0', $now - 1900],
            ];
            if (getenv('NIBE_TEST_ACTIVE_CYCLE') === '1') {
                $events[] = ['5', '20', $now - 600];
                $events[] = ['5', '25', $now - 300];
                $events[] = ['2', '35', $now - 600];
            }
            if (getenv('NIBE_TEST_LONG_CYCLE') === '1') {
                $events = array_values(array_filter($events, static fn (array $event): bool => $event[0] !== '5'));
                $events[] = ['5', '0', $now - 25 * 3600 - 300];
                for ($time = $now - 25 * 3600; $time <= $now; $time += 3600) {
                    $events[] = ['5', '20', $time];
                }
            }
            $defrostStarts = [];
            for ($defrost = 0; $defrost < 12; $defrost++) {
                $defrostStarts[] = $now - (23 - 2 * $defrost) * 3600;
            }
            for ($time = $now - 86400 - 300; $time <= $now; $time += 300) {
                $active = false;
                foreach ($defrostStarts as $start) {
                    if ($time >= $start && $time < $start + 600) {
                        $active = true;
                        break;
                    }
                }
                $events[] = ['11', $active ? '1' : '0', $time];
            }
            return array_map(static fn (array $event): array => [
                'measurement_point_id' => $event[0], 'raw_value' => $event[1],
                'source_timestamp' => gmdate('Y-m-d H:i:s', $event[2]),
                'collected_at' => gmdate('Y-m-d H:i:s', $event[2]),
            ], $events);
        }
        return [];
    }
}
PHP);

try {
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });
    ob_start();
    include $directory . '/index_old.php';
    $html = ob_get_clean();
    restore_error_handler();

    $unavailable = substr_count($html, '<strong>Niet beschikbaar</strong>');
    $debugBlocks = preg_match_all('/<strong>Niet beschikbaar<\/strong>\s*<details class="debug"><summary>Toelichting<\/summary>/', $html);
    if ($unavailable === 0 || $debugBlocks !== $unavailable
        || substr_count($html, '<details class="debug"><summary>Toelichting</summary>') !== $unavailable
        || preg_match_all('/<details\b[^>]*class="debug"[^>]*>/', $html) !== $unavailable) {
        throw new RuntimeException('Each unavailable KPI must have exactly one collapsed debug disclosure; available KPIs must have none.');
    }
    if (!str_contains($html, 'Weer in Grootegast')
        || !str_contains($html, 'Regen')
        || !str_contains($html, '8,5 °C')) {
        throw new RuntimeException('The detailed dashboard must show stored Grootegast weather observations.');
    }
    if (!str_contains($html, 'kW&lt;script&gt;') || str_contains($html, 'kW<script>')) {
        throw new RuntimeException('Debug output must escape measurement units.');
    }
    if (!str_contains($html, '<th>COP</th>')
        || !str_contains($html, '<td>4,00</td>')
        || !str_contains($html, 'Warmtevraag en weersgenormaliseerd verbruik')
        || !str_contains($html, '200 W/K')
        || !str_contains($html, 'Elektriciteit per graaddag — trend laatste 30 dagen')
        || !str_contains($html, 'woningtype, grootte, ventilatie en bewonersgedrag')
        || !str_contains($html, 'Temperatuur verdamper')
        || !str_contains($html, 'data-point-id="12"')
        || !str_contains($html, 'Verwarming + Warm water')
        || !str_contains($html, 'Elektrische bijverwarming')
        || !str_contains($html, 'Elektriciteit bijverwarming 24 uur')
        || !str_contains($html, 'Toon 24-uursgrafiek')
        || str_contains($html, 'COP vereist elektrisch vermogen')
        || str_contains($html, 'Koppelingen voor prestatie-inzichten')) {
        throw new RuntimeException('Recent cycles must show COP and operating type, important readings must have chart toggles, and the obsolete explanation must be absent.');
    }
    if (!preg_match('/<div id="charts" data-points="([^"]+)"/', $html, $chartPoints)
        || !in_array('12', explode(',', $chartPoints[1]), true)) {
        throw new RuntimeException('The evaporator point must be included in the temperature chart.');
    }
    if (!preg_match('/<section class="suggestions"[^>]*>(.*?)<\/section>/s', $html, $suggestionsSection)
        || !str_contains($suggestionsSection[1], 'Suggesties op basis van meetdata')
        || !str_contains($suggestionsSection[1], 'inschakelmomenten waargenomen')
        || substr_count($suggestionsSection[1], '<li>') > 3) {
        throw new RuntimeException('The dashboard must show measurement-backed suggestions and never more than three.');
    }
    if (!str_contains($html, '+ Instelling vastleggen')
        || !str_contains($html, 'id="setting-dialog"')
        || !str_contains($html, 'name="old_value"')
        || !str_contains($html, 'name="new_value"')
        || !str_contains($html, 'name="changed_at"')) {
        throw new RuntimeException('The manual settings log must expose its add dialog and editable fields.');
    }
    if (!preg_match('/ΔT nu<strong>Niet beschikbaar<\/strong>(?:(?!<\/div>).)*✗ Tijdsverschil: 305 seconden/s', $html)
        || !preg_match('/COP nu<strong>Niet beschikbaar<\/strong>(?:(?!<\/div>).)*✗ Warmtevermogen: meting 90[1-9] seconden oud/s', $html)
        || !preg_match('/Warmtevermogen nu<strong>Niet beschikbaar<\/strong>(?:(?!<\/div>).)*✗ Meting is 90[1-9] seconden oud/s', $html)) {
        throw new RuntimeException('Current KPI debug blocks do not show their failed validation.');
    }
    file_put_contents($directory . '/render-home.php', '<?php include __DIR__ . (getenv(\'NIBE_TEST_DETAILED\') === \'1\' ? \'/index_old.php\' : \'/index.php\');');
    $renderHome = static function (?array $environment = null) use ($directory): array {
        $process = proc_open(
            [PHP_BINARY, $directory . '/render-home.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The visual dashboard could not be started.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [$output, $error, proc_close($process)];
    };
    [$homeHtml, $homeError, $exitCode] = $renderHome();
    if ($exitCode !== 0 || !str_contains($homeHtml, 'Energie · Overzicht')
        || !str_contains($homeHtml, 'Systeemoverzicht')
        || str_contains($homeHtml, 'Warmtevraag en weersgenormaliseerd verbruik')
        || !str_contains($homeHtml, 'id="timeline-slider"')
        || !str_contains($homeHtml, 'data-panel="warmtepomp"')
        || !str_contains($homeHtml, 'class="evaporator-icon"')
        || !str_contains($homeHtml, 'data-reading="evaporator">-2.3 °C')
        || !str_contains($homeHtml, 'COP nu')
        || !str_contains($homeHtml, 'id="degree-minutes-title"')
        || !str_contains($homeHtml, 'data-reading="degree_minutes">-180')
        || !str_contains($homeHtml, 'class="degree-chart-line"')
        || !str_contains($homeHtml, 'data-sample-count="4"')
        || str_contains($homeHtml, 'Meetpunt-ID:')
        || str_contains($homeHtml, 'Alle meetpunten (')
        || !str_contains($homeHtml, 'Niet beschikbaar')) {
        throw new RuntimeException('The default dashboard must show visual system controls and avoid exposing raw measurement lists: ' . $homeError);
    }
    if (!str_contains($homeHtml, 'class="node outdoor"')
        || !str_contains($homeHtml, 'aria-hidden="true">☂</span>')
        || !str_contains($homeHtml, 'data-weather-condition="Regen"')
        || !str_contains($homeHtml, 'data-weather-temperature="8,5 °C"')
        || !str_contains($homeHtml, 'data-weather-apparent="6,2 °C"')
        || !str_contains($homeHtml, 'data-weather-humidity="85%"')
        || !str_contains($homeHtml, 'data-weather-wind="12,4 km/h"')
        || !str_contains($homeHtml, 'data-weather-precipitation="0,1 mm"')
        || str_contains($homeHtml, 'weather-card')
        || str_contains($homeHtml, 'Weer in Grootegast')) {
        throw new RuntimeException('Weather must be integrated into the outdoor visual without a separate home-page card.');
    }
    $homeScript = file_get_contents(__DIR__ . '/../dashboard-home.js');
    if (!is_string($homeScript)
        || !str_contains($homeScript, "buitenunit: 'Weer in Grootegast'")
        || !str_contains($homeScript, 'contextContent.append(summary, weatherDetails, updated)')
        || !str_contains($homeScript, 'button.dataset.weatherTimestamp')) {
        throw new RuntimeException('Selecting the outdoor visual must expose the detailed weather values.');
    }
    if (substr_count($homeHtml, 'class="timeline-segment defrost"') !== 12) {
        throw new RuntimeException('The home timeline must show all 12 observed defrosts in the last 24 hours.');
    }
    [$staleHtml, $staleError, $staleExitCode] = $renderHome(['NIBE_TEST_STALE_READING' => '1']);
    if ($staleExitCode !== 0
        || !preg_match('/data-reading="frequency">0 Hz<span class="reading-status stale"[^>]*aria-label="Laatste waarde, ouder dan 15 minuten"/', $staleHtml)) {
        throw new RuntimeException('The dashboard must show the latest value with a stale indicator until it is more than 24 hours old: ' . $staleError);
    }
    [$boundaryHtml, $boundaryError, $boundaryExitCode] = $renderHome(['NIBE_TEST_BOUNDARY_READING' => '1']);
    if ($boundaryExitCode !== 0
        || !preg_match('/data-reading="frequency">0 Hz<span class="reading-status stale"[^>]*aria-label="Laatste waarde, ouder dan 15 minuten"/', $boundaryHtml)) {
        throw new RuntimeException('The dashboard must still show a measurement exactly 24 hours old: ' . $boundaryError);
    }
    [$expiredHtml, $expiredError, $expiredExitCode] = $renderHome(['NIBE_TEST_EXPIRED_READING' => '1']);
    if ($expiredExitCode !== 0
        || !preg_match('/data-reading="frequency">Niet beschikbaar<span class="reading-status unavailable"[^>]*aria-label="Laatste meting is ouder dan 24 uur"/', $expiredHtml)) {
        throw new RuntimeException('The dashboard must hide values older than 24 hours: ' . $expiredError);
    }
    if (!preg_match('/data-cycles="([^"]+)"/', $homeHtml, $cycleAttribute)) {
        throw new RuntimeException('Replay must include the observed compressor cycles.');
    }
    $replayCycles = json_decode(html_entity_decode($cycleAttribute[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
    if (count($replayCycles) !== 1 || $replayCycles[0]['kind'] !== 'both'
        || $replayCycles[0]['start'] >= $replayCycles[0]['end']
        || !str_contains($homeHtml, '<svg class="outdoor-unit-illustration"')
        || !str_contains($homeHtml, '<circle class="unit-fan"')
        || !str_contains($homeHtml, '<svg class="home-illustration"')
        || !str_contains($homeHtml, 'class="solar-panel"')
        || !str_contains($homeHtml, 'class="front-window"')
        || !str_contains($homeHtml, 'class="boiler-symbol"')
        || !str_contains($homeHtml, 'data-operation="unknown"')
        || !str_contains($homeHtml, 'Binnentemperatuur')
        || !str_contains($homeHtml, 'Inzichten uit je systeem')) {
        throw new RuntimeException('The dashboard must expose observed replay modes, product illustrations and evidence-based insights.');
    }
    [$activeHtml, $activeError, $activeExitCode] = $renderHome(['NIBE_TEST_ACTIVE_CYCLE' => '1']);
    if ($activeExitCode !== 0 || !str_contains($activeHtml, 'Compressorcyclus actief')
        || !preg_match('/data-cycles="([^"]+)"/', $activeHtml, $activeAttribute)) {
        throw new RuntimeException('The home dashboard must show an active compressor cycle: ' . $activeError);
    }
    $activeReplay = json_decode(html_entity_decode($activeAttribute[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
    if (count($activeReplay) !== 2 || abs(time() - $activeReplay[1]['end']) > 5) {
        throw new RuntimeException('The active replay cycle must extend to the current time.');
    }
    if ($activeReplay[1]['kind'] !== 'heating'
        || !str_contains($activeHtml, 'data-state="active" data-operation="heating"')) {
        throw new RuntimeException('The live animation must use the observed active cycle destination.');
    }
    [$unknownHtml, $unknownError, $unknownExitCode] = $renderHome(['NIBE_TEST_ACTIVE_UNKNOWN' => '1']);
    if ($unknownExitCode !== 0
        || !str_contains($unknownHtml, 'data-state="active" data-operation="unknown"')
        || !str_contains($unknownHtml, 'Bestemming warmtestroom onbekend')
        || !str_contains($unknownHtml, '.twin[data-state="active"][data-operation="unknown"] .flow.heat')
        || !str_contains($unknownHtml, '.twin[data-state="active"][data-operation="unknown"] .flow.water-flow')) {
        throw new RuntimeException('An active compressor with unknown destination must show an explicitly uncertain animation: ' . $unknownError);
    }
    [$defrostHtml, $defrostError, $defrostExitCode] = $renderHome(['NIBE_TEST_DEFROST' => '1']);
    if ($defrostExitCode !== 0
        || !str_contains($defrostHtml, 'data-state="defrost"')
        || !str_contains($defrostHtml, 'live-badge defrost">Ontdooien')
        || !str_contains($defrostHtml, '.twin[data-state="defrost"] .outdoor-unit-illustration')) {
        throw new RuntimeException('A fresh defrost must be visible even when the frequency is stale: ' . $defrostError);
    }
    [$detailedActiveHtml, $detailedActiveError, $detailedActiveExitCode] = $renderHome([
        'NIBE_TEST_ACTIVE_CYCLE' => '1', 'NIBE_TEST_DETAILED' => '1',
    ]);
    if ($detailedActiveExitCode !== 0 || !str_contains($detailedActiveHtml, '(actief)')) {
        throw new RuntimeException('The detailed dashboard must identify the provisional cycle end: ' . $detailedActiveError);
    }
    [$longActiveHtml, $longActiveError, $longActiveExitCode] = $renderHome([
        'NIBE_TEST_LONG_CYCLE' => '1', 'NIBE_TEST_DETAILED' => '1',
    ]);
    if ($longActiveExitCode !== 0 || !str_contains($longActiveHtml, '(actief)')) {
        throw new RuntimeException('The default detailed view must show an active cycle that started over 24 hours ago: ' . $longActiveError);
    }
    if (str_contains($html, 'Datum/tijd (UTC)') || str_contains($html, 'Datum en tijd (UTC)')
        || preg_match('/<time class="local-time"[^>]*>[^<]*UTC<\/time>/', $html)
        || !str_contains($html, 'Elektriciteit sinds <time class="local-time"')) {
        throw new RuntimeException('Dashboard time displays must use local-time placeholders and label the actual energy period.');
    }
    [$emptyHtml, $emptyError, $emptyExitCode] = $renderHome(['NIBE_TEST_EMPTY_DATA' => '1']);
    if ($emptyExitCode !== 0 || !str_contains($emptyHtml, 'Er zijn nog geen meetpunten of metingen beschikbaar.')) {
        throw new RuntimeException('The visual dashboard must explain when measurements have not been collected: ' . $emptyError);
    }
    if (!str_contains($emptyHtml, 'Er zijn nog geen graadminutenmetingen beschikbaar in de afgelopen 24 uur.')
        || !str_contains($emptyHtml, 'data-reading="degree_minutes">Niet beschikbaar')) {
        throw new RuntimeException('The degree-minute card must not invent values or chart data when measurements are missing.');
    }
    [$noWeatherHtml, $noWeatherError, $noWeatherExitCode] = $renderHome(['NIBE_TEST_NO_WEATHER' => '1']);
    if ($noWeatherExitCode !== 0
        || !str_contains($noWeatherHtml, 'data-weather-condition=""')
        || !str_contains($noWeatherHtml, 'aria-controls="context-panel"')
        || !str_contains($homeScript, 'Weerdata wordt zichtbaar na de eerste geslaagde verzameling.')) {
        throw new RuntimeException('The outdoor visual must explain when no weather observation has been collected: ' . $noWeatherError);
    }
    [$databaseErrorHtml, $databaseError, $databaseExitCode] = $renderHome(['NIBE_TEST_DB_FAIL' => '1']);
    if ($databaseExitCode !== 0 || !str_contains($databaseErrorHtml, 'De database is nog niet beschikbaar.')) {
        throw new RuntimeException('The visual dashboard must explain when the database is unavailable: ' . $databaseError);
    }
} finally {
    unlink($directory . '/app/config.php');
    unlink($directory . '/app/dashboard.php');
    unlink($directory . '/index_old.php');
    unlink($directory . '/index.php');
    if (file_exists($directory . '/render-home.php')) unlink($directory . '/render-home.php');
    rmdir($directory . '/app');
    rmdir($directory);
}

echo "Dashboard UI tests passed.\n";
