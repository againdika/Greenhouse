// ╔══════════════════════════════════════════════════════════════════╗
// ║   Chile Greenhouse Monitor  v7.0  —  ESP32 + HW-383 Dual Relay  ║
// ║                                                                  ║
// ║   CH1 (IN1 / D5)  — Sprinkler pump                             ║
// ║     Triggers when: humidity > 78% for 5 continuous minutes      ║
// ║     Turns OFF:     humidity drops back to ≤ 78%                 ║
// ║                                                                  ║
// ║   CH2 (IN2 / D18) — Cooling fan / ventilation                  ║
// ║     Triggers when: temperature > 32°C for 5 continuous minutes  ║
// ║     Turns OFF:     temperature drops back to ≤ 32°C             ║
// ║                                                                  ║
// ║   Both timers are fully independent of each other.              ║
// ╚══════════════════════════════════════════════════════════════════╝
//
//  Libraries (Arduino Library Manager):
//    - DHT sensor library  by Adafruit
//    - LiquidCrystal I2C   by Frank de Brabander
//    - WiFi, HTTPClient    built-in for ESP32
//
//  ── WIRING ────────────────────────────────────────────────────────
//  DHT22 DATA   →  GPIO 4    (10k pull-up to 3.3V)
//  Gas sensor   →  GPIO 34   (ADC input, 0–4095)
//  LCD I2C      →  SDA=GPIO21, SCL=GPIO22
//
//  HW-383 2-channel relay:
//    IN1  →  GPIO 5  (D5)   ← sprinkler pump   (humidity control)
//    IN2  →  GPIO 18 (D18)  ← cooling fan/vent  (temperature control)
//    VCC  →  5V
//    GND  →  GND
//
//  HW-383 is ACTIVE LOW:
//    LOW  on INx  →  relay coil ON  →  device runs
//    HIGH on INx  →  relay coil OFF →  device stops
//
//  IMPORTANT: Both pins are set HIGH in code BEFORE pinMode() is called
//  so neither relay clicks on briefly during ESP32 boot.
//
//  ── MULTI-DEVICE SETUP ────────────────────────────────────────────
//  1. Register on web: Greenhouse Monitor → Devices → New Device
//  2. Set DEVICE_ID below to match the registration
//  3. Upload — appears Online within 15 seconds

#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <DHT.h>
#include <time.h>

// ── CHANGE THESE FOR EACH DEVICE ──────────────────────────────────────
const char* WIFI_SSID = "ssidname"; // WiFi SSID of your router
const char* WIFI_PASS = "xxxxxxxxx"; // WiFi password of your router
const char* API_URL   = "http://x.x.x.x/greenhouse/api.php"; // URL of the server API endpoint to POST data to

const char* API_KEY   = "xxxxxxxxxxx2024"; // API key for authentication with the server (must match the key defined in db.php on the server)
const char* DEVICE_ID = "ESP32-ZONE-A"; // Unique identifier for this device (e.g. "ESP32-ZONE-A"). Must match the DEVICE_ID registered on the server for this device.
// ─────────────────────────────────────────────────────────────────────

// ── RELAY PINS — HW-383 ────────────────────────────────────────────────
#define RELAY_CH1_PIN  5     // D5  / GPIO5  — sprinkler pump
#define RELAY_CH2_PIN  18    // D18 / GPIO18 — cooling fan / ventilation
#define RELAY_ON       LOW   // HW-383 active LOW
#define RELAY_OFF      HIGH
// ─────────────────────────────────────────────────────────────────────

// ── TRIGGER THRESHOLDS ────────────────────────────────────────────────
//  Humidity:    if indoor humidity stays above HUM_THRESHOLD for
//               HOLD_MS continuously → sprinkler pump ON (CH1)
//               Turns OFF the moment humidity falls to HUM_THRESHOLD or below.
//
//  Temperature: if indoor temperature stays above TEMP_THRESHOLD for
//               HOLD_MS continuously → cooling fan ON (CH2)
//               Turns OFF the moment temperature falls to TEMP_THRESHOLD or below.
//
//  Chile safe range: 20–30 °C.  Warning starts at 32 °C.
//  Trigger is set 2 degrees above warning so the relay fires only
//  when the situation genuinely needs correction.
#define HUM_THRESHOLD   78.0f    // % — sprinkler trigger (CH1)
#define TEMP_THRESHOLD  32.0f    // °C — fan/vent trigger  (CH2)
#define HOLD_MS    300000UL      // 5 minutes hold time for BOTH channels
// ─────────────────────────────────────────────────────────────────────

