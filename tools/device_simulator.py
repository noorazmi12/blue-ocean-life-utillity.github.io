#!/usr/bin/env python3
"""
SMART-KJA OWC — Simulator Perangkat (untuk pengujian lokal tanpa sensor nyata)

Mengirim data terukur secara realistis (bukan angka acak murni — memakai gelombang
sinus yang berkelanjutan) ke api/ingest.php sehingga indikator LIVE dan grafik
dapat diuji sebelum perangkat nyata tersedia.

Pemakaian (jalankan dari komputer dengan XAMPP):
    python tools/device_simulator.py
    python tools/device_simulator.py --url http://localhost/smart-kja-owc --interval 30 --once

Token di bawah harus sama dengan yang tercatat di database (lihat README:
"Token demo perangkat"). Server hanya menyimpan hash SHA-256.
"""

import argparse
import hashlib
import json
import math
import time
import urllib.request
from datetime import datetime

TOKENS = {
    "WAV-001": "demo-wave-token",
    "WTR-001": "demo-water-token",
    "OWC-001": "demo-owc-token",
}

URL = "http://localhost/smart-kja-owc/api/ingest.php"


def http_post(url: str, payload: dict) -> None:
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            print(f"  -> {resp.status} {resp.read().decode('utf-8')[:160]}")
    except Exception as exc:  # noqa: BLE001
        print(f"  !! gagal: {exc}")


def smooth(t: float, period: float, amp: float, base: float) -> float:
    """Gelombang sinus halus + sedikit variasi — meniru kondisi laut."""
    return round(base + amp * math.sin(t / period) + amp * 0.2 * math.sin(t / (period / 3.7)), 3)


def wave_payload(t: int) -> dict:
    return {
        "device_code": "WAV-001",
        "api_key": TOKENS["WAV-001"],
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "data": {
            "wave_height_m": max(0.05, smooth(t, 12, 0.30, 0.55)),
            "wave_period_s": round(4.2 + 1.0 * math.sin(t / 9 + 1.3), 2),
            "wave_direction_deg": round(115 + 35 * math.sin(t / 15), 0),
            "wind_speed_ms": max(0.2, smooth(t, 11, 1.0, 2.8)),
            "wind_direction_deg": round(205 + 30 * math.sin(t / 8), 0),
        },
    }


def water_payload(t: int) -> dict:
    return {
        "device_code": "WTR-001",
        "api_key": TOKENS["WTR-001"],
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "data": {
            "temp_sensor_c": round(29.1 + 0.8 * math.sin(t / 16), 2),
            "salinity_psu": round(31.4 + 0.5 * math.sin(t / 18 + 0.5), 2),
            "ph": round(7.95 + 0.2 * math.sin(t / 13 + 2), 2),
            "turbidity_ntu": round(max(0.4, 5.5 + 2.8 * math.sin(t / 7)), 2),
            "depth_m": round(12.4 + 0.6 * math.sin(t / 14), 2),
        },
    }


def owc_payload(t: int) -> dict:
    h = max(0.05, smooth(t, 12, 0.30, 0.55))
    per = 4.2 + 1.0 * math.sin(t / 9 + 1.3)
    power = min(950.0, max(0.0, 210 * h * h * per))
    voltage = 21.5 + 2.5 * h
    return {
        "device_code": "OWC-001",
        "api_key": TOKENS["OWC-001"],
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "data": {
            "power_w": round(power, 1),
            "voltage_v": round(voltage, 2),
            "current_a": round(power / voltage, 2),
            "battery_pct": round(78 + 8 * math.sin(t / 24), 1),
        },
    }


def main() -> None:
    global URL
    parser = argparse.ArgumentParser(description="Simulator perangkat SMART-KJA OWC")
    parser.add_argument("--url", default=URL, help="URL dasar aplikasi")
    parser.add_argument("--interval", type=int, default=60, help="Interval kirim data (detik)")
    parser.add_argument("--once", action="store_true", help="Kirim satu kali lalu berhenti")
    args = parser.parse_args()

    # Normalisasi URL
    URL = args.url.rstrip("/")
    if not URL.endswith("/api/ingest.php"):
        URL += "/api/ingest.php"

    print(f"Mengirim data ke {URL} setiap {args.interval} detik. Ctrl+C untuk berhenti.")
    t = int(time.time())
    i = 0
    while True:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] kirim paket #{i + 1}")
        http_post(URL, wave_payload(t + i * 10))
        http_post(URL, water_payload(t + i * 10))
        http_post(URL, owc_payload(t + i * 10))
        i += 1
        if args.once:
            break
        try:
            time.sleep(args.interval)
        except KeyboardInterrupt:
            print("\nSelesai.")
            break


if __name__ == "__main__":
    main()
