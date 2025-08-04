#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <LiquidCrystal_I2C.h>
#include "time.h"
#include <ArduinoJson.h>

// === CONFIGURATION ===
// Device identity
const int ip = 1;
const String loc = "Ruang Kamar";
const String deviceID = "Tools-" + String(ip);
const String deviceName = "Suhu Ruang | " + loc;

// Home WiFi credentials
const char* ssid = "HENING29";      // change to your WiFi SSID
const char* password = "s1234567s";  // change to your WiFi password

// Server endpoints (adjust to your actual host/IP and project path)
const char* uploadEndpoint = "http://192.168.100.6/real_time_monitroing/public/upload_data.php";
const String calibrationEndpoint = String("http://192.168.100.6/real_time_monitroing/public/get_calibration.php?device_id=") + deviceID;

// Sensor & peripherals
#define DHTPIN 25
#define DHTTYPE DHT21
DHT dht(DHTPIN, DHTTYPE);

#define BUZZER_PIN 33
#define LED_PIN 27

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

// Upload status indicator
bool lastUploadSuccess = false;

// Custom degree symbol
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

// Helper: URL encode
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
  WiFi.begin(ssid, password);

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 20000) {
    delay(500);
    Serial.print(".");
    lcd.setCursor((millis() / 500) % 16, 1);
    lcd.print(".");
  }

  if (WiFi.status() == WL_CONNECTED) {
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi Connected");
    lcd.setCursor(0, 1);
    lcd.print(WiFi.localIP().toString());
    Serial.print("\nConnected: ");
    Serial.println(WiFi.localIP());
    delay(1200);
  } else {
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi Failed");
    Serial.println("WiFi connect failed.");
    delay(1200);
  }
  lcd.clear(); // return to normal display
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
    } else {
      Serial.print("Calib JSON parse error: ");
      Serial.println(err.c_str());
    }
  } else {
    Serial.printf("Calib fetch failed: %d\n", code);
  }
  http.end();
}

void updateLCD(float adjustedTemp, float adjustedHum, const char* timeStr) {
  lcd.setCursor(0, 0);
  lcd.printf("T:%.1fC %s", adjustedTemp, deviceID.c_str());
  lcd.setCursor(0, 1);
  lcd.printf("H:%.1f%% %s", adjustedHum, timeStr);
  // upload status on top-right (overwrites last char of first line)
  lcd.setCursor(15, 0);
  lcd.print(lastUploadSuccess ? '+' : '-');
}

void sendData(float adjustedTemp, float adjustedHum) {
  if (WiFi.status() != WL_CONNECTED) {
    lastUploadSuccess = false;
    return;
  }

  // Get time string safely
  struct tm timeinfo;
  char timeStr[9] = "--:--:--";
  if (getLocalTime(&timeinfo)) {
    strftime(timeStr, sizeof(timeStr), "%H:%M:%S", &timeinfo);
  }

  // Display immediately (so user always sees fresh)
  updateLCD(adjustedTemp, adjustedHum, timeStr);

  // Alert
  if (adjustedTemp > TEMP_THRESHOLD) {
    digitalWrite(BUZZER_PIN, HIGH);
    digitalWrite(LED_PIN, HIGH);
  } else {
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(LED_PIN, LOW);
  }

  // Build upload URL
  String url = String(uploadEndpoint) +
               "?device_id=" + urlEncode(deviceID) +
               "&device_name=" + urlEncode(deviceName) +
               "&temperature=" + String(adjustedTemp, 2) +
               "&humidity=" + String(adjustedHum, 2);
  Serial.println("Uploading to: " + url);
  HTTPClient http;
  http.begin(url);
  int code = http.GET();
  String resp = http.getString();
  if (code == 200) {
    Serial.printf("[UPLOAD] OK %s\n", resp.c_str());
    lastUploadSuccess = true;
  } else {
    Serial.printf("[UPLOAD] ERR %d %s\n", code, resp.c_str());
    lastUploadSuccess = false;
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
  delay(1200);
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
  // Ensure WiFi
  if (WiFi.status() != WL_CONNECTED) {
    connectWifi();
  }

  // Read sensors once
  float rawTemp = dht.readTemperature();
  float rawHum = dht.readHumidity();

  if (isnan(rawTemp) || isnan(rawHum)) {
    readNan++;
    Serial.println("DHT read failed, reinit.");
    dht.begin();
    // Still show upload status if available
    struct tm timeinfo;
    char timeStr[9] = "--:--:--";
    if (getLocalTime(&timeinfo)) {
      strftime(timeStr, sizeof(timeStr), "%H:%M:%S", &timeinfo);
    }
    float displayedTemp = isnan(rawTemp) ? 0.0 : rawTemp + tempOffset;
    float displayedHum  = isnan(rawHum)  ? 0.0 : rawHum + humOffset;
    updateLCD(displayedTemp, displayedHum, timeStr);
  } else {
    readDHTCount++;
    float adjustedTemp = rawTemp + tempOffset;
    float adjustedHum = rawHum + humOffset;

    // Fetch calibration once per connection
    fetchCalibration();

    // Time for display
    struct tm timeinfo;
    char timeStr[9] = "--:--:--";
    if (getLocalTime(&timeinfo)) {
      strftime(timeStr, sizeof(timeStr), "%H:%M:%S", &timeinfo);
    }

    // Always update display so it doesn't go stale
    updateLCD(adjustedTemp, adjustedHum, timeStr);

    // Periodic upload
    unsigned long now = millis();
    if (now - lastUpload >= uploadInterval) {
      sendData(adjustedTemp, adjustedHum);
      lastUpload = now;
    }

    // Alert logic (redundant if inside sendData, but keep for immediate)
    if (adjustedTemp > TEMP_THRESHOLD) {
      digitalWrite(BUZZER_PIN, HIGH);
      digitalWrite(LED_PIN, HIGH);
    } else {
      digitalWrite(BUZZER_PIN, LOW);
      digitalWrite(LED_PIN, LOW);
    }
  }

  // Recovery conditions
  if (readDHTCount >= 1200 || readNan >= 10 || errorWiFiCount >= 10) {
    ESP.restart();
  }

  delay(1000);
}
