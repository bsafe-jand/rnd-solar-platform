# Solar Monitor — Project Context

> Written for: new AI agents and developers onboarding to this project.  
> Last updated: June 2026

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Tech Stack](#2-tech-stack)
3. [Architecture & Data Flow](#3-architecture--data-flow)
4. [File Inventory](#4-file-inventory)
5. [Data Payload Structure](#5-data-payload-structure)
6. [Node-RED Flow Details](#6-node-red-flow-details)
7. [Device-Agnostic Design](#7-device-agnostic-design)
8. [PHP Dashboard Features](#8-php-dashboard-features)
9. [API Reference](#9-api-reference)
10. [Setup Instructions](#10-setup-instructions)
11. [Known Issues & Notes](#11-known-issues--notes)

---

## 1. Project Overview

**Solar Monitor** is a lightweight telemetry dashboard for Victron Energy solar/battery systems running Venus OS (typically on a Raspberry Pi).

The system works as follows:

- A **Node-RED flow** runs on the Venus OS Pi. Every 30 seconds it polls the local Signal K server, extracts all available electrical, environment, tank, and navigation data, and **POSTs a structured JSON payload** to a remote PHP endpoint.
- The **PHP server** (running on a separate machine) receives the push, writes it to `latest.json`, and appends a line to a rolling `solar.log`.
- The **PHP dashboard** (`GET /`) reads `latest.json` and `solar.log` to render live cards and historical Chart.js graphs in the browser. It auto-refreshes every 35 seconds.
- In parallel, Node-RED also **writes to InfluxDB v2** (line protocol) for long-term storage and Grafana dashboards.
- A second Node-RED tab handles **Venus OS reboot** and **MPPT charger restart** via D-Bus and MQTT, triggered by schedule, manual button click, or HTTP from the dashboard.

The Pi runs entirely standalone — you deploy one `nodered-flow.json` to any Venus OS device without editing any device-specific IDs.

---

## 2. Tech Stack

| Layer | Technology |
|---|---|
| Solar/battery hardware | Victron MPPT chargers, BMV battery monitors, MultiPlus/Quattro inverters |
| Venus OS gateway | Raspberry Pi running Victron Venus OS |
| Signal K | Runs on Venus OS at `localhost:3000`; normalises all Victron D-Bus data into a unified JSON tree |
| Node-RED | Runs on Venus OS; orchestrates polling, formatting, and pushing data |
| MQTT broker | Venus OS built-in MQTT at `localhost:1883`; used for device discovery and MPPT control |
| PHP server | PHP 8, serves both the POST endpoint and the dashboard; listens on `192.168.0.3:9999` |
| InfluxDB v2 | Time-series database; listens on `192.168.0.3:8086`; org `solar`, bucket `victron` |
| Grafana | Dashboarding UI; listens on `192.168.0.3:3001`; auto-provisioned with InfluxDB datasource |
| Chart.js 4 | In-browser chart library loaded from CDN; used for historical graphs in the PHP dashboard |

---

## 3. Architecture & Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  Venus OS Pi  (192.168.0.2)                                         │
│                                                                     │
│  Victron MPPT / BMV / MultiPlus                                     │
│       │ D-Bus                                                       │
│       ▼                                                             │
│  Signal K  (localhost:3000)                                         │
│  /signalk/v1/api/vessels/self   ◄── Node-RED polls every 30 s      │
│                                                                     │
│  Node-RED                                                           │
│  ├── Tab 1: Solar Push                                              │
│  │   ├── Parse all electrical / env / nav fields                    │
│  │   ├── POST JSON ──────────────────────────────────────────────► │
│  │   └── POST line protocol ──────────────────────────────────── ► │
│  │                                                                  │
│  └── Tab 2: Venus Reboot                                            │
│      ├── D-Bus exec → get portal ID                                 │
│      ├── MQTT keepalive → R/{portalId}/system/0/Serial             │
│      ├── MQTT subscribe → N/+/solarcharger/+/DeviceInstance        │
│      ├── HTTP POST /reboot → victron-output-custom D-Bus write      │
│      └── HTTP POST /restart-mppt → MQTT W/{portalId}/solarcharger  │
│                │ MQTT (localhost:1883)                               │
└────────────────┼────────────────────────────────────────────────────┘
                 │
    ┌────────────┴──────────────────────────────────────┐
    │  Server  (192.168.0.3)                            │
    │                                                   │
    │  PHP :9999                                        │
    │  ├── POST /  → latest.json + solar.log            │
    │  ├── GET  /  → HTML dashboard (cards + charts)    │
    │  └── GET  /?json → raw latest JSON                │
    │                                                   │
    │  InfluxDB :8086                                   │
    │  └── org=solar  bucket=victron                    │
    │       └── measurements: solar_charger, battery,  │
    │           inverter, environment, tanks, navigation│
    │                                                   │
    │  Grafana :3001                                    │
    │  └── auto-provisioned → InfluxDB datasource       │
    └───────────────────────────────────────────────────┘
```

**Key points:**
- The Pi **pushes** to the server — the server never pulls from the Pi. This means the Pi can be behind NAT.
- PHP reads only local files (`latest.json`, `solar.log`) — no database dependency for the basic dashboard.
- InfluxDB/Grafana are optional extras for long-term trend analysis. The PHP dashboard works without them.
- The Node-RED HTTP server (`192.168.0.2:1881`) receives reboot/restart commands from the PHP dashboard's JavaScript buttons.

---

## 4. File Inventory

### `index.php` — Main receiver + dashboard

The single PHP file that does everything:

- **POST handler** (lines 9–41): validates `X-Api-Key` header, decodes JSON body, stamps `received_at`, writes `latest.json` (overwrite) and `solar.log` (append + rotate).
- **GET `/?json` handler** (lines 45–49): returns raw `latest.json` as pretty-printed JSON.
- **Helper functions** (lines 62–145): `has_any_data()`, `latest_data()`, `rotate_log()`, `row()`, `badge_mode()`, `section()`, `open_card()`, `big()`, `close_card()`, `has_non_null()`.
- **Dashboard HTML** (lines 147+): dark-themed UI with inline CSS, card grid sections for each device type.
- **Chart data prep** (lines 460–494): reads last 288 lines of `solar.log`, extracts time-series arrays for Chart.js.
- **Chart.js section** (lines 496–687): renders up to 7 historical line charts, with colour-by-sign for current charts.
- **Control buttons** (lines 704–733): "Reboot Venus OS" (`POST http://192.168.0.2:1881/reboot`) and "Restart MPPT" (`POST http://192.168.0.2:1881/restart-mppt`), each with a `confirm()` dialog.

### `config.php` — Configuration

```php
define('PUSH_URL',      'http://192.168.0.3:9999/');   // shown in "no data" error
define('API_KEY',       'change-me-to-a-secret-key');  // must match Node-RED header
define('LOG_MAX_LINES', 2880);                          // ~24 h at 30-second intervals
```

### `nodered-flow.json` — Node-RED flow export

Contains two tabs and one shared MQTT broker config. Import via Node-RED UI → Menu → Import. See [Section 6](#6-node-red-flow-details) for full details.

### `docker-compose.yml` — InfluxDB 2 + Grafana stack

Runs on the server (`192.168.0.3`):

| Service | Image | Host port | Notes |
|---|---|---|---|
| `influxdb` | `influxdb:2` | `8086` | Auto-initialises org `solar`, bucket `victron` |
| `grafana` | `grafana/grafana:latest` | `3001` | Port 3001 avoids clash with Signal K on 3000 |

Credentials: `admin` / `changeme123` for both. Change before production use.

Named volumes: `influxdb-data`, `influxdb-config`, `grafana-data`.

### `grafana/provisioning/datasources/influxdb.yaml` — Datasource provisioning

Auto-provisions an InfluxDB datasource into Grafana on first start. Uses the **Flux** query language. Connects to `http://influxdb:8086` (Docker internal hostname). Contains the `INFLUX_TOKEN_HERE` placeholder (place 2 of 3).

### `latest.json` — Last received payload *(runtime, not in git)*

Written atomically (via `LOCK_EX`) on every successful POST. Holds the most recent full payload. Read by the dashboard on every page load.

### `solar.log` — Rolling 24-hour log *(runtime, not in git)*

One JSON object per line, appended on each POST. Rotated to keep the last `LOG_MAX_LINES` (2880) lines. The chart section reads the last 288 lines (the most recent ~2.4 hours if pushes are every 30 s, or up to 24 h if 5-minute intervals are used).

---

## 5. Data Payload Structure

Node-RED POSTs this JSON to `POST /`. PHP writes it to `latest.json` and `solar.log` (with `received_at` added by PHP).

```json
{
  "timestamp": "2026-06-12 11:30:00",
  "portalId": "abc123def456",
  "received_at": "2026-06-12 11:30:01",

  "solar": {
    "290": {
      "panelVoltage_V":    18.5,
      "panelCurrent_A":    2.1,
      "panelPower_W":      38.85,
      "outputVoltage_V":   14.12,
      "outputCurrent_A":   2.7,
      "outputPower_W":     38.12,
      "yieldToday_Wh":     210.5,
      "yieldYesterday_Wh": 450.0,
      "yieldTotal_Wh":     15200.0,
      "maxPowerToday_W":   75.0,
      "controllerMode":    5,
      "errorCode":         0,
      "chargeState":       null,
      "temperature_C":     35.2
    }
  },

  "batteries": {
    "512": {
      "voltage_V":             13.95,
      "current_A":             2.5,
      "power_W":               34.88,
      "stateOfCharge_pct":     82.0,
      "timeRemaining_min":     540.0,
      "consumedAh":            18.5,
      "capacity_Ah":           100.0,
      "temperature_C":         28.1,
      "midpointVoltage_V":     null,
      "midpointDeviation_pct": null,
      "starterVoltage_V":      null,
      "chargeEfficiency_pct":  null,
      "lowVoltageAlarm":       false,
      "highVoltageAlarm":      false
    }
  },

  "inverters": {
    "276": {
      "operatingMode": 3,
      "state":         "Bulk",
      "activeInput":   0,
      "dc": {
        "voltage_V": 13.95,
        "current_A": 5.2,
        "power_W":   72.5
      },
      "acIn": {
        "L1": {
          "voltage_V": 230.0, "current_A": 0.0,
          "frequency_Hz": 50.0, "power_W": 0.0,
          "apparentPower_VA": 0.0, "powerFactor": null
        }
      },
      "acOut": {
        "L1": {
          "voltage_V": 230.1, "current_A": 0.5,
          "frequency_Hz": 50.0, "power_W": 115.0
        }
      }
    }
  },

  "acGrid": {
    "L1": {
      "voltage_V":    230.0,
      "current_A":    2.1,
      "frequency_Hz": 50.02,
      "power_W":      483.0,
      "energy_kWh":   1.24
    }
  },

  "acLoads": {
    "L1": {
      "voltage_V":    229.8,
      "current_A":    1.8,
      "frequency_Hz": 50.0,
      "power_W":      414.0
    }
  },

  "genset": {
    "L1": {
      "voltage_V":    230.0,
      "current_A":    0.0,
      "frequency_Hz": null,
      "power_W":      null,
      "runtime_h":    12.5,
      "running":      false
    }
  },

  "dc": {
    "systemVoltage_V": 13.95,
    "systemCurrent_A": 1.2,
    "systemPower_W":   16.74
  },

  "tanks": {
    "0": {
      "type":        "Fresh water",
      "level_pct":   65.0,
      "capacity_L":  120.0,
      "remaining_L": 78.0
    }
  },

  "environment": {
    "inside":  { "temperature_C": 24.5, "humidity_pct": 55.0 },
    "outside": { "temperature_C": 18.2, "humidity_pct": 70.0, "pressure_hPa": 1013.25 },
    "water":   { "temperature_C": 19.8 },
    "solar":   { "irradiance_Wm2": null }
  },

  "navigation": {
    "latitude":              -33.8688,
    "longitude":             151.2093,
    "speedOverGround_ms":    0.0,
    "courseOverGround_deg":  null,
    "headingTrue_deg":       null,
    "headingMagnetic_deg":   null,
    "altitude_m":            12.0,
    "satellites":            8,
    "fixType":               "GNSS Fix"
  }
}
```

**Unit conventions** (enforced in the `parse-payload` Node-RED function node):

| Signal K unit | Converted to |
|---|---|
| Kelvin (K) | °C via `K2C(v) = v - 273.15` |
| Joules (J) | Wh via `J2Wh(v) = v / 3600` |
| Fraction (0–1) | % via `frac2pct(v) = v * 100` |
| Radians | ° via `rad2deg(v) = v * 180 / π` |
| m³ | L via `m32L(v) = v * 1000` |
| Seconds | minutes via `sec2min`, hours via `sec2h` |
| Coulombs (C) | Ah via `C2A(v) = v / 3600` |

All numeric values are rounded to 2 decimal places (`r2()`). Missing Signal K fields produce `null` (not omitted), so the PHP dashboard can distinguish "device present but value unknown" from "no device".

---

## 6. Node-RED Flow Details

### Tab 1: Solar Push (`solar-tab`)

**Purpose:** Poll Signal K every 30 seconds, parse all data, push to PHP endpoint and InfluxDB.

| Node | Type | Role |
|---|---|---|
| `Every 30 s` | inject | Timer — fires immediately on deploy (2 s delay), then every 30 s |
| `GET Signal K vessels/self` | http request | `GET http://localhost:3000/signalk/v1/api/vessels/self` |
| `Parse everything` | function | Transforms raw Signal K tree into the structured payload (see Section 5). Reads `flow.get('portalId')` set by Tab 2 |
| `Set headers` | function | Adds `Content-Type: application/json` and `X-Api-Key` header |
| `POST to PHP endpoint` | http request | `POST http://192.168.0.3:9999/` |
| `Format InfluxDB line protocol` | function | Converts payload to InfluxDB line protocol strings; sets Bearer token header |
| `POST to InfluxDB v2` | http request | `POST http://192.168.0.3:8086/api/v2/write?org=solar&bucket=victron&precision=ns` |
| `Payload preview` | debug | Logs parsed payload to Node-RED sidebar |
| `Server response` | debug | Shows PHP endpoint HTTP response |
| `InfluxDB response` | debug | Shows InfluxDB write response (204 = success) |

**Wire diagram (simplified):**
```
[Every 30 s] → [GET Signal K] → [Parse everything] ─┬─ [Set headers] → [POST PHP] → [debug]
                                                      └─ [Format InfluxDB] → [POST InfluxDB] → [debug]
```

**InfluxDB measurements written:**

| Measurement | Tags | Fields |
|---|---|---|
| `solar_charger` | `id` (charger instance) | All numeric fields from solar charger object |
| `battery` | `id` (battery instance) | All numeric fields from battery object |
| `inverter` | `id` (inverter instance) | `voltage_V`, `current_A`, `power_W` (DC only) |
| `environment` | _(none)_ | `inside_temperature_C`, `outside_temperature_C`, `outside_pressure_hPa`, etc. |
| `tanks` | `id`, `type` | `level_pct`, `remaining_L` |
| `navigation` | _(none)_ | `lat`, `lon`, `speed`, `satellites` |

Timestamps use nanosecond precision (`Date.now() + '000000'`).

---

### Tab 2: Venus Reboot (`venus-reboot-tab`)

**Purpose:** Scheduled/manual/HTTP-triggered reboot of Venus OS; device-agnostic MPPT charger restart via MQTT; portal ID and device instance discovery.

#### Reboot sub-flow

| Node | Type | Role |
|---|---|---|
| `Scheduled reboot (Mon 5am)` | inject | Cron `00 05 * * 1` — weekly Monday 5 AM |
| `Manual reboot (trigger once)` | inject | Click once in Node-RED UI to reboot immediately |
| `HTTP reboot trigger` | http in | `POST /reboot` — called by dashboard button |
| `Trigger reboot` | trigger | Debounces multiple triggers; outputs `1` |
| `Reboot Venus OS` | victron-output-custom | Writes `1` to D-Bus `com.victronenergy.platform/0 /Device/Reboot` |
| `Set response` | function | Returns `{ status: "rebooting" }` |
| `HTTP 200 rebooting` | http response | Sends 200 back to the caller |

#### Device discovery sub-flow

| Node | Type | Role |
|---|---|---|
| `Discover devices on startup` | inject | Fires once 2 s after deploy, then every 60 s |
| `Get portal ID (D-Bus)` | exec | Runs `dbus-send --system --print-reply --dest=com.victronenergy.system /Serial com.victronenergy.BusItem.GetValue` |
| `Parse portal ID` | function | Regex-extracts hex ID from D-Bus output; stores in `flow.set('portalId', id)` |
| `Build keepalive` | function | Constructs topic `R/{portalId}/system/0/Serial` |
| `Wait 1 s` | delay | Gives MQTT broker time to register the keepalive before the device list request |
| `Keepalive → Venus MQTT` | mqtt out | Publishes keepalive; triggers Venus OS to announce devices |
| `Request device list` | function | Constructs topic `R/{portalId}/solarcharger/+/DeviceInstance` |
| `Request charger list` | mqtt out | Publishes the request |
| `Listen for chargers` | mqtt in | Subscribes `N/+/solarcharger/+/DeviceInstance` |
| `Store charger instance` | function | Extracts instance ID from topic; appends to `flow.get('chargers')` array; also captures `portalId` from topic |
| `Listen for batteries` | mqtt in | Subscribes `N/+/battery/+/DeviceInstance` |
| `Store battery instance` | function | Stores battery instance ID in `flow.get('batteries')` |

#### MPPT restart sub-flow

| Node | Type | Role |
|---|---|---|
| `Restart MPPT (all chargers)` | inject | Manual trigger from Node-RED UI |
| `HTTP restart MPPT` | http in | `POST /restart-mppt` — called by dashboard button |
| `Build MPPT OFF command` | function | Reads `flow.get('portalId')` and `flow.get('chargers')[0]`; publishes `W/{portalId}/solarcharger/{instance}/Mode` with `{"value": 4}` (OFF) |
| `MPPT → OFF via MQTT` | mqtt out | Sends the OFF command |
| `Wait 5 s` | delay | Pause between OFF and ON |
| `Build MPPT ON command` | function | Same topic, payload `{"value": 1}` (ON) |
| `MPPT → ON via MQTT` | mqtt out | Sends the ON command |
| Debug nodes (×5) | debug | Log each step: triggered, OFF built, OFF dispatched, ON built, ON dispatched |

---

## 7. Device-Agnostic Design

The flow contains **zero hardcoded Victron device IDs**. The same `nodered-flow.json` deploys identically to any Venus OS device.

### Bootstrap sequence (on flow deploy)

```
1. [Discover devices on startup] fires after 2 s
        │
        ▼
2. [dbus-exec] runs:
   dbus-send --system --print-reply \
     --dest=com.victronenergy.system \
     /Serial com.victronenergy.BusItem.GetValue
   → stdout: "variant   string \"abc123def456\""
        │
        ▼
3. [Parse portal ID] extracts "abc123def456"
   → flow.set('portalId', 'abc123def456')
        │
        ▼
4. [Build keepalive] → topic: R/abc123def456/system/0/Serial
        │
        ▼  (1 s delay)
5. [Keepalive → Venus MQTT] publishes keepalive
   Venus OS responds by announcing all connected devices
        │
        ▼
6. [Request device list] publishes to
   R/abc123def456/solarcharger/+/DeviceInstance
        │
        ▼
7. [Listen for chargers] receives:
   N/abc123def456/solarcharger/290/DeviceInstance → value 290
   → flow.set('chargers', ['290'])

8. [Listen for batteries] receives similarly
   → flow.set('batteries', '512')
```

**Why D-Bus exec (not MQTT) for the portal ID bootstrap?**  
MQTT topics require the portal ID (e.g. `R/{portalId}/...`). You can't subscribe to discover the portal ID via MQTT itself — that's a chicken-and-egg problem. D-Bus exec sidesteps this by querying `com.victronenergy.system /Serial` directly on the local system bus. This always works before MQTT is even connected.

**Discovery repeats every 60 seconds** to handle cases where a charger is powered on after the flow starts.

### MPPT Mode values (Victron D-Bus/MQTT)

| Value | Meaning |
|---|---|
| `1` | On (normal operation) |
| `4` | Off (charger disabled) |

The restart procedure: write `4` (OFF) → wait 5 s → write `1` (ON). Tested on SmartSolar MPPT 75/10 rev2.

---

## 8. PHP Dashboard Features

### Three render states

| State | Condition | UI shown |
|---|---|---|
| **No data** | `latest.json` doesn't exist or has ≤ 2 keys | Red error box: "No data received yet. Import nodered-flow.json..." |
| **Connected, no devices** | Payload received but all device arrays are empty | Green "Connected" info box with Pi timestamp and raw JSON expander |
| **Full dashboard** | At least one device section has data | All applicable card sections + history charts |

### Stale data banner

If `received_at` is more than **3600 seconds** (1 hour) ago, a yellow warning banner appears at the top:

> ⚠ Last push was X hours ago — Pi may be offline or Node-RED flow stopped

The same warning also appears inline in the timestamp footer.

### Live card sections

Each section only renders if the corresponding data key is non-empty. Cards use a responsive `auto-fill` grid (min 260 px wide).

| Section | Icon | Data key | Card label |
|---|---|---|---|
| Solar Chargers | ☀ | `solar` | "MPPT — {id}" |
| Battery Banks | 🔋 | `batteries` | "Battery — {id}" |
| Inverter / Charger | ⚡ | `inverters` | "Inverter — {id}" |
| AC Grid / Shore | 🏠 | `acGrid` | "Phase — {ph}" |
| AC Loads / Output | 💡 | `acLoads` | "Phase — {ph}" |
| Generator | ⛲ | `genset` | "Phase — {ph}" |
| DC System | 🔌 | `dc` | "DC System load" |
| Tanks | 💧 | `tanks` | "{type} — {id}" |
| Environment | 🌡 | `environment` | "Sensors" |
| Navigation / GPS | 📍 | `navigation` | "Position" |

### Charger mode badges

The `badge_mode()` function maps numeric Victron controller modes to colour-coded pills:

| Mode | Label | Colour |
|---|---|---|
| 0, 1, 11, 245 | Off / Low power / Power supply / Wake-up | Grey |
| 2 | Fault | Red |
| 3, 252 | Bulk / External ctrl | Blue |
| 4 | Absorption | Purple |
| 5, 6 | Float / Storage | Green |
| 7, 247 | Equalise / Auto equalise | Amber |
| 9 | Inverting | Teal |

### Historical charts (Chart.js)

Reads the last **288 lines** of `solar.log`. Data is taken from the **first** solar charger and **first** battery in each log entry.

| Chart | Dataset | Colour |
|---|---|---|
| Charger Output Voltage (V) | `solar[0].outputVoltage_V` | Blue |
| Charger Output Current (A) | `solar[0].outputCurrent_A` | Green (positive) / Red (negative) — segment coloring |
| Solar Power (W) | `solar[0].panelPower_W` | Yellow |
| Battery State of Charge (%) | `batteries[0].stateOfCharge_pct` | Green, Y-axis fixed 0–100 |
| Battery Voltage (V) | `batteries[0].voltage_V` | Blue |
| Battery Current (A) | `batteries[0].current_A` | Green (positive) / Red (negative) — segment coloring |
| Yield Today (Wh) | `solar[0].yieldToday_Wh` | Purple |

Charts with all-null data are suppressed (not rendered). `spanGaps: false` means gaps in the line where data was null. `pointRadius: 0` for clean lines. `tension: 0.3` for smooth curves.

### Control buttons

Located in the timestamp footer at the bottom of the page:

| Button | Endpoint | Confirmation dialog |
|---|---|---|
| 🔮 Reboot Venus OS | `POST http://192.168.0.2:1881/reboot` | "Are you sure you want to reboot the Venus OS device? This will disconnect all devices for ~60 seconds." |
| ↺ Restart MPPT | `POST http://192.168.0.2:1881/restart-mppt` | "Restart MPPT charger 290? It will go offline for ~5 seconds then resume charging." |

Both use `mode: 'no-cors'` so they work even when the Pi's Node-RED doesn't return CORS headers. Both disable themselves and show a status message after sending.

---

## 9. API Reference

### PHP server (`192.168.0.3:9999`)

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/` | None | HTML dashboard (auto-refreshes every 35 s) |
| `GET` | `/?json` | None | Raw `latest.json` as pretty-printed JSON |
| `POST` | `/` | `X-Api-Key` header | Receive push from Node-RED; returns `{"status":"ok","received_at":"..."}` |

**POST request format:**
```
POST / HTTP/1.1
Content-Type: application/json
X-Api-Key: <your-secret-key>

{ ...full payload JSON... }
```

**POST error responses:**
- `401` — missing or incorrect `X-Api-Key`
- `400` — body is not valid JSON

### Node-RED HTTP server on Venus OS (`192.168.0.2:1881`)

| Method | Path | Description |
|---|---|---|
| `POST` | `/reboot` | Triggers Venus OS reboot via D-Bus; returns `{"status":"rebooting"}` |
| `POST` | `/restart-mppt` | Triggers MPPT OFF→5s→ON cycle via MQTT; returns `{"status":"restarting","chargers":["290"]}` |

---

## 10. Setup Instructions

### Prerequisites

- A Venus OS Raspberry Pi on your LAN (e.g. `192.168.0.2`), with Node-RED and Signal K running
- A server (e.g. `192.168.0.3`) with PHP 8 CLI and Docker + Docker Compose

### Step 1 — Generate a secret token

Run this on any machine with OpenSSL:

```bash
openssl rand -hex 32
```

Copy the output (e.g. `a1b2c3d4e5f6...`). This is used as both the API key and the InfluxDB token.

### Step 2 — Replace `INFLUX_TOKEN_HERE` in all three places

| File | Location |
|---|---|
| `docker-compose.yml` | `DOCKER_INFLUXDB_INIT_ADMIN_TOKEN: INFLUX_TOKEN_HERE` |
| `grafana/provisioning/datasources/influxdb.yaml` | `token: INFLUX_TOKEN_HERE` |
| `nodered-flow.json` | `const INFLUX_TOKEN = 'INFLUX_TOKEN_HERE';` in the `format-influx` function node |

> The InfluxDB token must be at least 32 characters. The generated hex string is exactly 64 characters — ideal.

### Step 3 — Set the API key in `config.php`

```php
define('API_KEY', 'your-generated-token-here');
```

### Step 4 — Set the API key in `nodered-flow.json`

In the `set-headers` function node, replace:

```javascript
'X-Api-Key': 'change-me-to-a-secret-key'
```

with the same token value. You can do this either by editing the JSON file or after importing into Node-RED.

### Step 5 — Verify/update IP addresses

If your server is not at `192.168.0.3` or the PHP port is not `9999`:

- In `config.php`: update `PUSH_URL`
- In `nodered-flow.json`: find-replace `192.168.0.3:9999` with your server's address
- In `nodered-flow.json`: find-replace `192.168.0.3:8086` with your InfluxDB address

If your Venus OS Pi is not at `192.168.0.2:1881`:

- In `index.php`: update the two `fetch('http://192.168.0.2:1881/...')` calls in the control button JS

### Step 6 — Start the PHP server

On the server machine, from the project directory:

```bash
php -S 0.0.0.0:9999 index.php
```

Or configure a proper PHP web server (nginx + php-fpm, Apache, etc.). The built-in CLI server is fine for home use.

### Step 7 — Start InfluxDB + Grafana

```bash
docker compose up -d
```

- InfluxDB UI: `http://192.168.0.3:8086` (admin / changeme123)
- Grafana: `http://192.168.0.3:3001` (admin / changeme123)

The InfluxDB datasource in Grafana is auto-provisioned. Create dashboards using Flux queries against org `solar`, bucket `victron`.

### Step 8 — Import the Node-RED flow on each Venus OS Pi

1. Open Node-RED on the Pi: `http://192.168.0.2:1880`
2. Menu (≡) → Import
3. Upload `nodered-flow.json` or paste its contents
4. Click **Deploy**

The flow starts immediately. The Solar Push tab will begin pushing every 30 seconds. The Venus Reboot tab will discover the portal ID and charger/battery instances within the first few seconds.

### Step 9 — Verify

- Open `http://192.168.0.3:9999/` in a browser
- Within 30 seconds you should see live data cards
- Open `http://192.168.0.3:9999/?json` to inspect the raw payload
- After a few minutes, open the History section to confirm charts are populating

---

## 11. Known Issues & Notes

### InfluxDB not yet started
InfluxDB is not running until you execute `docker compose up -d`. Node-RED will log HTTP errors for the InfluxDB write until then, but this does not affect the PHP dashboard (which uses `latest.json`/`solar.log` only).

### MPPT restart: Mode write mechanics
The MPPT restart uses MQTT writes to the D-Bus `Mode` path via the Victron MQTT bridge. Mode values: `4` = Off, `1` = On (normal). The 5-second delay between OFF and ON is intentional — too short and some firmware versions don't fully power-cycle the MPPT. Tested on **SmartSolar MPPT 75/10 rev2**.

### Portal ID discovery: D-Bus exec
D-Bus exec is used (not MQTT) to bootstrap the portal ID because MQTT topic subscriptions require the portal ID — using MQTT to discover it creates a chicken-and-egg problem. The `dbus-send` command only works if Node-RED runs on the Venus OS Pi itself (it requires access to the system D-Bus socket).

### Pi clock drift
`solar.log` entries contain two timestamps:
- `timestamp` — set by Node-RED on the Pi (`new Date()`)
- `received_at` — set by PHP on the server (`date('Y-m-d H:i:s')`)

If the Pi's clock is wrong (common if NTP is unavailable), `timestamp` may lag or jump. The chart section uses `timestamp` for the X-axis labels, so a bad Pi clock causes incorrect time labels. The dashboard footer shows both timestamps so you can detect this.

### Log rotation
`solar.log` is rotated by PHP on every POST: it reads all lines into memory and keeps only the last `LOG_MAX_LINES` (2880). At 30-second push intervals, 2880 lines = 24 hours. If pushes are less frequent, the window covers more time. This naive rotation reads the whole file on every push — acceptable for small files (~2880 lines × ~1 KB = ~3 MB max).

### Hardcoded IP addresses
The following addresses are baked into `nodered-flow.json` and `index.php`:

| Address | Used in | Purpose |
|---|---|---|
| `192.168.0.3:9999` | `nodered-flow.json` (2 nodes) | PHP endpoint for data push |
| `192.168.0.3:8086` | `nodered-flow.json` (1 node) | InfluxDB write endpoint |
| `192.168.0.2:1881` | `index.php` (2 JS fetch calls) | Node-RED HTTP triggers for reboot/MPPT |

Find-replace these when deploying to a different network.

### MPPT restart confirm dialog
The confirmation dialog in `index.php` still says "charger 290" (hardcoded string). This does not affect functionality but should be updated if your charger instance ID differs.

### Grafana port conflict
Grafana runs on host port `3001` (not `3000`) because Signal K typically occupies `3000` on the same server. If Signal K is not on your server, you can change `3001:3000` to `3000:3000` in `docker-compose.yml`.

### Security
- The API key in `config.php` defaults to `'change-me-to-a-secret-key'` — always change this.
- The PHP server has no HTTPS. For local LAN use this is acceptable; for internet-facing deployments, put it behind a reverse proxy with TLS.
- InfluxDB and Grafana credentials default to `admin` / `changeme123` — change before production use.
- The `GET /?json` endpoint is unauthenticated — anyone who can reach the server can read the latest payload.
