#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <LiquidCrystal_I2C.h>
#include "time.h"
#include <ArduinoJson.h>

// === CONFIGURATION ===
const int ip = 1;
const String loc = "Ruang Kamar";
const String deviceID = "Tools-" + String(ip);
const String deviceName = "Suhu Ruang | " + loc;

// Endpoint URLs
const char* uploadEndpoint = "http://192.168.100.6/real_time_monitoring/public/upload_data.php";
const String calibrationEndpointBase = String("http://192.168.100.6/real_time_monitoring/public/get_calibration.php?sensor_id=") + deviceID;

// WiFi credentials
const char* ssid = "HENING29";
const char* password = "s1234567s";

// Sensor & peripherals
#define DHTPIN 25
#define DHTTYPE DHT21
DHT dht(DHTPIN, DHTTYPE);

#define LED_PIN 27
#define BUZZER_PIN 33

#define LCDADDR 0x27
LiquidCrystal_I2C lcd(LCDADDR, 16, 2);

// Thresholds
const float TEMP_ALERT_HIGH = 40.0;
const float TEMP_ALERT_LOW  = 38.0;
const float HUM_ALERT_HIGH  = 80.0;
const float HUM_ALERT_LOW   = 78.0;

bool tempAlertActive = false;
bool humAlertActive  = false;

// Timezone / NTP
const long gmtOffsetSec = 7 * 3600;
const int daylightOffsetSec = 0;
const char* ntpServer = "pool.ntp.org";

// Calibration values
float tempCalibration = 0.0;
float humCalibration = 0.0;
unsigned long lastCalibrationFetch = 0;
const unsigned long calibrationInterval = 10 * 60UL * 1000UL;

// Upload control
unsigned long lastUpload = 0;
const unsigned long baselineUploadInterval = 60 * 1000UL;
float lastSentTemp = NAN;
float lastSentHum = NAN;
bool lastUploadSuccess = false;

// Time strings
String dateTime = "";
String lcdFormat = "";

// Custom degree symbol
uint8_t degree[8] = {0x08,0x14,0x14,0x08,0x00,0x00,0x00,0x00};

// URL encode
String urlEncode(const String &str) {
  String encoded = "";
  char buf[4];
  for (size_t i = 0; i < str.length(); i++) {
    char c = str[i];
    if (('a' <= c && c <= 'z') || ('A' <= c && c <= 'Z') ||
        ('0' <= c && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded += c;
    } else {
      sprintf(buf, "%%%02X", (uint8_t)c);
      encoded += buf;
    }
  }
  return encoded;
}

// Sync NTP time
bool syncTimeOnce(unsigned long timeout_ms = 15000) {
  configTime(gmtOffsetSec, daylightOffsetSec, ntpServer, "time.nist.gov");
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

// Fetch calibration
void fetchCalibrationIfNeeded() {
  if (millis() - lastCalibrationFetch < calibrationInterval) return;
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(calibrationEndpointBase);
  int code = http.GET();
  if (code == 200) {
    String payload = http.getString();
    StaticJsonDocument<256> doc;
    if (deserializeJson(doc, payload) == DeserializationError::Ok) {
      if (doc.containsKey("offset_temp")) tempCalibration = doc["offset_temp"].as<float>();
      if (doc.containsKey("offset_hum")) humCalibration = doc["offset_hum"].as<float>();
      Serial.printf("Calibration: temp=%.2f hum=%.2f\n", tempCalibration, humCalibration);
      lastCalibrationFetch = millis();
    } else {
      Serial.println("Calibration JSON parse error");
    }
  } else {
    Serial.printf("Failed to fetch calibration, HTTP %d\n", code);
  }
  http.end();
}

// LCD update
void updateLCD(float adjustedTemp, float adjustedHum) {
  lcd.setCursor(0, 0);
  lcd.printf("T:%.1fC %s", adjustedTemp, deviceID.c_str());
  lcd.setCursor(0, 1);
  lcd.printf("H:%.1f%% %s", adjustedHum, lcdFormat.c_str());
  lcd.setCursor(15, 0);
  lcd.print(lastUploadSuccess ? '+' : '-');
}

// Alerts
void applyAlerts(float adjustedTemp, float adjustedHum) {
  if (!tempAlertActive && adjustedTemp >= TEMP_ALERT_HIGH) tempAlertActive = true;
  else if (tempAlertActive && adjustedTemp <= TEMP_ALERT_LOW) tempAlertActive = false;
  if (!humAlertActive && adjustedHum >= HUM_ALERT_HIGH) humAlertActive = true;
  else if (humAlertActive && adjustedHum <= HUM_ALERT_LOW) humAlertActive = false;

  if (tempAlertActive || humAlertActive) {
    digitalWrite(BUZZER_PIN, HIGH);
    digitalWrite(LED_PIN, HIGH);
  } else {
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(LED_PIN, LOW);
  }
}

// Upload to server
void tryUpload(float adjustedTemp, float adjustedHum) {
  unsigned long now = millis();
  bool timeForBaseline = (now - lastUpload >= baselineUploadInterval);

  if (!timeForBaseline &&
      fabs(adjustedTemp - lastSentTemp) < 1.0f &&
      fabs(adjustedHum - lastSentHum) < 5.0f) {
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected, skip upload");
    return;
  }

  String postData = "";
  postData += "device_id=" + urlEncode(deviceID);
  postData += "&device_name=" + urlEncode(deviceName);
  postData += "&temperature=" + String(adjustedTemp, 2);
  postData += "&humidity=" + String(adjustedHum, 2);
  postData += "&date=" + urlEncode(dateTime);
  postData += "&ip_address=" + urlEncode(WiFi.localIP().toString());

  HTTPClient http;
  http.begin(uploadEndpoint);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  int code = http.POST(postData);
  String resp = http.getString();
  if (code == 200) {
    Serial.printf("[UPLOAD] OK %s\n", resp.c_str());
    lastUploadSuccess = true;
    lastUpload = now;
    lastSentTemp = adjustedTemp;
    lastSentHum = adjustedHum;
  } else {
    Serial.printf("[UPLOAD] ERR %d %s\n", code, resp.c_str());
    lastUploadSuccess = false;
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
  unsigned long startAttempt = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startAttempt < 15000) {
    Serial.print(".");
    delay(500);
  }
  Serial.println(WiFi.status() == WL_CONNECTED ? "\nWiFi connected" : "\nWiFi failed");

  syncTimeOnce(15000);
  fetchCalibrationIfNeeded();
}

void loop() {
  static unsigned long lastTimeRefresh = 0;
  if (millis() - lastTimeRefresh >= 30000) {
    syncTimeOnce(5000);
    lastTimeRefresh = millis();
  }

  float rawTemp = dht.readTemperature();
  float rawHum = dht.readHumidity();
  if (isnan(rawTemp) || isnan(rawHum)) {
    Serial.println("DHT read failed");
    delay(1000);
    return;
  }

  float adjustedTemp = rawTemp + tempCalibration;
  float adjustedHum = rawHum + humCalibration;

  fetchCalibrationIfNeeded();
  applyAlerts(adjustedTemp, adjustedHum);
  updateLCD(adjustedTemp, adjustedHum);
  tryUpload(adjustedTemp, adjustedHum);

  delay(500);
}
