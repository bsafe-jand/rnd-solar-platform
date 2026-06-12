<?php
require_once __DIR__ . '/config.php';

define('LATEST_FILE', __DIR__ . '/latest.json');
define('LOG_FILE',    __DIR__ . '/solar.log');

// ── Route: POST /  →  receive a push from the Pi ─────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (API_KEY !== null && API_KEY !== '') {
        $incoming = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!hash_equals(API_KEY, $incoming)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }

    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!is_array($data)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $data['received_at'] = date('Y-m-d H:i:s');

    file_put_contents(LATEST_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    file_put_contents(LOG_FILE, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    rotate_log(LOG_FILE, LOG_MAX_LINES);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'received_at' => $data['received_at']]);
    exit;
}

// ── Route: GET /?json ─────────────────────────────────────────────────────────

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode(latest_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Load data for dashboard ───────────────────────────────────────────────────

$d        = latest_data();
$pushTime = $d['timestamp']   ?? null;
$recvTime = $d['received_at'] ?? null;
$age      = $recvTime ? (time() - strtotime($recvTime)) : null;
$noData       = count($d) <= 2; // only timestamp + received_at means nothing useful yet
$noDeviceData = !$noData && !has_any_data($d);

// ── Helpers ───────────────────────────────────────────────────────────────────

function has_any_data(array $d): bool
{
    foreach (['solar', 'batteries', 'inverters', 'acGrid', 'acLoads', 'genset', 'tanks', 'environment', 'navigation'] as $key) {
        if (!empty($d[$key])) return true;
    }
    $dc = $d['dc'] ?? [];
    return ($dc['systemVoltage_V'] ?? null) !== null
        || ($dc['systemCurrent_A'] ?? null) !== null
        || ($dc['systemPower_W']   ?? null) !== null;
}

function latest_data(): array
{
    if (!file_exists(LATEST_FILE)) return [];
    return json_decode(file_get_contents(LATEST_FILE), true) ?? [];
}

function rotate_log(string $path, int $max): void
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) <= $max) return;
    file_put_contents($path, implode("\n", array_slice($lines, -$max)) . "\n", LOCK_EX);
}

/** Print a key/value row, hiding rows where value is null */
function row(string $label, mixed $value, string $unit = '', string $class = ''): void
{
    if ($value === null || $value === '') return;
    $display = is_bool($value) ? ($value ? 'Yes' : 'No') : htmlspecialchars((string)$value);
    $cls = $class ? " class=\"val {$class}\"" : ' class="val"';
    echo "<div class=\"row\"><span class=\"lbl\">{$label}</span>"
       . "<span{$cls}>{$display}"
       . ($unit ? " <span class=\"unit\">{$unit}</span>" : '')
       . "</span></div>\n";
}

function badge_mode(mixed $mode): string
{
    $map = [
        0   => ['Off',             'off'],
        1   => ['Low power',       'off'],
        2   => ['Fault',           'fault'],
        3   => ['Bulk',            'bulk'],
        4   => ['Absorption',      'abs'],
        5   => ['Float',           'float'],
        6   => ['Storage',         'float'],
        7   => ['Equalise',        'eq'],
        9   => ['Inverting',       'inv'],
        11  => ['Power supply',    'off'],
        245 => ['Wake-up',         'off'],
        247 => ['Auto equalise',   'eq'],
        252 => ['External ctrl',   'bulk'],
    ];
    $label = is_string($mode) ? $mode : ($map[(int)$mode][0] ?? "Mode {$mode}");
    $cls   = is_string($mode) ? 'off'  : ($map[(int)$mode][1] ?? 'off');
    return "<span class=\"badge badge-{$cls}\">" . htmlspecialchars($label) . "</span>";
}

function section(string $title, string $icon = ''): void
{
    echo "<h2>{$icon} {$title}</h2>\n<div class=\"grid\">\n";
}

function end_section(): void { echo "</div>\n"; }

function open_card(string $label): void
{
    echo "<div class=\"card\"><div class=\"card-label\">" . htmlspecialchars($label) . "</div>\n";
}

