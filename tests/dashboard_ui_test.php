<?php

$directory = sys_get_temp_dir() . '/nibe-dashboard-' . bin2hex(random_bytes(8));
mkdir($directory);
mkdir($directory . '/app');
copy(__DIR__ . '/../index.php', $directory . '/index.php');
copy(__DIR__ . '/../app/dashboard.php', $directory . '/app/dashboard.php');
file_put_contents($directory . '/app/config.php', <<<'PHP'
<?php
function nibe_env(string $name, ?string $default = null): ?string
{
    return $name === 'NIBE_DASHBOARD_ROLE_MAP'
        ? '{"electric_power":"3","heat_power":"4"}'
        : $default;
}

function nibe_database(): DashboardTestDatabase
{
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
    public function fetch(): bool { return false; }
    public function fetchAll(): array
    {
        $now = time();
        $device = 'emmy-n-20251117-06794225149019-fc-0f-e7-5e-ef-32';
        $points = [
            ['1', '44058', '40', '°C', $now - 600],
            ['2', '44055', '35', '°C', $now - 295],
            ['3', '999', '2', 'kW<script>', $now - 300],
            ['4', '598', '3', 'kW', $now - 901],
        ];
        if (str_contains($this->sql, 'FROM nibe_measurement_points')) {
            return array_map(static fn (array $point): array => [
                'measurement_point_id' => $point[0], 'source_point_id' => $point[1],
                'raw_value' => $point[2], 'unit' => $point[3],
                'source_timestamp' => gmdate('Y-m-d H:i:s', $point[4]),
                'collected_at' => gmdate('Y-m-d H:i:s', $point[4]),
                'source_device_id' => $device, 'device_name' => 'Test', 'name' => 'Test',
            ], $points);
        }
        if (str_contains($this->sql, 'measurement_point_id IN')) {
            return array_map(static fn (array $point): array => [
                'measurement_point_id' => $point[0], 'raw_value' => $point[2],
                'source_timestamp' => gmdate('Y-m-d H:i:s', $point[4]),
                'collected_at' => gmdate('Y-m-d H:i:s', $point[4]),
            ], $points);
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
    include $directory . '/index.php';
    $html = ob_get_clean();
    restore_error_handler();

    $unavailable = substr_count($html, '<strong>Niet beschikbaar</strong>');
    $debugBlocks = preg_match_all('/<strong>Niet beschikbaar<\/strong>\s*<details class="debug"><summary>Toelichting<\/summary>/', $html);
    if ($unavailable === 0 || $debugBlocks !== $unavailable
        || substr_count($html, '<details class="debug"><summary>Toelichting</summary>') !== $unavailable
        || preg_match_all('/<details\b[^>]*class="debug"[^>]*>/', $html) !== $unavailable) {
        throw new RuntimeException('Each unavailable KPI must have exactly one collapsed debug disclosure; available KPIs must have none.');
    }
    if (!str_contains($html, 'kw&lt;script&gt;') || str_contains($html, 'kw<script>')) {
        throw new RuntimeException('Debug output must escape measurement units.');
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
} finally {
    unlink($directory . '/app/config.php');
    unlink($directory . '/app/dashboard.php');
    unlink($directory . '/index.php');
    rmdir($directory . '/app');
    rmdir($directory);
}

echo "Dashboard UI tests passed.\n";