// ── SENSOR / POST SETTINGS ────────────────────────────────────────────
#define NTP_SERVER     "pool.ntp.org"
#define GMT_OFFSET_SEC 19800     // UTC+05:30 Sri Lanka Standard Time
#define DST_OFFSET_SEC 0
#define DHTPIN         4
#define DHTTYPE        DHT22
#define GAS_PIN        34
#define POST_MS        15000     // send data every 15 seconds
// ─────────────────────────────────────────────────────────────────────

DHT dht(DHTPIN, DHTTYPE);
LiquidCrystal_I2C lcd(0x27, 16, 2);

// ── Sensor readings ────────────────────────────────────────────────────
float temperature = 0.0f;
float humidity    = 0.0f;
int   gasValue    = 0;

// ── CH1: Humidity / sprinkler state ───────────────────────────────────
bool          ch1On         = false;  // true = sprinkler running
bool          humAbove      = false;  // humidity currently above threshold
unsigned long humHighSince  = 0;      // when humidity first crossed threshold
unsigned long ch1OnSince    = 0;      // when CH1 last turned ON

// ── CH2: Temperature / fan state ──────────────────────────────────────
bool          ch2On         = false;  // true = fan/vent running
bool          tempAbove     = false;  // temperature currently above threshold
unsigned long tempHighSince = 0;      // when temperature first crossed threshold
unsigned long ch2OnSince    = 0;      // when CH2 last turned ON

// ── Comms ──────────────────────────────────────────────────────────────
unsigned long lastPost  = 0;
unsigned long startTime = 0;
bool          showIP    = true;
bool          lastOK    = false;
int           postCount = 0;

// ──────────────────────────────────────────────────────────────────────
// LCD helper — pads string to exactly 16 characters
void lcdWrite(const String& r0, const String& r1) {
    auto pad = [](String s) -> String {
        while ((int)s.length() < 16) s += ' ';
        return s.substring(0, 16);
    };
    lcd.setCursor(0, 0); lcd.print(pad(r0));
    lcd.setCursor(0, 1); lcd.print(pad(r1));
}

bool ntpReady() { time_t t; time(&t); return t > 100000; }

// ──────────────────────────────────────────────────────────────────────
// CH1: Sprinkler pump — humidity
void ch1Start() {
    if (!ch1On) {
        digitalWrite(RELAY_CH1_PIN, RELAY_ON);
        ch1On      = true;
        ch1OnSince = millis();
        Serial.printf("[CH1 ON]  Sprinkler pump started. Humidity %.1f%% held above %.0f%% for 5 min.\n",
                      humidity, HUM_THRESHOLD);
        lcdWrite("SPRINKLER ON", "H:" + String(humidity,1) + "% >" + String((int)HUM_THRESHOLD) + "%");
        delay(1200);
    }
}

void ch1Stop() {
    if (ch1On) {
        digitalWrite(RELAY_CH1_PIN, RELAY_OFF);
        ch1On      = false;
        humAbove   = false;
        humHighSince = 0;
        unsigned long ran = (millis() - ch1OnSince) / 1000;
        Serial.printf("[CH1 OFF] Sprinkler stopped after %lu sec. Humidity %.1f%% normalised.\n",
                      ran, humidity);
        lcdWrite("SPRINKLER OFF", "H:" + String(humidity,1) + "% OK");
        delay(1200);
    }
}

// ──────────────────────────────────────────────────────────────────────
// CH2: Cooling fan / ventilation — temperature
void ch2Start() {
    if (!ch2On) {
        digitalWrite(RELAY_CH2_PIN, RELAY_ON);
        ch2On      = true;
        ch2OnSince = millis();
        Serial.printf("[CH2 ON]  Cooling fan started. Temp %.1f°C held above %.0f°C for 5 min.\n",
                      temperature, TEMP_THRESHOLD);
        lcdWrite("COOLING FAN ON", "T:" + String(temperature,1) + "C >" + String((int)TEMP_THRESHOLD) + "C");
        delay(1200);
    }
}