function big(mixed $value, string $unit, string $class = ''): void
{
    if ($value === null) return;
    $cls = $class ? " {$class}" : '';
    echo "<div class=\"big{$cls}\">" . htmlspecialchars((string)$value)
       . "<span class=\"unit\">{$unit}</span></div>\n";
}

function close_card(): void { echo "</div>\n"; }

function has_non_null(array $arr): bool {
    foreach ($arr as $v) { if ($v !== null) return true; }
    return false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="35">
<title>Solar Monitor</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body   { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 1.5rem 1.75rem; }
  h1     { font-size: 1.4rem; font-weight: 700; color: #facc15; margin-bottom: 1.75rem; }
  h2     { font-size: .75rem; font-weight: 700; color: #64748b; margin: 1.5rem 0 .6rem;
           text-transform: uppercase; letter-spacing: .08em; display: flex; align-items: center; gap: .4rem; }
  .grid  { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: .85rem; }
  .card  { background: #1e293b; border-radius: .75rem; padding: 1.1rem 1.2rem; border: 1px solid #334155; }
  .card-label { font-size: .68rem; color: #475569; text-transform: uppercase; letter-spacing: .07em; margin-bottom: .5rem; }
  .big   { font-size: 2rem; font-weight: 700; line-height: 1; margin-bottom: .1rem; }
  .sub   { color: #64748b; font-size: .78rem; margin-bottom: .8rem; }
  .unit  { font-size: .8rem; color: #94a3b8; margin-left: .1rem; }
  .row   { display: flex; justify-content: space-between; align-items: center;
           padding: .28rem 0; border-bottom: 1px solid #0f172a; font-size: .85rem; }
  .row:last-child { border-bottom: none; }
  .lbl   { color: #64748b; }
  .val   { font-weight: 600; text-align: right; }
  .badge { display: inline-block; padding: .13rem .45rem; border-radius: 9999px; font-size: .7rem; font-weight: 600; }
  .badge-bulk  { background: #1d4ed8; color: #bfdbfe; }
  .badge-abs   { background: #7c3aed; color: #ede9fe; }
  .badge-float { background: #065f46; color: #a7f3d0; }
  .badge-inv   { background: #0f766e; color: #99f6e4; }
  .badge-off   { background: #374151; color: #9ca3af; }
  .badge-eq    { background: #92400e; color: #fde68a; }
  .badge-fault { background: #7f1d1d; color: #fecaca; }
  .warn  { color: #f87171; }
  .good  { color: #4ade80; }
  .muted { color: #94a3b8; }
  .stale { color: #f59e0b; }
  .stale-banner { background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.45);
                  border-radius: .5rem; padding: .7rem 1rem .7rem 1rem; color: #f59e0b;
                  font-size: .84rem; margin-bottom: 1.25rem; }
  .err   { background: #1e293b; border: 1px solid #ef4444; border-radius: .5rem; padding: 1rem; color: #fca5a5; line-height: 1.7; }
  .ts    { font-size: .72rem; color: #475569; margin-top: 1.5rem; line-height: 2; }
  a      { color: #60a5fa; }
  code   { background: #0f172a; padding: .1rem .35rem; border-radius: .25rem; font-size: .85em; }
  .info  { background: #1e293b; border: 1px solid #22c55e; border-radius: .5rem; padding: 1.25rem 1.4rem; line-height: 1.8; }
  .info-badge { display: inline-flex; align-items: center; gap: .35rem; background: #14532d; color: #4ade80;
                padding: .2rem .7rem; border-radius: 9999px; font-size: .75rem; font-weight: 700;
                letter-spacing: .04em; margin-bottom: .8rem; }
  .info p { color: #cbd5e1; font-size: .9rem; margin-bottom: .25rem; }
  details { margin-top: 1rem; }
  details > summary { cursor: pointer; color: #60a5fa; font-size: .82rem; user-select: none; }
  details > summary:hover { color: #93c5fd; }
  details > pre { margin-top: .6rem; background: #0f172a; border: 1px solid #334155; border-radius: .5rem;
                  padding: 1rem; font-size: .75rem; color: #94a3b8; overflow-x: auto;
                  white-space: pre-wrap; word-break: break-all; max-height: 380px; overflow-y: auto; }
  .chart-wrap { background: #1e293b; border-radius: .75rem; padding: 1rem 1.2rem; border: 1px solid #334155; margin-bottom: .85rem; }
  .chart-title { font-size: .75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; margin-bottom: .4rem; }
  canvas { max-height: 180px; }
  .btn-reboot { background: transparent; border: 1px solid #ef4444; color: #f87171; font-size: .72rem;
                font-family: inherit; padding: .18rem .6rem; border-radius: .35rem; cursor: pointer;
                transition: background .15s, color .15s; vertical-align: middle; }
  .btn-reboot:hover { background: rgba(239,68,68,.15); color: #fca5a5; }
  #reboot-status { font-size: .72rem; color: #f59e0b; margin-left: .5rem; }
  .btn-mppt { background: transparent; border: 1px solid #f59e0b; color: #fbbf24; font-size: .72rem;
              font-family: inherit; padding: .18rem .6rem; border-radius: .35rem; cursor: pointer;
              transition: background .15s, color .15s; vertical-align: middle; margin-left: .5rem; }
  .btn-mppt:hover { background: rgba(245,158,11,.15); color: #fde68a; }
  #mppt-status { font-size: .72rem; color: #f59e0b; margin-left: .5rem; }
</style>
</head>
<body>

<h1>&#9728; Solar Monitor</h1>

<?php if ($age !== null && $age > 3600):
    $stalHours = round($age / 3600, 1);
    $stalHoursStr = ($stalHours == floor($stalHours)) ? (int)$stalHours : $stalHours;
?>
<div class="stale-banner">
  &#9888; Last push was <?= $stalHoursStr ?> hour<?= $stalHoursStr == 1 ? '' : 's' ?> ago — Pi may be offline or Node-RED flow stopped
</div>
<?php endif; ?>

<?php if ($noData): ?>
<div class="err">
  <strong>No data received yet.</strong><br>
  The Pi has not pushed any data to this endpoint.<br>
  Import <code>nodered-flow.json</code> into Node-RED, then push to
  <code><?= htmlspecialchars(PUSH_URL) ?></code>
</div>
<?php elseif ($noDeviceData): ?>
<div class="info">
  <div class="info-badge">&#9679; Connected</div>
  <?php if ($pushTime): ?>
  &nbsp;<span style="font-size:.8rem;color:#475569;">Pi timestamp: <strong style="color:#94a3b8;"><?= htmlspecialchars($pushTime) ?></strong></span>
  <?php endif; ?>
  <p><strong>Signal K is reachable but no device data was found.</strong><br>
  Connect Victron hardware (MPPT, BMV, MultiPlus, etc.) via the Venus OS device.</p>
  <details>
    <summary>&#128269; Show raw JSON payload</summary>
    <pre><?= htmlspecialchars(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </details>
</div>
<?php else: ?>

<?php
// ── Solar chargers ────────────────────────────────────────────────────────────
$solar = $d['solar'] ?? [];
if (!empty($solar)):
    section('Solar Chargers', '&#9865;');
    foreach ($solar as $id => $c):
        open_card("MPPT — {$id}");
        $pw = $c['panelPower_W'] ?? null;
        if ($pw !== null) { big($pw, 'W', $pw > 0 ? 'good' : 'muted'); echo "<div class=\"sub\">Panel power</div>\n"; }
        row('Panel voltage',    $c['panelVoltage_V']   ?? null, 'V');
        row('Panel current',    $c['panelCurrent_A']   ?? null, 'A');
        row('Output voltage',   $c['outputVoltage_V']  ?? null, 'V');
        row('Output current',   $c['outputCurrent_A']  ?? null, 'A');
        row('Output power',     $c['outputPower_W']    ?? null, 'W');
        row('Yield today',      $c['yieldToday_Wh']    ?? null, 'Wh');
        row('Yield yesterday',  $c['yieldYesterday_Wh']?? null, 'Wh');
        row('Yield total',      isset($c['yieldTotal_Wh']) ? number_format($c['yieldTotal_Wh']) : null, 'Wh');
        row('Max power today',  $c['maxPowerToday_W']  ?? null, 'W');
        row('Temperature',      $c['temperature_C']    ?? null, '°C');
        row('Error code',       $c['errorCode']        ?? null);
        if (isset($c['controllerMode'])):
            echo "<div class=\"row\"><span class=\"lbl\">Mode</span>" . badge_mode($c['controllerMode']) . "</div>\n";
        endif;
        close_card();
    endforeach;
    end_section();
endif;

// ── Batteries ─────────────────────────────────────────────────────────────────
$batteries = $d['batteries'] ?? [];
if (!empty($batteries)):
    section('Battery Banks', '&#128267;');
    foreach ($batteries as $id => $b):
        open_card("Battery — {$id}");
        $soc = $b['stateOfCharge_pct'] ?? null;
        if ($soc !== null) { big($soc, '%', $soc < 20 ? 'warn' : 'good'); echo "<div class=\"sub\">State of charge</div>\n"; }
        row('Voltage',           $b['voltage_V']            ?? null, 'V');
        row('Current',           $b['current_A']            ?? null, 'A', ($b['current_A'] ?? 0) < 0 ? 'warn' : 'good');
        row('Power',             $b['power_W']              ?? null, 'W');
        row('Time remaining',    $b['timeRemaining_min']    ?? null, 'min');
        row('Consumed',          $b['consumedAh']           ?? null, 'Ah');
        row('Capacity',          $b['capacity_Ah']          ?? null, 'Ah');
        row('Temperature',       $b['temperature_C']        ?? null, '°C');
        row('Midpoint voltage',  $b['midpointVoltage_V']    ?? null, 'V');
        row('Midpoint deviation',$b['midpointDeviation_pct']?? null, '%');
        row('Starter voltage',   $b['starterVoltage_V']     ?? null, 'V');
        row('Charge efficiency', $b['chargeEfficiency_pct'] ?? null, '%');
        close_card();
    endforeach;
    end_section();
endif;

// ── Inverter / Charger ────────────────────────────────────────────────────────
$inverters = $d['inverters'] ?? [];
if (!empty($inverters)):
    section('Inverter / Charger', '&#9889;');
    foreach ($inverters as $id => $inv):
        open_card("Inverter — {$id}");
        if (isset($inv['operatingMode'])):
            echo "<div class=\"row\"><span class=\"lbl\">Mode</span>" . badge_mode($inv['operatingMode']) . "</div>\n";
        endif;
        row('State',        $inv['state']       ?? null);
        row('Active input', $inv['activeInput']  ?? null);
        $dc = $inv['dc'] ?? [];
        row('DC voltage',   $dc['voltage_V']    ?? null, 'V');
        row('DC current',   $dc['current_A']    ?? null, 'A');
        row('DC power',     $dc['power_W']      ?? null, 'W');
        foreach ($inv['acIn'] ?? [] as $ph => $p):
            row("AC in {$ph} voltage",   $p['voltage_V']       ?? null, 'V');
            row("AC in {$ph} current",   $p['current_A']       ?? null, 'A');
            row("AC in {$ph} frequency", $p['frequency_Hz']    ?? null, 'Hz');
            row("AC in {$ph} power",     $p['power_W']         ?? null, 'W');
            row("AC in {$ph} power factor", $p['powerFactor']  ?? null);
        endforeach;
        foreach ($inv['acOut'] ?? [] as $ph => $p):
            row("AC out {$ph} voltage",   $p['voltage_V']      ?? null, 'V');
            row("AC out {$ph} current",   $p['current_A']      ?? null, 'A');
            row("AC out {$ph} frequency", $p['frequency_Hz']   ?? null, 'Hz');
            row("AC out {$ph} power",     $p['power_W']        ?? null, 'W');
        endforeach;
        close_card();
    endforeach;
    end_section();
endif;

// ── AC Grid ───────────────────────────────────────────────────────────────────
$acGrid = $d['acGrid'] ?? [];
if (!empty($acGrid)):
    section('AC Grid / Shore', '&#127968;');
    foreach ($acGrid as $ph => $p):
        open_card("Phase — {$ph}");
        row('Voltage',   $p['voltage_V']    ?? null, 'V');
        row('Current',   $p['current_A']    ?? null, 'A');
        row('Frequency', $p['frequency_Hz'] ?? null, 'Hz');
        row('Power',     $p['power_W']      ?? null, 'W');
        row('Energy',    $p['energy_kWh']   ?? null, 'kWh');
        close_card();
    endforeach;
    end_section();
endif;

// ── AC Loads ──────────────────────────────────────────────────────────────────
$acLoads = $d['acLoads'] ?? [];
if (!empty($acLoads)):
    section('AC Loads / Output', '&#128161;');
    foreach ($acLoads as $ph => $p):
        open_card("Phase — {$ph}");
        row('Voltage',   $p['voltage_V']    ?? null, 'V');
        row('Current',   $p['current_A']    ?? null, 'A');
        row('Frequency', $p['frequency_Hz'] ?? null, 'Hz');
        row('Power',     $p['power_W']      ?? null, 'W');
        close_card();
    endforeach;
    end_section();
endif;

// ── Generator ─────────────────────────────────────────────────────────────────
$genset = $d['genset'] ?? [];
if (!empty($genset)):
    section('Generator', '&#9978;');
    foreach ($genset as $ph => $p):
        open_card("Phase — {$ph}");
        row('Running',   $p['running']      ?? null);
        row('Voltage',   $p['voltage_V']    ?? null, 'V');
        row('Current',   $p['current_A']    ?? null, 'A');
        row('Frequency', $p['frequency_Hz'] ?? null, 'Hz');
        row('Power',     $p['power_W']      ?? null, 'W');
        row('Runtime',   $p['runtime_h']    ?? null, 'h');
        close_card();
    endforeach;
    end_section();
endif;

// ── DC System ─────────────────────────────────────────────────────────────────
$dc = $d['dc'] ?? [];
$hasDC = ($dc['systemVoltage_V'] ?? null) !== null
      || ($dc['systemCurrent_A'] ?? null) !== null
      || ($dc['systemPower_W']   ?? null) !== null;
if ($hasDC):
    section('DC System', '&#128268;');
    echo "<div class=\"grid\">\n";
    open_card('DC System load');
    row('Voltage', $dc['systemVoltage_V'] ?? null, 'V');
    row('Current', $dc['systemCurrent_A'] ?? null, 'A');
    row('Power',   $dc['systemPower_W']   ?? null, 'W');
    close_card();
    end_section();
endif;

// ── Tanks ─────────────────────────────────────────────────────────────────────
$tanks = $d['tanks'] ?? [];
if (!empty($tanks)):
    section('Tanks', '&#128167;');
    foreach ($tanks as $id => $t):
        open_card(($t['type'] ?? $id) . " — {$id}");
        $lvl = $t['level_pct'] ?? null;
        if ($lvl !== null) { big($lvl, '%', $lvl < 20 ? 'warn' : 'good'); echo "<div class=\"sub\">Level</div>\n"; }
        row('Capacity',  $t['capacity_L']  ?? null, 'L');
        row('Remaining', $t['remaining_L'] ?? null, 'L');
        close_card();
    endforeach;
    end_section();
endif;

// ── Environment ───────────────────────────────────────────────────────────────
$env = $d['environment'] ?? [];
$hasEnv = ($env['inside']['temperature_C']  ?? null) !== null
       || ($env['outside']['temperature_C'] ?? null) !== null
       || ($env['water']['temperature_C']   ?? null) !== null
       || ($env['solar']['irradiance_Wm2']  ?? null) !== null;
if ($hasEnv):
    section('Environment', '&#127777;');
    echo "<div class=\"grid\">\n";
    open_card('Sensors');
    row('Inside temp',    $env['inside']['temperature_C']  ?? null, '°C');
    row('Inside humidity',$env['inside']['humidity_pct']   ?? null, '%');
    row('Outside temp',   $env['outside']['temperature_C'] ?? null, '°C');
    row('Outside humidity',$env['outside']['humidity_pct'] ?? null, '%');
    row('Air pressure',   $env['outside']['pressure_hPa']  ?? null, 'hPa');
    row('Water temp',     $env['water']['temperature_C']   ?? null, '°C');
    row('Solar irradiance',$env['solar']['irradiance_Wm2'] ?? null, 'W/m²');
    close_card();
    end_section();
endif;

// ── Navigation ───────────────────────────────────────────────────────────────
$nav = $d['navigation'] ?? [];
$hasNav = ($nav['latitude'] ?? null) !== null || ($nav['longitude'] ?? null) !== null;
if ($hasNav):
    section('Navigation / GPS', '&#128205;');
    echo "<div class=\"grid\">\n";
    open_card('Position');
    row('Latitude',  $nav['latitude']             ?? null, '°');
    row('Longitude', $nav['longitude']            ?? null, '°');
    row('Speed',     $nav['speedOverGround_ms']   ?? null, 'm/s');
    row('COG',       $nav['courseOverGround_deg'] ?? null, '°');
    row('Heading (T)',$nav['headingTrue_deg']     ?? null, '°');
    row('Heading (M)',$nav['headingMagnetic_deg'] ?? null, '°');
    row('Altitude',  $nav['altitude_m']           ?? null, 'm');
    row('Satellites',$nav['satellites']           ?? null);
    row('Fix type',  $nav['fixType']              ?? null);
    close_card();
    end_section();
endif;
?>

<?php endif; ?>

<?php
// ── Historical charts data prep ───────────────────────────────────────────────
$chartLabels          = [];
$chartChargerVoltage  = [];
$chartChargerCurrent  = [];
$chartSolarPower      = [];
$chartSoC             = [];
$chartBattVoltage     = [];
$chartBattCurrent     = [];
$chartYieldToday      = [];

if (file_exists(LOG_FILE)) {
    $logLines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($logLines !== false) {
        $logLines = array_slice($logLines, -288);
        foreach ($logLines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) continue;
            $ts = $entry['timestamp'] ?? null;
            $chartLabels[] = $ts ? date('H:i', strtotime($ts)) : '';
            $solarArr = $entry['solar'] ?? [];
            $s0 = !empty($solarArr) ? reset($solarArr) : [];
            $chartChargerVoltage[] = array_key_exists('outputVoltage_V', $s0) ? $s0['outputVoltage_V'] : null;
            $chartChargerCurrent[] = array_key_exists('outputCurrent_A', $s0) ? $s0['outputCurrent_A'] : null;
            $chartSolarPower[]     = array_key_exists('panelPower_W',    $s0) ? $s0['panelPower_W']    : null;
            $chartYieldToday[]     = array_key_exists('yieldToday_Wh',   $s0) ? $s0['yieldToday_Wh']  : null;
            $battArr = $entry['batteries'] ?? [];
            $b0 = !empty($battArr) ? reset($battArr) : [];
            $chartSoC[]         = array_key_exists('stateOfCharge_pct', $b0) ? $b0['stateOfCharge_pct'] : null;
            $chartBattVoltage[] = array_key_exists('voltage_V',         $b0) ? $b0['voltage_V']         : null;
            $chartBattCurrent[] = array_key_exists('current_A',         $b0) ? $b0['current_A']         : null;
        }
    }
}
$hasChartData = count($chartLabels) >= 2;
?>

<?php if ($hasChartData): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

<h2>&#128200; History</h2>

<?php
$jsLabels          = json_encode($chartLabels);
$jsChargerVoltage  = json_encode($chartChargerVoltage);
$jsChargerCurrent  = json_encode($chartChargerCurrent);
$jsSolarPower      = json_encode($chartSolarPower);
$jsSoC             = json_encode($chartSoC);
$jsBattVoltage     = json_encode($chartBattVoltage);
$jsBattCurrent     = json_encode($chartBattCurrent);
$jsYieldToday      = json_encode($chartYieldToday);
?>

<?php if (has_non_null($chartChargerVoltage)): ?>
<div class="chart-wrap">
  <p class="chart-title">Charger Output Voltage (V)</p>
  <canvas id="chartChargerVoltage"></canvas>
</div>
<?php endif; ?>
<?php if (has_non_null($chartChargerCurrent)): ?>
<div class="chart-wrap">
  <p class="chart-title">Charger Output Current (A)</p>
  <canvas id="chartChargerCurrent"></canvas>
</div>
<?php endif; ?>
<?php if (has_non_null($chartSolarPower)): ?>
<div class="chart-wrap">
  <p class="chart-title">Solar Power (W)</p>
  <canvas id="chartSolarPower"></canvas>
</div>
<?php endif; ?>
<?php if (has_non_null($chartSoC)): ?>
<div class="chart-wrap">
  <p class="chart-title">Battery State of Charge (%)</p>
  <canvas id="chartSoC"></canvas>
</div>
<?php endif; ?>
<?php if (has_non_null($chartBattVoltage)): ?>
<div class="chart-wrap">
  <p class="chart-title">Battery Voltage (V)</p>
  <canvas id="chartBattVoltage"></canvas>
</div>
<?php endif; ?>
<?php if (has_non_null($chartBattCurrent)): ?>
<div class="chart-wrap">
  <p class="chart-title">Battery Current (A)</p>
  <canvas id="chartBattCurrent"></canvas>
</div>
<?php endif; ?>
<?php if (has_non_null($chartYieldToday)): ?>
<div class="chart-wrap">
  <p class="chart-title">Yield Today (Wh)</p>
  <canvas id="chartYieldToday"></canvas>
</div>
<?php endif; ?>

<script>
(function () {
  var labels          = <?= $jsLabels ?>;
  var chargerVoltage  = <?= $jsChargerVoltage ?>;
  var chargerCurrent  = <?= $jsChargerCurrent ?>;
  var solarPower      = <?= $jsSolarPower ?>;
  var soc             = <?= $jsSoC ?>;
  var battVoltage     = <?= $jsBattVoltage ?>;
  var battCurrent     = <?= $jsBattCurrent ?>;
  var yieldToday      = <?= $jsYieldToday ?>;

  var darkTooltip = {
    backgroundColor: '#1e293b',
    borderColor: '#334155',
    borderWidth: 1,
    titleColor: '#e2e8f0',
    bodyColor: '#94a3b8',
  };

  function baseDataset(label, data, color) {
    return {
      label: label,
      data: data,
      borderColor: color,
      backgroundColor: color + '33',
      fill: true,
      tension: 0.3,
      pointRadius: 0,
      spanGaps: false,
    };
  }

  function baseScales(yOpts) {
    var y = Object.assign({
      grid: { color: '#334155' },
      ticks: { color: '#64748b' },
    }, yOpts || {});
    return {
      x: {
        grid: { color: '#334155' },
        ticks: { color: '#64748b', maxTicksLimit: 12 },
      },
      y: y,
    };
  }

  function makeChart(id, label, data, color, yOpts) {
    new Chart(document.getElementById(id), {
      type: 'line',
      data: { labels: labels, datasets: [baseDataset(label, data, color)] },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, ...darkTooltip } },
        scales: baseScales(yOpts),
      },
    });
  }

  var yTitle = function(text) { return { title: { display: true, text: text, color: '#64748b', font: { size: 11 } } }; };

  if (document.getElementById('chartChargerVoltage')) makeChart('chartChargerVoltage', 'Charger Output Voltage (V)', chargerVoltage, '#60a5fa', yTitle('V'));

  // Charger output current — colour segments by sign
  if (document.getElementById('chartChargerCurrent')) {
    new Chart(document.getElementById('chartChargerCurrent'), {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'Charger Output Current (A)',
          data: chargerCurrent,
          borderColor: '#60a5fa',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.3,
          pointRadius: 0,
          spanGaps: false,
          segment: {
            borderColor: function (ctx) {
              var v = ctx.p1.parsed.y;
              return v >= 0 ? '#4ade80' : '#f87171';
            },
          },
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, ...darkTooltip } },
        scales: baseScales({ title: { display: true, text: 'A', color: '#64748b', font: { size: 11 } } }),
      },
    });
  }

  if (document.getElementById('chartSolarPower'))  makeChart('chartSolarPower', 'Solar Power (W)', solarPower, '#facc15', yTitle('W'));
  if (document.getElementById('chartSoC'))         makeChart('chartSoC', 'Battery SoC (%)', soc, '#4ade80', Object.assign({ min: 0, max: 100 }, yTitle('%')));
  if (document.getElementById('chartBattVoltage')) makeChart('chartBattVoltage', 'Battery Voltage (V)', battVoltage, '#60a5fa', yTitle('V'));
  if (document.getElementById('chartYieldToday'))  makeChart('chartYieldToday', 'Yield Today (Wh)', yieldToday, '#a78bfa', yTitle('Wh'));

  // Battery current — colour segments by sign
  if (!document.getElementById('chartBattCurrent')) return;
  new Chart(document.getElementById('chartBattCurrent'), {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Battery Current (A)',
        data: battCurrent,
        borderColor: '#60a5fa',
        backgroundColor: 'transparent',
        fill: false,
        tension: 0.3,
        pointRadius: 0,
        spanGaps: false,
        segment: {
          borderColor: function (ctx) {
            var v = ctx.p1.parsed.y;
            return v >= 0 ? '#4ade80' : '#f87171';
          },
        },
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, ...darkTooltip } },
      scales: baseScales({ title: { display: true, text: 'A', color: '#64748b', font: { size: 11 } } }),
    },
  });
})();
</script>
<?php endif; ?>

<div class="ts">
  <?php if ($pushTime): ?>Pi timestamp: <strong><?= htmlspecialchars($pushTime) ?></strong><br><?php endif; ?>
  <?php if ($recvTime):
    echo "Received: <strong>" . htmlspecialchars($recvTime) . "</strong>";
    if ($age > 3600) {
      $hrs = round($age / 3600, 1);
      $hrsStr = ($hrs == floor($hrs)) ? (int)$hrs : $hrs;
      echo " &nbsp;<span class=\"stale\">&#9888; last push was {$hrsStr}h ago — Pi may be offline or Node-RED flow stopped</span>";
    } elseif ($age > 90) {
      echo " &nbsp;<span class=\"stale\">&#9888; {$age}s ago</span>";
    }
    echo "<br>";
  endif; ?>
  Auto-refreshes every 35 s &middot; <a href="?json">Raw JSON</a>
  &nbsp;&middot;&nbsp;
  <button class="btn-reboot" id="btn-reboot" onclick="doReboot()">&#9211; Reboot Venus OS</button>
  <span id="reboot-status"></span>
  <button class="btn-mppt" id="btn-mppt" onclick="doMpptRestart()">&#8635; Restart MPPT</button>
  <span id="mppt-status"></span>
</div>
<script>
function doReboot() {
  if (!confirm('Are you sure you want to reboot the Venus OS device?\nThis will disconnect all devices for ~60 seconds.')) return;
  fetch('http://192.168.0.2:1881/reboot', { method: 'POST', mode: 'no-cors' })
    .then(function() {
      document.getElementById('reboot-status').textContent = 'Reboot command sent\u2026';
      document.getElementById('btn-reboot').disabled = true;
    })
    .catch(function() {
      document.getElementById('reboot-status').textContent = 'Reboot command sent\u2026';
      document.getElementById('btn-reboot').disabled = true;
    });
}
function doMpptRestart() {
  if (!confirm('Restart MPPT charger 290? It will go offline for ~5 seconds then resume charging.')) return;
  fetch('http://192.168.0.2:1881/restart-mppt', { method: 'POST', mode: 'no-cors' })
    .then(function() {
      document.getElementById('mppt-status').textContent = 'MPPT restart sent\u2026';
      document.getElementById('btn-mppt').disabled = true;
    })
    .catch(function() {
      document.getElementById('mppt-status').textContent = 'MPPT restart sent\u2026';
      document.getElementById('btn-mppt').disabled = true;
    });
}
</script>

</body>
</html>
