# SmartGrow — Greenhouse IoT Monitor
# Greenhouse-Project

> **ESP32 edge sensors → FOG local server → Azure cloud**  
> Automated climate monitoring and relay control for Chile pepper cultivation — Galle, Sri Lanka

![System Architecture](https://github.com/user-attachments/assets/56e4ab67-e905-4e77-8d19-8efcdb299ba6)

---

## Table of Contents

- [Overview](#overview)
- [System Architecture](#system-architecture)
- [Features](#features)
- [Hardware Requirements](#hardware-requirements)
- [Wiring Diagram](#wiring-diagram)
- [Software Stack](#software-stack)
- [Project Structure](#project-structure)
- [Database Setup](#database-setup)
- [Server Setup (FOG Node)](#server-setup-fog-node)
- [ESP32 Firmware Setup](#esp32-firmware-setup)
- [Adding a New Zone Device](#adding-a-new-zone-device)
- [Cloud Layer (Azure)](#cloud-layer-azure)
- [Relay Trigger Logic](#relay-trigger-logic)
- [Sensor Thresholds](#sensor-thresholds)
- [Dashboard Pages](#dashboard-pages)
- [User Roles](#user-roles)
- [Weather Integration](#weather-integration)
- [Configuration Reference](#configuration-reference)
- [API Reference](#api-reference)

---

## Overview

SmartGrow is a three-layer IoT platform for greenhouse climate monitoring and automated control. Physical ESP32 sensor boards inside each greenhouse zone post readings every 15 seconds to a local PHP/MySQL server (the FOG node). The server runs a live dashboard, monitors device health, and exports sensor records as labelled CSV files to Azure for cloud-level analysis.

A dual-channel HW-383 relay on each ESP32 board controls a **sprinkler pump** (humidity) and a **cooling fan** (temperature) automatically — with no cloud dependency for physical device control. Relay logic runs entirely on the ESP32 itself.

```
ESP32 (Zone A, B, C)
       │  HTTP POST every 15 s
       ▼
FOG Node — WAMP / PHP / MySQL          ←── Browser dashboard
       │  CSV export (labelled FOG-Galle)
       ▼
Azure Web Server — storage + analytics
```

---

## System Architecture

| Layer | Component | Role |
|-------|-----------|------|
| **Edge** | ESP32 + DHT22 + Gas sensor + HW-383 relay | Read sensors, control relay, post data |
| **FOG** | WAMP (Apache + PHP + MySQL) on local PC | Receive data, serve dashboard, export CSV |
| **Cloud** | Azure Web Server | Store CSV files, cross-farm analytics |

Multiple FOG nodes (e.g. FOG-Galle, FOG-Matara) can each push their own labelled CSV files to the same Azure endpoint, enabling management of distributed greenhouse farms from one cloud dashboard.

---

## Features

### Edge (ESP32)
- Reads DHT22 temperature + humidity and analog gas sensor every loop
- Posts to FOG server every **15 seconds** via HTTP POST
- **Autonomous dual relay control** — no server required for physical actions
- CH1 sprinkler pump triggered by sustained high humidity (78% × 5 min)
- CH2 cooling fan triggered by sustained high temperature (32°C × 5 min)
- Both channels independent — can run simultaneously
- Boot-glitch prevention — relay pins set HIGH before `pinMode()` to avoid clicking on during ESP32 boot
- Auto-reconnects WiFi if connection drops
- 16×2 LCD shows live readings and relay state (`SP:ON/OFF FN:ON/OFF`)
- Sends relay state, run time, and countdown to FOG with every POST

### FOG Dashboard
- **Live dashboard** — sensor reading tiles, dual relay tiles, charts, 24-hour summary
- **Unified alert panel** — device offline, relay activation, sensor warnings, weather alerts — all in one block
- **Device status chips** — online/offline indicator per ESP32 with time since last reading
- **Offline popup** — appears automatically if any registered device stops posting for 5 minutes
- **Relay tiles** — live progress bar with threshold marker, animated running indicator, session run time, 24-hour activation count
- **Weather section** — current outdoor conditions, 6-hour hourly tiles, 3-day outlook (structural risk only — wind, storm, outdoor humidity)
- **Sensor Records** — paginated log with filter by condition, date, source
- **Device Management** — register/edit/disable ESP32 devices, per-device API keys, auto-generated sketch snippet
- **Weekly email report** — danger episode tiles, peak readings, day-by-day summary via PHPMailer
- **CSV export** — download sensor data by date range
- **Users** — add/delete users, owner/helper roles, password management
- **Auto-refresh** — dashboard reloads every 15 seconds

### Cloud (Azure)
- CSV files pushed from FOG node, named with FOG identifier
- Multiple files per FOG node (one per export period or device batch)
- Cross-farm analytics across multiple FOG deployments

---

## Hardware Requirements

### Per ESP32 Zone Board

| Component | Spec | Notes |
|-----------|------|-------|
| ESP32 development board | Any 30-pin variant | 38-pin also works |
| DHT22 sensor | Temperature + humidity | With 10kΩ pull-up to 3.3V on data pin |
| Gas / air quality sensor | MQ-series analog (0–4095 ADC) | Connected to GPIO34 |
| HW-383 2-channel relay module | 5V coil, active LOW | Sprinkler + cooling fan |
| 16×2 LCD with I2C backpack | 0x27 address | Frank de Brabander library |
| Resistor | 10kΩ | DHT22 data line pull-up |
| Power supply | 5V / min 2A | Relay coils need adequate current |

### FOG Node (per farm)

| Component | Notes |
|-----------|-------|
| Windows PC | Running WAMP server |
| WiFi router / access point | ESP32 boards connect here |
| Network switch | Central switch for multiple zone APs |
| UPS (recommended) | Prevents data loss during power cuts |

---

## Wiring Diagram

```
ESP32 GPIO    Component
──────────    ─────────────────────────────────────
GPIO 4    →   DHT22 DATA pin  (10kΩ pull-up to 3.3V)
GPIO 34   →   Gas sensor AOUT (ADC, 0–4095)
GPIO 21   →   LCD SDA
GPIO 22   →   LCD SCL
GPIO 5    →   HW-383 IN1  ←  Sprinkler pump relay (CH1)
GPIO 18   →   HW-383 IN2  ←  Cooling fan relay    (CH2)
5V        →   HW-383 VCC
GND       →   HW-383 GND

HW-383 relay is ACTIVE LOW:
  LOW  signal on INx  →  relay coil energised  →  device ON
  HIGH signal on INx  →  relay coil off         →  device OFF
```

> **Important:** Both relay pins are written `HIGH` in firmware *before* `pinMode()` is called. This prevents the relay from briefly clicking on during the ESP32 boot sequence.

---

## Software Stack

| Layer | Technology |
|-------|-----------|
| Edge firmware | Arduino C++ (ESP32 Arduino core) |
| FOG web server | Apache (via WAMP) |
| FOG application | PHP 8.x |
| FOG database | MySQL 8.x (via WAMP) |
| Email reports | PHPMailer (Gmail SMTP TLS) |
| Weather data | Open-Meteo API (free, no key required) |
| Charts | Chart.js 4.4 (CDN) |
| Font | Inter (Google Fonts) |
| Cloud storage | Azure Web Server / Blob Storage |

---

## Project Structure

```
esp32_code/
├── esp32_greenhouse.ino    # Arduino firmware v7.0
greenhouse/
├── db.php                  # Database config, weather functions, thresholds
├── includes.php            # Shared CSS, navigation, status helper functions
├── auth.php                # Session authentication, requireLogin(), requireOwner()
├── login.php               # Sign-in page (owner / helper role)
├── logout.php              # Session destroy + redirect
├── dashboard.php           # Live dashboard — sensors, relays, weather, charts
├── log.php                 # Paginated sensor records with filter
├── devices.php             # ESP32 device registry (owner only)
├── manual.php              # Add a manual reading (owner only)
├── export.php              # Download sensor data as CSV (owner only)
├── weekly_report.php       # Send weekly email report via PHPMailer (owner only)
├── admin.php               # Delete records by date / condition / all (owner only)
├── users.php               # Add / delete users, change role / password (owner only)
├── api.php                 # ESP32 HTTP POST endpoint — no session auth
├── setup_users.php         # One-time user seeding — DELETE AFTER USE
├── PHP Mailer/             # Mail Cofigure API Folder
├── csv_upload/             # Cloud communication files
---

## Database Setup

Run the following SQL **once** in phpMyAdmin before first use.  
The complete SQL is also in the comment block at the bottom of `db.php`.

### New installation

```sql
CREATE DATABASE IF NOT EXISTS greenhouse
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE greenhouse;

-- Edge device registry
CREATE TABLE IF NOT EXISTS edge_devices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    device_id     VARCHAR(40)  NOT NULL UNIQUE,
    display_name  VARCHAR(100) NOT NULL,
    location_desc VARCHAR(255) DEFAULT NULL,
    api_key       VARCHAR(64)  NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen     DATETIME     DEFAULT NULL,
    last_ip       VARCHAR(45)  DEFAULT NULL
) ENGINE=InnoDB;

-- Sensor + relay log
CREATE TABLE IF NOT EXISTS sensor_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    device_id        VARCHAR(40)  DEFAULT 'DEFAULT',
    recorded_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    temperature      DECIMAL(5,2) NOT NULL,
    humidity         DECIMAL(5,2) NOT NULL,
    gas              INT          NOT NULL,
    temp_status      ENUM('NORMAL','WARNING','DANGER') NOT NULL DEFAULT 'NORMAL',
    hum_status       ENUM('NORMAL','WARNING','DANGER') NOT NULL DEFAULT 'NORMAL',
    gas_status       ENUM('NORMAL','WARNING','DANGER') NOT NULL DEFAULT 'NORMAL',
    motor_on         TINYINT(1)   NOT NULL DEFAULT 0,
    motor_run_sec    INT          NOT NULL DEFAULT 0,
    motor_pending_sec INT         NOT NULL DEFAULT 0,
    ch2_on           TINYINT(1)   NOT NULL DEFAULT 0,
    ch2_run_sec      INT          NOT NULL DEFAULT 0,
    ch2_pending_sec  INT          NOT NULL DEFAULT 0,
    trigger_reason   VARCHAR(16)  NOT NULL DEFAULT 'none',
    source           ENUM('esp32','manual') NOT NULL DEFAULT 'esp32',
    notes            VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

-- Users
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(60)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('owner','helper') NOT NULL DEFAULT 'helper',
    full_name  VARCHAR(100) DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Register the first device
INSERT IGNORE INTO edge_devices (device_id, display_name, location_desc, api_key)
VALUES ('ESP32-ZONE-A', 'Zone A — Main Sensor', 'Primary greenhouse sensor node', 'your API KEY');
```

### Upgrading an existing sensor_log table

If your `sensor_log` table exists from a previous version (without relay columns):

```sql
ALTER TABLE sensor_log
  ADD COLUMN motor_on          TINYINT(1)  NOT NULL DEFAULT 0    AFTER gas_status,
  ADD COLUMN motor_run_sec     INT         NOT NULL DEFAULT 0    AFTER motor_on,
  ADD COLUMN motor_pending_sec INT         NOT NULL DEFAULT 0    AFTER motor_run_sec,
  ADD COLUMN ch2_on            TINYINT(1)  NOT NULL DEFAULT 0    AFTER motor_pending_sec,
  ADD COLUMN ch2_run_sec       INT         NOT NULL DEFAULT 0    AFTER ch2_on,
  ADD COLUMN ch2_pending_sec   INT         NOT NULL DEFAULT 0    AFTER ch2_run_sec,
  ADD COLUMN trigger_reason    VARCHAR(16) NOT NULL DEFAULT 'none' AFTER ch2_pending_sec,
  ADD INDEX  idx_motor_on  (motor_on),
  ADD INDEX  idx_ch2_on    (ch2_on);
```

If you also need to add `device_id` (older installs):

```sql
ALTER TABLE sensor_log
  ADD COLUMN device_id VARCHAR(40) DEFAULT 'DEFAULT' AFTER id,
  ADD INDEX idx_device_id (device_id);
```

---

## Server Setup (FOG Node)

### 1. Install WAMP

Download and install [WampServer](https://www.wampserver.com/) on the greenhouse management PC. Ensure Apache and MySQL are running (green icon in system tray).

### 2. Deploy files

Copy all PHP files into your WAMP web root:

```
C:\wamp64\www\greenhouse\
```

### 3. Configure db.php

Open `db.php` and update these values:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');           // your MySQL root password
define('DB_NAME', 'greenhouse');
define('API_KEY', 'xxxxxxxx');  // change this to a secret value

define('MAIL_FROM',         'your@gmail.com');
define('MAIL_RECIPIENT',    'your@gmail.com');
define('MAIL_APP_PASSWORD', '');  // 16-char Gmail App Password

define('WEATHER_LAT',      '');   // your farm latitude
define('WEATHER_LON',      '');  // your farm longitude
define('WEATHER_LOCATION', ''); // Location name
```

### 4. Create default users

Visit `http://localhost/greenhouse/setup_users.php` once in your browser.  
This creates:

| Username | Password | Role |
|----------|----------|------|
| `owner` | `owner123` | Owner — full access |
| `helper` | `helper123` | Helper — read only |

**Delete `setup_users.php` immediately after running it.**  
Change both passwords on first login via the Users page.

### 5. Enable OpenSSL (for email)

In WAMP, left-click the tray icon → PHP → PHP extensions → enable `php_openssl`.  
This is required for Gmail SMTP TLS used by the weekly report.

### 6. Install PHPMailer

Place the PHPMailer folder inside your project root:

```
greenhouse/
└── PHPMailer/
    └── src/
        ├── PHPMailer.php
        ├── SMTP.php
        └── Exception.php
```

Download from [github.com/PHPMailer/PHPMailer](https://github.com/PHPMailer/PHPMailer). If your folder is named `PHPMailer-master`, rename it to `PHPMailer`.

---

## ESP32 Firmware Setup

### Required libraries (Arduino Library Manager)

| Library | Author |
|---------|--------|
| DHT sensor library | Adafruit |
| LiquidCrystal I2C | Frank de Brabander |
| WiFi | Built-in (ESP32 core) |
| HTTPClient | Built-in (ESP32 core) |

### Configure the sketch

Open `esp32_greenhouse.ino` and edit the top section:

```cpp
const char* WIFI_SSID = "YourWiFiName";
const char* WIFI_PASS = "YourWiFiPassword";
const char* API_URL   = "http://x.x.x.x/greenhouse/api.php";  // FOG PC local IP
const char* API_KEY   = "api key";   // must match db.php API_KEY
const char* DEVICE_ID = "ESP32-ZONE-A";          // must match Devices page
```

> Find your FOG PC's local IP: open Command Prompt on the WAMP PC → `ipconfig` → look for IPv4 Address.

### Threshold settings (sketch)

```cpp
#define HUM_THRESHOLD   78.0f    // % — CH1 sprinkler trigger
#define TEMP_THRESHOLD  32.0f    // °C — CH2 fan trigger
#define HOLD_MS    300000UL      // 5 minutes before relay fires
```

These **must match** the constants in `db.php`:

```php
define('MOTOR_HUM_THRESHOLD',  78);
define('MOTOR_TEMP_THRESHOLD', 32);
```

### Upload

Select board: **ESP32 Dev Module** — Partition scheme: Default.  
Upload at 115200 baud. Open Serial Monitor to verify POST responses.

---

## Adding a New Zone Device

1. Open the dashboard → **Devices** → **Register New Device**
2. Enter a unique **Device ID** (e.g. `ESP32-ZONE-B`), display name, and location
3. Copy the generated sketch snippet from the dark code box on the right
4. Paste into `esp32_greenhouse.ino`, update WiFi credentials, upload to the new board
5. The device appears as **Online** in the Devices page within 15 seconds

Unknown devices that POST without being pre-registered are **auto-registered** as placeholders — they appear in the Devices list for the owner to review and fill in details.

---

## Cloud Layer (Azure)

CSV files are exported from the FOG server and pushed to Azure, each named with the FOG identifier:

```
FOG-Galle_ESP32-ZoneA-1_2026-03.csv
FOG-Galle_ESP32-ZoneB-1_2026-03.csv
FOG-Matara_ESP32-ZoneA-1_2026-03.csv
```

The Azure registry stores multiple files per FOG node. This architecture supports multiple geographically distributed farms pushing data to a single Azure endpoint for cross-farm analytics.

**CSV columns exported:**

```
id, device_id, recorded_at, temperature, humidity, gas,
temp_status, hum_status, gas_status,
motor_on, motor_run_sec, motor_pending_sec,
ch2_on, ch2_run_sec, ch2_pending_sec,
trigger_reason, source, notes
```

---

## Relay Trigger Logic

Both relay channels are **fully independent** — either or both can be active simultaneously.

### CH1 — Sprinkler Pump (Humidity)

```
humidity > 78%  continuously for 5 min  →  CH1 ON  (GPIO5 / D5 / IN1)
humidity drops to ≤ 78%                →  CH1 OFF immediately
```

### CH2 — Cooling Fan / Ventilation (Temperature)

```
temperature > 32°C  continuously for 5 min  →  CH2 ON  (GPIO18 / D18 / IN2)
temperature drops to ≤ 32°C                →  CH2 OFF immediately
```

### Important behaviours

- The 5-minute timer **resets to zero** if the value drops below threshold before the timer completes
- Motor logic runs on **every loop iteration** — response is near-instant (< 2 seconds)
- Relay control works **even when WiFi is disconnected** — the ESP32 does not need the FOG server to operate physically
- The dashboard shows three states per channel: **Running** (animated pulse), **Pending** (countdown timer), **Off**

---

## Sensor Thresholds

### Inside greenhouse (DHT22 + gas sensor)

| Sensor | NORMAL | WARNING | DANGER |
|--------|--------|---------|--------|
| Temperature | 18 – 32 °C | < 18 or > 32 °C | < 15 or > 35 °C |
| Humidity | 50 – 85 % | < 50 or > 85 % | < 40 or > 90 % |
| Air quality (gas) | < 800 | 800 – 1500 | > 1500 |

### Relay trigger thresholds

| Channel | Sensor | Trigger value | Hold time |
|---------|--------|--------------|-----------|
| CH1 — sprinkler | Humidity | > 78 % | 5 min |
| CH2 — cooling fan | Temperature | > 32 °C | 5 min |

---

## Dashboard Pages

| Page | URL | Access |
|------|-----|--------|
| Live Status | `dashboard.php` | All users |
| Sensor Records | `log.php` | All users |
| Devices | `devices.php` | Owner only |
| Add Reading | `manual.php` | Owner only |
| Download Data | `export.php` | Owner only |
| Weekly Report | `weekly_report.php` | Owner only |
| Manage Data | `admin.php` | Owner only |
| Users | `users.php` | Owner only |

---

## User Roles

| Role | Permissions |
|------|-------------|
| **Owner** | Full access — all pages, device management, data export, user management, relay configuration |
| **Helper** | Read-only — Live Status and Sensor Records only |

---

## Weather Integration

Weather data is fetched from [Open-Meteo](https://open-meteo.com/) — completely free, no API key required. Results are cached for 15 minutes in a temp file so the API is not called on every page load.

Coordinates default to Galle, Sri Lanka (`LAT, LON`). Change these in `db.php`:

```php
define('WEATHER_LAT',      '');
define('WEATHER_LON',      '');
define('WEATHER_LOCATION', '');
define('WEATHER_TIMEZONE', 'Asia/Colombo');
```

> **Note:** Weather alerts in this system are for **structural protection only** (wind, storms, outdoor humidity affecting ventilation). This greenhouse uses controlled drip irrigation — rain does not affect the watering schedule.

Weather alert thresholds:

```php
define('WEATHER_WIND_WARNING_KMH',  25);   // amber alert
define('WEATHER_WIND_DANGER_KMH',   45);   // red alert
define('WEATHER_HUMIDITY_HIGH_PCT', 88);   // close vents advisory
```

---

## Configuration Reference

All configurable values are in `db.php` and at the top of `esp32_greenhouse.ino`.

### db.php

| Constant | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_USER` | `root` | MySQL username |
| `DB_PASS` | `''` | MySQL password |
| `DB_NAME` | `greenhouse` | Database name |
| `API_KEY` | `xxxxxxxxx` | Global device API key |
| `SENSOR_TIMEOUT_SEC` | `300` | Seconds before device shown as offline |
| `MOTOR_HUM_THRESHOLD` | `78` | Humidity % that arms CH1 timer |
| `MOTOR_TEMP_THRESHOLD` | `32` | Temperature °C that arms CH2 timer |
| `MAIL_APP_PASSWORD` | `''` | 16-char Gmail App Password for reports |
| `WEATHER_LAT` / `LON` | Galle | GPS coordinates for weather fetch |

### esp32_greenhouse.ino

| Constant | Default | Description |
|----------|---------|-------------|
| `WIFI_SSID` / `WIFI_PASS` | — | WiFi credentials |
| `API_URL` | — | FOG server endpoint |
| `API_KEY` | `xxxxxxxxxxxxxxx` | Must match `db.php` |
| `DEVICE_ID` | `ESP32-ZONE-A` | Must match Devices page |
| `RELAY_CH1_PIN` | `5` | GPIO for sprinkler relay (IN1) |
| `RELAY_CH2_PIN` | `18` | GPIO for cooling fan relay (IN2) |
| `HUM_THRESHOLD` | `78.0` | Humidity trigger % |
| `TEMP_THRESHOLD` | `32.0` | Temperature trigger °C |
| `HOLD_MS` | `300000` | Hold time before relay fires (ms) |
| `POST_MS` | `15000` | POST interval to FOG server (ms) |

---

## API Reference

### POST `/api.php` — ESP32 data endpoint

No session authentication. Devices authenticate with `key` + `device_id`.

**Request fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `key` | string | Yes | Device API key |
| `device_id` | string | No | Device identifier (defaults to `DEFAULT`) |
| `temp` | float | Yes | Temperature in °C (-40 to 80) |
| `humidity` | float | Yes | Humidity % (0 to 100) |
| `gas` | int | Yes | Gas ADC reading (0 to 4095) |
| `motor_on` | int | No | CH1 sprinkler state (0 or 1) |
| `motor_run_sec` | int | No | CH1 seconds running this session |
| `motor_pending` | int | No | CH1 seconds until trigger fires |
| `ch2_on` | int | No | CH2 fan state (0 or 1) |
| `ch2_run_sec` | int | No | CH2 seconds running this session |
| `ch2_pending` | int | No | CH2 seconds until trigger fires |
| `trigger` | string | No | `none` / `humidity` / `temperature` / `both` |

**Success response:**

```json
{
  "ok": true,
  "id": 1234,
  "device_id": "ESP32-ZONE-A",
  "temp_status": "NORMAL",
  "hum_status": "NORMAL",
  "gas_status": "NORMAL",
  "ch1_on": false,
  "ch2_on": false,
  "trigger_reason": "none",
  "recorded_at": "2026-03-26 14:30:00"
}
```

**Error responses:**

| HTTP | Meaning |
|------|---------|
| `403` | Invalid or missing API key / device not active |
| `400` | Missing fields or values out of sensor range |
| `405` | Request method is not POST |
| `500` | Database error |

---

## Security Notes

- Change `API_KEY` in `db.php` from the default before deploying
- Use a unique `api_key` per device in the Devices page for tighter control
- Delete `setup_users.php` immediately after first use
- Change default passwords (`owner123`, `helper123`) on first login
- The `api.php` endpoint has no rate limiting — consider adding one if the FOG server is internet-facing
- All user passwords are stored as bcrypt hashes

---

## License

This project is developed for the SmartGrow greenhouse operation, Galle, Sri Lanka.  
Built with open-source components — ESP32 Arduino core, PHP, MySQL, Chart.js, Open-Meteo.

---

*SmartGrow — Chile Cultivation, Galle, Sri Lanka — 2026*
