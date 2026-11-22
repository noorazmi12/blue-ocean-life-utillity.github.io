// BOLU - ESP32 klasik (ESP32 Dev Module): monitoring pH dan suhu DS18B20.
// Menggunakan pin dari kode pertama yang sudah berhasil pada alat pengguna.

#include <WiFi.h>
#include <WebServer.h>
#include <ESPmDNS.h>
#include <WiFiClient.h>
#include <HTTPClient.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <WiFiManager.h>

#define PH_PIN 34            // GPIO34, sesuai kode pertama yang berhasil
#define ONE_WIRE_BUS 4       // GPIO4, sesuai kode pertama yang berhasil

// === ISI NAMA WI-FI DAN PASSWORD DI SINI ===
// ESP32 hanya mendukung Wi-Fi 2,4 GHz. Jangan membagikan password saat screenshot.
const char* WIFI_SSID = "Lang Lang Buana";
const char* WIFI_PASSWORD = "gilang128";

const char* BOLU_URL ="http://172.20.10.3/bolu3";
const char* KEY_WTR = "ef3db6c33635f0d1bca6c8551f6ac8df8d4f9b0354591709";
const unsigned long READ_INTERVAL_MS = 2000;
const unsigned long SEND_INTERVAL_MS = 15000;

WebServer server(80);
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature suhuSensor(&oneWire);

float suhuC = -99.0f;
float voltPH = 0.0f;
float nilaiPH = 0.0f;
bool suhuValid = false;
bool phValid = false;
String statusKirim = "Belum mengirim";

void bacaSensor() {
  suhuSensor.requestTemperatures();
  float hasilSuhu = suhuSensor.getTempCByIndex(0);
  suhuValid = !isnan(hasilSuhu) && hasilSuhu >= -5.0f &&
              hasilSuhu <= 45.0f && hasilSuhu != 85.0f;
  suhuC = suhuValid ? hasilSuhu : -99.0f;

  // Dipertahankan dari rumus sketch lama; perlu kalibrasi probe pH nyata.
  int adc = analogRead(PH_PIN);
  voltPH = (adc / 4095.0f) * 3.3f;
  nilaiPH = (voltPH < 0.1f) ? 0.0f : 7.0f + (2.50f - voltPH) / 0.18f;
  phValid = !isnan(nilaiPH) && nilaiPH >= 0.05f && nilaiPH <= 14.0f;

  Serial.print("Suhu: ");
  if (suhuValid) Serial.print(suhuC, 1); else Serial.print("ERROR");
  Serial.print(" C | pH: ");
  if (phValid) Serial.print(nilaiPH, 2); else Serial.print("ERROR");
  Serial.print(" | Tegangan modul pH: ");
  Serial.println(voltPH, 3);
}

void kirimKeBolu() {
  if (WiFi.status() != WL_CONNECTED) {
    statusKirim = "Wi-Fi terputus";
    Serial.println(statusKirim);
    return;
  }
  if (String(KEY_WTR).startsWith("ISI_TOKEN_")) {
    statusKirim = "Token WTR-001 belum diisi";
    Serial.println(statusKirim);
    return;
  }
  if (!suhuValid && !phValid) {
    statusKirim = "Suhu dan pH tidak valid, tidak dikirim";
    Serial.println(statusKirim);
    return;
  }

String data = "{";

if (suhuValid) {
  data += "\"temp_sensor_c\":" + String(suhuC, 2);
}

if (phValid) {
  if (suhuValid) data += ",";

  data += "\"ph\":" + String(nilaiPH, 2);

  // TAMBAHAN: Kirim tegangan modul pH ke BOLU
  data += ",\"volt_ph_v\":" + String(voltPH, 3);
}

data += "}";

Serial.println("[BOLU] Data dikirim: " + data);

  String payload = "{\"device_code\":\"WTR-001\",\"api_key\":\"" +
                   String(KEY_WTR) + "\",\"data\":" + data + "}";

  WiFiClient client;
  HTTPClient http;
  String url = String(BOLU_URL) + "/api/ingest.php";
  if (!http.begin(client, url)) {
    statusKirim = "Tidak bisa membuka koneksi ke BOLU";
    Serial.println(statusKirim);
    return;
  }
  http.setTimeout(8000);
  http.addHeader("Content-Type", "application/json");
  int httpCode = http.POST(payload);
  String balasan = (httpCode > 0) ? http.getString() : http.errorToString(httpCode);
  http.end();
  statusKirim = "HTTP " + String(httpCode) + ": " + balasan;
  Serial.println("[BOLU] " + statusKirim);
}

