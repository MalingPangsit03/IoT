#include <WiFi.h>
#include <WiFiMulti.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <LiquidCrystal_I2C.h>
#include "time.h"
#include <ArduinoJson.h>

// === CONFIGURATION ===
// Device identity (ip corresponds to your mapping, e.g., 17 -> Tools-17)
const int ip = 17;
const String loc = "Giling Gula";
const String prod = "Biskuit";
const String deviceID = "Tools-" + String(ip);
const String deviceName = "Suhu Ruang | " + loc;

// Server endpoints (adjust host/IP to your actual server)
const char* uploadEndpoint = "http://your-server/public/upload_data.php"; // ensure final path
const String calibrationEndpoint = String("http://your-server/public/get_calibration.php?device_id=") + deviceID;

// WiFi networks (priority/fallback)
WiFiMulti wifiMulti;
const char* ssid_a_biskuit_mie = "HENING";
const char* password_a_biskuit_mie = "s1234567s";


// Sensor & peripherals
#define DHTPIN 33
#define DHTTYPE DHT21
DHT dht(DHTPIN, DHTTYPE);

#define BUZZER_PIN 14
#define LED_PIN 13

#define LCDADDR 0x27
LiquidCrystal_I2C lcd(LCDADDR, 16, 2);

// Threshold
const float TEMP_THRESHOLD = 30.0; // Celsius

// Time
const long gmtOffset_sec = 7 * 3600; // UTC+7
const int daylightOffset_sec = 0;

// Calibration offsets
float tempOffset = 0.0;
float humOffset = 0.0;
bool calibrationFetched = false;

// State counters
int readDHTCount = 0;
int readNan = 0;
int errorWiFiCount = 0;

// Timing
unsigned long lastUpload = 0;
const unsigned long uploadInterval = 60000; // 1 minute

// Custom degree symbol for LCD
uint8_t degree[8] = {
  0x08,
  0x14,
  0x14,
  0x08,
  0x00,
  0x00,
  0x00,
  0x00
};

// Helpers
String urlEncode(const String &str) {
  String encoded = "";
  char c;
  char buf[4];
  for (size_t i = 0; i < str.length(); i++) {
    c = str[i];
    if (('a' <= c && c <= 'z') ||
        ('A' <= c && c <= 'Z') ||
        ('0' <= c && c <= '9') ||
        (c == '-' || c == '_' || c == '.' || c == '~')) {
      encoded += c;
    } else {
      sprintf(buf, "%%%02X", (uint8_t)c);
      encoded += buf;
    }
  }
  return encoded;
}

void connectWifi() {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Connecting WiFi");
  if (prod == "Biskuit" || prod == "Mie") {
    wifiMulti.addAP(ssid_a_biskuit_mie, password_a_biskuit_mie);
    wifiMulti.addAP(ssid_b_biskuit_mie, password_b_biskuit_mie);
    wifiMulti.addAP(ssid_c_biskuit_mie, password_c_biskuit_mie);
  } else if (prod == "Kerupuk") {
    wifiMulti.addAP(ssid_a_kerupuk, password_a_kerupuk);
    wifiMulti.addAP(ssid_b_kerupuk, password_b_kerupuk);
    wifiMulti.addAP(ssid_c_kerupuk, password_c_kerupuk);
  }
  wifiMulti.addAP(ssid_it, password_it);

  // Optional: static IP can be configured here if needed
  // WiFi.config(...);

  // Try to connect
  int attempts = 0;
  while (wifiMulti.run() != WL_CONNECTED && attempts < 20) {
    delay(500);
    lcd.setCursor(0, 1);
    lcd.print(".");
    attempts++;
  }
  if (WiFi.status() == WL_CONNECTED) {
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi Connected");
    lcd.setCursor(0, 1);
    lcd.print(WiFi.localIP().toString());
    delay(1000);
  } else {
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi Fail");
    delay(1000);
  }
}