void ch2Stop() {
    if (ch2On) {
        digitalWrite(RELAY_CH2_PIN, RELAY_OFF);
        ch2On       = false;
        tempAbove   = false;
        tempHighSince = 0;
        unsigned long ran = (millis() - ch2OnSince) / 1000;
        Serial.printf("[CH2 OFF] Cooling fan stopped after %lu sec. Temp %.1f°C normalised.\n",
                      ran, temperature);
        lcdWrite("COOLING OFF", "T:" + String(temperature,1) + "C OK");
        delay(1200);
    }
}

// ──────────────────────────────────────────────────────────────────────
// Evaluate both relay channels — called every loop() iteration
// so ON/OFF decisions happen immediately without waiting for a POST.
void updateRelays() {

    unsigned long now = millis();

    // ── CH1: Humidity → Sprinkler ──────────────────────────────────
    if (humidity > HUM_THRESHOLD) {
        if (!humAbove) {
            humAbove     = true;
            humHighSince = now;
            Serial.printf("[HUM] Rose above %.0f%% — 5 min countdown started.\n", HUM_THRESHOLD);
        }
        if (!ch1On && (now - humHighSince >= HOLD_MS)) {
            ch1Start();
        }
    } else {
        if (humAbove) {
            humAbove     = false;
            humHighSince = 0;
            Serial.printf("[HUM] Back to %.1f%% — countdown reset.\n", humidity);
        }
        if (ch1On) ch1Stop();
    }

    // ── CH2: Temperature → Cooling fan ────────────────────────────
    if (temperature > TEMP_THRESHOLD) {
        if (!tempAbove) {
            tempAbove     = true;
            tempHighSince = now;
            Serial.printf("[TEMP] Rose above %.0f°C — 5 min countdown started.\n", TEMP_THRESHOLD);
        }
        if (!ch2On && (now - tempHighSince >= HOLD_MS)) {
            ch2Start();
        }
    } else {
        if (tempAbove) {
            tempAbove     = false;
            tempHighSince = 0;
            Serial.printf("[TEMP] Back to %.1f°C — countdown reset.\n", temperature);
        }
        if (ch2On) ch2Stop();
    }
}

// ──────────────────────────────────────────────────────────────────────
// Build a trigger reason string for the POST
// Values: "none" | "humidity" | "temperature" | "both"
String triggerReason() {
    if (ch1On && ch2On) return "both";
    if (ch1On)          return "humidity";
    if (ch2On)          return "temperature";
    return "none";
}

// ──────────────────────────────────────────────────────────────────────
// POST all sensor + relay data to the server
bool postData() {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[WIFI] Not connected — skipping POST");
        return false;
    }

    unsigned long now = millis();

    // CH1 timing
    unsigned long ch1RunSec  = ch1On ? (now - ch1OnSince) / 1000 : 0;
    unsigned long ch1PendSec = 0;
    if (!ch1On && humAbove && humHighSince > 0) {
        unsigned long el = now - humHighSince;
        ch1PendSec = el < HOLD_MS ? (HOLD_MS - el) / 1000 : 0;
    }

    // CH2 timing
    unsigned long ch2RunSec  = ch2On ? (now - ch2OnSince) / 1000 : 0;
    unsigned long ch2PendSec = 0;
    if (!ch2On && tempAbove && tempHighSince > 0) {
        unsigned long el = now - tempHighSince;
        ch2PendSec = el < HOLD_MS ? (HOLD_MS - el) / 1000 : 0;
    }

    HTTPClient http;
    http.begin(API_URL);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    http.setTimeout(8000);

    String body =
        "key="             + String(API_KEY)
      + "&device_id="      + String(DEVICE_ID)
      + "&temp="           + String(temperature, 2)
      + "&humidity="       + String(humidity,    2)
      + "&gas="            + String(gasValue)
      // CH1 — sprinkler / humidity
      + "&motor_on="       + String(ch1On      ? 1 : 0)
      + "&motor_run_sec="  + String(ch1RunSec)
      + "&motor_pending="  + String(ch1PendSec)
      // CH2 — fan / temperature
      + "&ch2_on="         + String(ch2On      ? 1 : 0)
      + "&ch2_run_sec="    + String(ch2RunSec)
      + "&ch2_pending="    + String(ch2PendSec)
      // Combined trigger reason
      + "&trigger="        + triggerReason();

    int    code     = http.POST(body);
    String response = http.getString();
    http.end();

    Serial.printf("[POST #%d] %s | T:%.1f H:%.1f G:%d | CH1:%s(%lus) CH2:%s(%lus) | HTTP:%d\n",
        ++postCount, DEVICE_ID, temperature, humidity, gasValue,
        ch1On?"ON":"OFF", ch1RunSec, ch2On?"ON":"OFF", ch2RunSec, code);

    return (code == 200);
}

