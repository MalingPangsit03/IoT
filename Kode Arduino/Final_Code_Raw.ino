#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <LiquidCrystal_I2C.h>
#include "time.h"
#include <ArduinoJson.h>

// === SYSTEM CONFIGURATION ===
const int ip = 1;
const String loc = "Ruang Kamar";
const String deviceID = "Tools-" + String(ip);
const String deviceName = "Suhu Ruang | " + loc;

// WiFi and server
const char* ssid = "HENING29";
const char* password = "s1234567s";
const char* uploadEndpoint = "http://192.168.100.6/real_time_monitoring/public/upload_data.php";

// DHT config
#define DHTPIN 25
#define DHTTYPE DHT21
DHT dht(DHTPIN, DHTTYPE);

// LCD & IO
#define LED_PIN 27
#define BUZZER_PIN 33
#define LCDADDR 0x27
LiquidCrystal_I2C lcd(LCDADDR, 16, 2);
uint8_t degree[8] = {0x08,0x14,0x14,0x08,0x00,0x00,0x00,0x00};

// Thresholds
const float TEMP_ALERT_HIGH = 40.0;
const float TEMP_ALERT_LOW  = 38.0;
const float HUM_ALERT_HIGH  = 80.0;
const float HUM_ALERT_LOW   = 78.0;
bool tempAlertActive = false;
bool humAlertActive  = false;

// Calibration
float tempCalibration = 0.0;
float humCalibration = 0.0;

// Time
const long gmtOffsetSec = 7 * 3600;
const char* ntpServer = "pool.ntp.org";
String dateTime = "", lcdFormat = "";

// Upload
unsigned long lastUpload = 0;
const unsigned long uploadInterval = 60 * 1000UL;
float lastSentTemp = NAN, lastSentHum = NAN;
bool lastUploadSuccess = false;

bool syncTimeOnce(unsigned long timeout_ms = 15000) {
  configTime(gmtOffsetSec, 0, ntpServer, "time.nist.gov");
  struct tm timeinfo;
  unsigned long start = millis();
  while (millis() - start < timeout_ms) {
    if (getLocalTime(&timeinfo)) {
      char buff[64];
      strftime(buff, sizeof(buff), "%Y-%m-%d %H:%M:%S", &timeinfo);
      dateTime = String(buff);
      char fmt[6];
      strftime(fmt, sizeof(fmt), "%H:%M", &timeinfo);
      lcdFormat = String(fmt);
      return true;
    }
    delay(500);
  }
  return false;
}

void applyAlerts(float t, float h) {
  tempAlertActive = (t >= TEMP_ALERT_HIGH) ? true : (t <= TEMP_ALERT_LOW) ? false : tempAlertActive;
  humAlertActive  = (h >= HUM_ALERT_HIGH)  ? true : (h <= HUM_ALERT_LOW)  ? false : humAlertActive;
  digitalWrite(BUZZER_PIN, tempAlertActive || humAlertActive);
  digitalWrite(LED_PIN, tempAlertActive || humAlertActive);
}

void updateLCD(float t, float h) {
  lcd.setCursor(0, 0);
  lcd.printf("T:%.1fC %s", t, deviceID.c_str());
  lcd.setCursor(0, 1);
  lcd.printf("H:%.1f%% %s", h, lcdFormat.c_str());
  lcd.setCursor(15, 0);
  lcd.print(lastUploadSuccess ? '+' : '-');
}

void tryUpload(float temp, float hum) {
  unsigned long now = millis();
  if (now - lastUpload < uploadInterval &&
      fabs(temp - lastSentTemp) < 1.0f &&
      fabs(hum - lastSentHum) < 5.0f) {
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected, skipping upload.");
    return;
  }

  // Prepare JSON
  DynamicJsonDocument doc(512);
  doc["device_id"] = deviceID;
  doc["device_name"] = deviceName;
  doc["temperature"] = temp;
  doc["humidity"] = hum;
  doc["date"] = dateTime;
  doc["ip_address"] = WiFi.localIP().toString();

  String jsonData;
  serializeJson(doc, jsonData);

  Serial.println("Uploading JSON:");
  Serial.println(jsonData);

  // POST
  HTTPClient http;
  http.begin(uploadEndpoint);
  http.addHeader("Content-Type", "application/json");
  int code = http.POST(jsonData);
  String resp = http.getString();
  http.end();

  if (code == 200) {
    Serial.printf("[UPLOAD] OK %s\n", resp.c_str());
    lastUploadSuccess = true;
    lastUpload = now;
    lastSentTemp = temp;
    lastSentHum = hum;
  } else {
    Serial.printf("[UPLOAD] ERR %d %s\n", code, resp.c_str());
    lastUploadSuccess = false;
  }
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
  delay(1200);
  lcd.clear();
}

void setup() {
  Serial.begin(115200);
  pinMode(LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);

  dht.begin();
  lcd.init();
  lcd.backlight();
  printHeader();

  WiFi.begin(ssid, password);
  Serial.print("Connecting to WiFi");

  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Getting WiFi...");
  lcd.setCursor(0, 1);
  lcd.print(ssid);

  unsigned long startAttempt = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startAttempt < 15000) {
    Serial.print(".");
    delay(500);
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWiFi connected");
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Connected to:");
    lcd.setCursor(0, 1);
    lcd.print(ssid);
    delay(2000);
    lcd.clear();
  } else {
    Serial.println("\nWiFi failed");
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi Failed");
    lcd.setCursor(0, 1);
    lcd.print("Check settings");
    delay(3000);
  }

  syncTimeOnce(15000);
}

void loop() {
  static unsigned long lastSync = 0;
  if (millis() - lastSync >= 30000) {
    syncTimeOnce(5000);
    lastSync = millis();
  }

  float rawTemp = dht.readTemperature();
  float rawHum  = dht.readHumidity();
  if (isnan(rawTemp) || isnan(rawHum)) {
    Serial.println("Sensor read failed");
    delay(1000);
    return;
  }

  float temp = rawTemp + tempCalibration;
  float hum  = rawHum + humCalibration;

  applyAlerts(temp, hum);
  updateLCD(temp, hum);
  tryUpload(temp, hum);

  delay(500);
}
