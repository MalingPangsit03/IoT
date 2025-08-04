#include <DHT.h>
#include <LiquidCrystal_I2C.h>

#define DHTPIN 25
#define DHTTYPE DHT21
DHT dht(DHTPIN, DHTTYPE);

#define LED_PIN 27
#define BUZZER_PIN 33

LiquidCrystal_I2C lcd(0x27, 16, 2);

// timing
unsigned long lastDHT = 0;
const unsigned long dhtInterval = 2500; // >=2s per spec

void setup() {
  Serial.begin(115200);
  pinMode(LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);

  dht.begin();
  lcd.init();
  lcd.backlight();
  delay(500);

  // Intro
  lcd.setCursor(0,0);
  lcd.print("Component Test");
  lcd.setCursor(0,1);
  lcd.print("Starting...");
  delay(1500);
  lcd.clear();
}

void loop() {
  // 1. LED test: blink once
  digitalWrite(LED_PIN, HIGH);
  delay(200);
  digitalWrite(LED_PIN, LOW);

  // 2. Buzzer test: short beep
  digitalWrite(BUZZER_PIN, HIGH);
  delay(200);
  digitalWrite(BUZZER_PIN, LOW);

  // 3. Read DHT21 (only every >=2.5s)
  if (millis() - lastDHT >= dhtInterval) {
    lastDHT = millis();
    float temp = dht.readTemperature();
    float hum = dht.readHumidity();

    // Serial output
    Serial.print("DHT21 Read -> ");
    if (isnan(temp) || isnan(hum)) {
      Serial.println("FAILED");
    } else {
      Serial.print("Temp: ");
      Serial.print(temp);
      Serial.print(" C, Hum: ");
      Serial.print(hum);
      Serial.println(" %");
    }

    // LCD display summary
    lcd.clear();
    if (!isnan(temp) && !isnan(hum)) {
      lcd.setCursor(0, 0);
      lcd.print("DHT21: OK       ");
      lcd.setCursor(0, 1);
      lcd.printf("T:%.1fC H:%.1f%%", temp, hum);
    } else {
      lcd.setCursor(0, 0);
      lcd.print("Sensor ERROR   ");
      lcd.setCursor(0, 1);
      lcd.print("Check wiring   ");
    }
  }

  // keep running indicator on serial
  delay(500);
}