// ══════════════════════════════════════════════════════════════════════
void setup() {
    Serial.begin(115200);
    Serial.printf("\n=== Greenhouse Monitor v7.0 | %s ===\n", DEVICE_ID);

    // ── Initialise relay pins HIGH (OFF) BEFORE pinMode ───────────────
    // Writing the output register before enabling the pin prevents
    // the relay clicking ON briefly during the ESP32 boot sequence.
    digitalWrite(RELAY_CH1_PIN, RELAY_OFF);
    digitalWrite(RELAY_CH2_PIN, RELAY_OFF);
    pinMode(RELAY_CH1_PIN, OUTPUT);
    pinMode(RELAY_CH2_PIN, OUTPUT);
    digitalWrite(RELAY_CH1_PIN, RELAY_OFF); // confirm again after pinMode
    digitalWrite(RELAY_CH2_PIN, RELAY_OFF);
    Serial.println("[RELAY] CH1 + CH2 initialised OFF");

    dht.begin();
    pinMode(GAS_PIN, INPUT);
    lcd.init();
    lcd.backlight();

    lcdWrite("Greenhouse v7.0", String(DEVICE_ID));
    delay(1500);

    // ── WiFi ──────────────────────────────────────────────────────────
    lcdWrite("Connecting WiFi", String(WIFI_SSID));
    WiFi.begin(WIFI_SSID, WIFI_PASS);
    for (int i = 0; WiFi.status() != WL_CONNECTED && i < 40; i++) delay(500);

    if (WiFi.status() == WL_CONNECTED) {
        lcdWrite("Syncing NTP...", "");
        configTime(GMT_OFFSET_SEC, DST_OFFSET_SEC, NTP_SERVER);
        for (int i = 0; !ntpReady() && i < 20; i++) delay(500);
        lcdWrite("IP:", WiFi.localIP().toString());
        Serial.println("[WIFI] IP: " + WiFi.localIP().toString());
        delay(2000);
    } else {
        lcdWrite("WiFi FAILED", "Check credentials");
        Serial.println("[WIFI] FAILED");
        delay(3000);
    }

    startTime = millis();
}

// ══════════════════════════════════════════════════════════════════════
void loop() {

    // 1. Read sensors
    float t = dht.readTemperature();
    float h = dht.readHumidity();
    if (!isnan(t)) temperature = t;
    if (!isnan(h)) humidity    = h;
    gasValue = analogRead(GAS_PIN);

    // Show IP chip for first 5 seconds
    if (showIP && millis() - startTime < 5000) { delay(200); return; }
    showIP = false;

    // 2. Auto-reconnect WiFi
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[WIFI] Lost — reconnecting...");
        WiFi.reconnect();
        delay(2000);
    }

    // 3. Evaluate relay logic — runs every loop for instant response
    updateRelays();

    // 4. POST every 15 seconds
    if (millis() - lastPost >= POST_MS) {
        lastPost = millis();
        lastOK   = postData();
    }

    // 5. LCD display
    //    Row 1: T:28.5C  H:72.1%
    //    Row 2: CH1:OFF  CH2:OFF   (or ON when active)
    String row1 = "T:" + String(temperature, 1) + "C H:" + String(humidity, 1) + "%";
    String ch1s = ch1On ? "SP:ON " : "SP:OFF";
    String ch2s = ch2On ? "FN:ON " : "FN:OFF";
    lcdWrite(row1, ch1s + " " + ch2s);

    delay(2000);
}