void tampilkanWeb() {
  String html = "<!doctype html><html lang='id'><head><meta charset='utf-8'>";
  html += "<meta name='viewport' content='width=device-width,initial-scale=1'>";
  html += "<title>OWC KJA - pH dan Suhu</title></head>";
  html += "<body style='font-family:Arial;max-width:600px;margin:32px auto;padding:16px'>";
  html += "<h2>Monitoring Kualitas Air</h2><h3>Suhu: <span id='suhu'>--</span> &deg;C</h3>";
  html += "<h3>pH: <span id='ph'>--</span></h3><p>Tegangan pH: <span id='volt'>--</span> V</p>";
  html += "<p>Status BOLU: <span id='status'>--</span></p>";
  html += "<script>async function refresh(){try{const r=await fetch('/data');const d=await r.json();";
  html += "document.getElementById('suhu').textContent=d.suhu===null?'ERROR':d.suhu;";
  html += "document.getElementById('ph').textContent=d.ph===null?'ERROR':d.ph;";
  html += "document.getElementById('volt').textContent=d.volt;";
  html += "document.getElementById('status').textContent=d.status;";
  html += "}catch(e){document.getElementById('status').textContent='Koneksi lokal gagal';}}";
  html += "refresh();setInterval(refresh,2000);</script></body></html>";
  server.send(200, "text/html; charset=utf-8", html);
}

void tampilkanData() {
  String json = "{\"suhu\":";
  json += suhuValid ? String(suhuC, 1) : String("null");
  json += ",\"ph\":";
  json += phValid ? String(nilaiPH, 2) : String("null");
  json += ",\"volt\":" + String(voltPH, 3);
  // Status yang ditampilkan merupakan teks dari kode/HTTP, bukan input browser.
  json += ",\"status\":\"";
  // Hindari karakter JSON khusus dalam status.
  String safe = statusKirim;
  safe.replace("\\", " ");
  safe.replace("\"", "'");
  safe.replace("\n", " ");
  safe.replace("\r", " ");
  json += safe + "\"}";
  server.send(200, "application/json", json);
}

void setup() {
  Serial.begin(115200);
  delay(1000);
  suhuSensor.begin();
  analogReadResolution(12);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);

  bool wifiManualDiisi = String(WIFI_SSID) != "ISI_NAMA_WIFI" &&
                         String(WIFI_SSID).length() > 0;
  if (wifiManualDiisi) {
    Serial.print("Menghubungkan ke Wi-Fi: ");
    Serial.println(WIFI_SSID);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    unsigned long mulai = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - mulai < 20000) {
      delay(500);
      Serial.print(".");
    }
    Serial.println();
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi manual gagal / belum diisi. Membuka WiFiManager...");
    WiFiManager wifiManager;
    wifiManager.setConfigPortalTimeout(180);
    if (!wifiManager.autoConnect("OWC_Setup")) {
      Serial.println("Wi-Fi gagal; ESP32 restart");
      delay(3000);
      ESP.restart();
    }
  }
  Serial.print("IP ESP32: ");
  Serial.println(WiFi.localIP());
  if (MDNS.begin("owc")) Serial.println("Web lokal: http://owc.local");
  server.on("/", tampilkanWeb);
  server.on("/data", tampilkanData);
  server.begin();
  bacaSensor();
}

void loop() {
  server.handleClient();
  static unsigned long terakhirBaca = 0;
  static unsigned long terakhirKirim = 0;
  unsigned long sekarang = millis();
  if (sekarang - terakhirBaca >= READ_INTERVAL_MS) {
    terakhirBaca = sekarang;
    bacaSensor();
  }
  if (sekarang - terakhirKirim >= SEND_INTERVAL_MS) {
    terakhirKirim = sekarang;
    kirimKeBolu();
  }
}