void fetchCalibration() {
  if (calibrationFetched) return;
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(calibrationEndpoint);
  int code = http.GET();
  if (code == 200) {
    String payload = http.getString();
    StaticJsonDocument<256> doc;
    auto err = deserializeJson(doc, payload);
    if (!err) {
      if (doc.containsKey("temperature")) tempOffset = doc["temperature"].as<float>();
      if (doc.containsKey("humidity")) humOffset = doc["humidity"].as<float>();
      calibrationFetched = true;
    }
  }
  http.end();
}

void sendData() {
  if (WiFi.status() != WL_CONNECTED) return;

  float rawTemp = dht.readTemperature();
  float rawHum = dht.readHumidity();

  if (isnan(rawTemp) || isnan(rawHum)) {
    readNan++;
    Serial.println("DHT read failed, reinit.");
    dht.begin();
    return;
  }
  readDHTCount++;

  float adjustedTemp = rawTemp + tempOffset;
  float adjustedHum = rawHum + humOffset;

  // Time
  struct tm timeinfo;
  getLocalTime(&timeinfo);
  char timeStr[9];
  strftime(timeStr, sizeof(timeStr), "%H:%M:%S", &timeinfo);
  char dateTimeStr[20];
  strftime(dateTimeStr, sizeof(dateTimeStr), "%Y-%m-%d %H:%M:%S", &timeinfo);

  // LCD display
  lcd.setCursor(0, 0);
  lcd.printf("T:%.1fC %s", adjustedTemp, deviceID.c_str());
  lcd.setCursor(0, 1);
  lcd.printf("H:%.1f%% %s", adjustedHum, timeStr);

  // Alert
  if (adjustedTemp > TEMP_THRESHOLD) {
    digitalWrite(BUZZER_PIN, HIGH);
    digitalWrite(LED_PIN, HIGH);
  } else {
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(LED_PIN, LOW);
  }

  // Prepare and send HTTP GET (can be changed to POST)
  String url = String(uploadEndpoint) +
               "?device_id=" + urlEncode(deviceID) +
               "&device_name=" + urlEncode(deviceName) +
               "&temperature=" + String(adjustedTemp, 2) +
               "&humidity=" + String(adjustedHum, 2);
  HTTPClient http;
  http.begin(url);
  int code = http.GET();
  String resp = http.getString();
  if (code == 200) {
    Serial.printf("[UPLOAD] OK %s\n", resp.c_str());
  } else {
    Serial.printf("[UPLOAD] ERR %d %s\n", code, resp.c_str());
    errorWiFiCount++;
  }
  http.end();
}

void printHeader() {
  lcd.clear();
  lcd.createChar(0, degree);
  lcd.setCursor(0, 0);
  lcd.print("Device:");
  lcd.setCursor(8, 0);
  lcd.print(deviceID);
  lcd.setCursor(0, 1);
  lcd.print("Loc:");
  lcd.setCursor(5, 1);
  lcd.print(loc);
  delay(1500);
  lcd.clear();
}

void setup() {
  Serial.begin(115200);
  delay(100);

  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(LED_PIN, LOW);

  dht.begin();

  lcd.init();
  lcd.backlight();
  lcd.createChar(0, degree);
  printHeader();

  connectWifi();
  configTime(gmtOffset_sec, daylightOffset_sec, "pool.ntp.org", "time.nist.gov");
}

void loop() {
  // Ensure WiFi is up
  if (wifiMulti.run() != WL_CONNECTED) {
    connectWifi();
  }

  // Fetch calibration once after connection
  fetchCalibration();

  // Send data periodically
  unsigned long now = millis();
  if (now - lastUpload >= uploadInterval) {
    sendData();
    lastUpload = now;
  }

  // Self recover conditions
  if (readDHTCount >= 1200 || readNan >= 10 || errorWiFiCount >= 10) {
    ESP.restart();
  }

  delay(1000);
}
