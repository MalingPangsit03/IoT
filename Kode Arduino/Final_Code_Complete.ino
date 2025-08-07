// === Final Version: Dynamic Threshold & Upload-on-Change ESP32 Code ===
#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <LiquidCrystal_I2C.h>
#include "time.h"
#include <ArduinoJson.h>
#include <mbedtls/aes.h>
#include <mbedtls/base64.h>

// WiFi & server
const char* ssid = "HENING29";
const char* password = "s1234567s";
const char* uploadEndpoint = "http://192.168.100.6/real_time_monitoring/public/upload_data_otp_ed.php";
const char* thresholdEndpoint = "https://192.168.100.6/real_time_monitoring/public/get_threshold.php";

// Device Info
const int ip = 1;
const String loc = "Ruang Kamar";
const String deviceID = "Tools-" + String(ip);
const String deviceName = "Suhu Ruang | " + loc;

// AES Key & IV
const byte aesKey[16] = { '1','2','3','4','5','6','7','8','9','0','a','b','c','d','e','f' };
const byte aesIV[16]  = { 'a','b','c','d','e','f','1','2','3','4','5','6','7','8','9','0' };

// DHT setup
#define DHTPIN 25
#define DHTTYPE DHT21
DHT dht(DHTPIN, DHTTYPE);

// LCD & IO
#define LED_PIN 27
#define BUZZER_PIN 33
LiquidCrystal_I2C lcd(0x27, 16, 2);
uint8_t degree[8] = {0x08,0x14,0x14,0x08,0x00,0x00,0x00,0x00};

// Thresholds
float TEMP_ALERT_HIGH = 40.0;
float TEMP_ALERT_LOW  = 38.0;
float HUM_ALERT_HIGH  = 80.0;
float HUM_ALERT_LOW   = 78.0;
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
const unsigned long uploadInterval = 60000UL;
float lastSentTemp = NAN, lastSentHum = NAN;
bool lastUploadSuccess = false;

int pkcs7Pad(const byte* input, int len, byte* output, int blockSize) {
  int padLen = blockSize - (len % blockSize);
  memcpy(output, input, len);
  for (int i = len; i < len + padLen; ++i) output[i] = padLen;
  return len + padLen;
}

String encryptAndEncode(String plainText) {
  int inputLen = plainText.length();
  byte plain[inputLen];
  memcpy(plain, plainText.c_str(), inputLen);
  byte padded[inputLen + 16];
  int paddedLen = pkcs7Pad(plain, inputLen, padded, 16);

  mbedtls_aes_context aes;
  mbedtls_aes_init(&aes);
  mbedtls_aes_setkey_enc(&aes, aesKey, 128);
  byte iv[16];
  memcpy(iv, aesIV, 16);
  byte encrypted[paddedLen];
  mbedtls_aes_crypt_cbc(&aes, MBEDTLS_AES_ENCRYPT, paddedLen, iv, padded, encrypted);
  mbedtls_aes_free(&aes);

  size_t b64Len;
  mbedtls_base64_encode(nullptr, 0, &b64Len, encrypted, paddedLen);
  byte encoded[b64Len + 1];
  mbedtls_base64_encode(encoded, sizeof(encoded), &b64Len, encrypted, paddedLen);
  encoded[b64Len] = '\0';

  return String((char*)encoded);
}

bool syncTimeOnce(unsigned long timeout_ms = 15000) {
  configTime(gmtOffsetSec, 0, ntpServer);
  struct tm timeinfo;
  unsigned long start = millis();
  while (millis() - start < timeout_ms) {
    if (getLocalTime(&timeinfo)) {
      char buff[64];
      strftime(buff, sizeof(buff), "%Y-%m-%d %H:%M:%S", &timeinfo);
      dateTime = String(buff);
      strftime(buff, sizeof(buff), "%H:%M", &timeinfo);
      lcdFormat = String(buff);
      return true;
    }
    delay(500);
  }
  return false;
}

bool fetchThresholds() {
  if (WiFi.status() != WL_CONNECTED) return false;
  HTTPClient http;
  String url = String(thresholdEndpoint) + "?device_id=" + deviceID;
  http.begin(url);
  int httpCode = http.GET();
  if (httpCode == 200) {
    String payload = http.getString();
    DynamicJsonDocument doc(256);
    if (!deserializeJson(doc, payload) && doc["status"] == "success") {
      TEMP_ALERT_HIGH = doc["temp_high"];
      TEMP_ALERT_LOW  = doc["temp_low"];
      HUM_ALERT_HIGH  = doc["hum_high"];
      HUM_ALERT_LOW   = doc["hum_low"];
      http.end();
      return true;
    }
  }
  http.end();
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
  if (WiFi.status() != WL_CONNECTED) return;
  if (millis() - lastUpload < uploadInterval &&
      fabs(temp - lastSentTemp) < 1.0f &&
      fabs(hum - lastSentHum) < 1.0f) return;

  DynamicJsonDocument doc(512);
  doc["device_id"] = deviceID;
  doc["device_name"] = deviceName;
  doc["temperature"] = temp;
  doc["humidity"] = hum;
  doc["date"] = dateTime;
  doc["ip_address"] = WiFi.localIP().toString();

  String jsonData;
  serializeJson(doc, jsonData);

  String encrypted = encryptAndEncode(jsonData);

  HTTPClient http;
  http.begin(uploadEndpoint);
  http.addHeader("Content-Type", "text/plain");
  int code = http.POST(encrypted);
  http.end();

  if (code == 200) {
    lastUploadSuccess = true;
    lastUpload = millis();
    lastSentTemp = temp;
    lastSentHum = hum;
  } else {
    lastUploadSuccess = false;
  }
}

void printHeader() {
  lcd.clear();
  lcd.createChar(0, degree);
  lcd.setCursor(0, 0); lcd.print("Device:"); lcd.setCursor(8, 0); lcd.print(deviceID);
  lcd.setCursor(0, 1); lcd.print("Loc:"); lcd.setCursor(5, 1); lcd.print(loc);
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
  lcd.setCursor(0, 0); lcd.print("Getting WiFi...");
  lcd.setCursor(0, 1); lcd.print(ssid);

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) delay(500);
  lcd.clear();

  if (WiFi.status() == WL_CONNECTED) {
    lcd.setCursor(0, 0); lcd.print("Connected to:");
    lcd.setCursor(0, 1); lcd.print(ssid);
    delay(2000);
    fetchThresholds();
  } else {
    lcd.setCursor(0, 0); lcd.print("WiFi Failed");
    lcd.setCursor(0, 1); lcd.print("Check settings");
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
  float rawHum = dht.readHumidity();
  if (isnan(rawTemp) || isnan(rawHum)) return;

  float temp = rawTemp + tempCalibration;
  float hum  = rawHum + humCalibration;

  applyAlerts(temp, hum);
  updateLCD(temp, hum);
  tryUpload(temp, hum);

  delay(500);
}
