import { formatYmd, parseDbDateMs, store } from '../data/store.ts';
import type { DeviceType } from '../types.ts';
import { dataStatus, fmtTimeHi, fmtTimeHis, setting, settingFloat, settingInt, wavePowerKw } from './helpers.ts';

export function skjaDownsample<T>(points: T[], max = 120): T[] {
  const n = points.length;
  if (n === 0 || n <= max) return points;
  const step = Math.ceil(n / max);
  const out: T[] = [];
  for (let i = 0; i < n; i += step) {
    out.push(points[i]);
  }
  const last = points[n - 1];
  if (out[out.length - 1] !== last) {
    out.push(last);
  }
  return out;
}

export function skjaBootDeviceId(type: DeviceType): number {
  const d = store.devices
    .filter((x) => x.device_type === type && x.is_active === 1)
    .sort((a, b) => a.id - b.id)[0];
  return d ? d.id : 0;
}

export function skjaBootWave(minutes: number, from = '', to = '') {
  const devId = skjaBootDeviceId('wave_sensor');
  if (!devId) return { points: [], stats: null };

  let rows = store.waveMeasurements.filter((r) => r.device_id === devId);
  if (/^\d{4}-\d{2}-\d{2}$/.test(from) && /^\d{4}-\d{2}-\d{2}$/.test(to)) {
    const start = `${from} 00:00:00`;
    const end = `${to} 23:59:59`;
    rows = rows.filter((r) => r.measured_at >= start && r.measured_at <= end);
  } else {
    const mins = Math.max(5, Math.min(10080, minutes));
    const cutoff = Date.now() - mins * 60 * 1000;
    rows = rows.filter((r) => {
      const ms = parseDbDateMs(r.measured_at);
      return ms !== null && ms >= cutoff;
    });
  }
  rows = rows.slice().sort((a, b) => a.measured_at.localeCompare(b.measured_at)).slice(0, 3000);

  const points: Array<{ t: string; h: number; p: number; w: number | null }> = [];
  const hs: number[] = [];
  for (const r of rows) {
    const h = Number(r.wave_height_m);
    hs.push(h);
    points.push({
      t: fmtTimeHi(r.measured_at),
      h,
      p: Number(r.wave_period_s),
      w: r.wind_speed_ms !== null ? Number(r.wind_speed_ms) : null,
    });
  }
  let stats = null;
  if (hs.length > 0) {
    const min = Math.min(...hs);
    const max = Math.max(...hs);
    const avg = hs.reduce((s, v) => s + v, 0) / hs.length;
    stats = {
      min: Number(min.toFixed(3)),
      max: Number(max.toFixed(3)),
      avg: Number(avg.toFixed(3)),
      count: hs.length,
    };
  }
  return { points: skjaDownsample(points), stats };
}

export function skjaBootWater(param: 'temp_sensor_c' | 'ph' | 'volt_ph_v', minutes: number) {
  const devId = skjaBootDeviceId('water_sensor');
  if (!devId) return [];
  const mins = Math.max(5, Math.min(20160, minutes));
  const cutoff = Date.now() - mins * 60 * 1000;
  const rows = store.waterMeasurements
    .filter((r) => {
      if (r.device_id !== devId || r[param] === null || r[param] === undefined) return false;
      const ms = parseDbDateMs(r.measured_at);
      return ms !== null && ms >= cutoff;
    })
    .sort((a, b) => a.measured_at.localeCompare(b.measured_at) || a.id - b.id)
    .slice(-3000);

  const points = rows.map((r) => ({
    t: fmtTimeHis(r.measured_at),
    v: Number(r[param]),
  }));
  return skjaDownsample(points);
}

export function skjaBootEnergy(minutes: number) {
  const devId = skjaBootDeviceId('owc');
  if (!devId) return [];
  const mins = Math.max(5, Math.min(10080, minutes));
  const cutoff = Date.now() - mins * 60 * 1000;
  const waveCutoff = Date.now() - (mins + 10) * 60 * 1000;

  const rows = store.owcMeasurements
    .filter((r) => {
      if (r.device_id !== devId) return false;
      const ms = parseDbDateMs(r.measured_at);
      return ms !== null && ms >= cutoff;
    })
    .sort((a, b) => a.measured_at.localeCompare(b.measured_at))
    .slice(0, 3000);

  const waveBySec = new Map<number, { wave_height_m: number; wave_period_s: number }>();
  for (const w of store.waveMeasurements) {
    const ms = parseDbDateMs(w.measured_at);
    if (ms !== null && ms >= waveCutoff) {
      waveBySec.set(Math.floor(ms / 1000), w);
    }
  }

  const width = settingFloat('owc_capture_width_m', 3.0);
  const eff = settingFloat('owc_efficiency', 0.3);

  const points = rows.map((r) => {
    const ms = parseDbDateMs(r.measured_at) ?? 0;
    const sec = Math.floor(ms / 1000);
    let est: number | null = null;
    for (const cand of [sec, sec - 300, sec + 300, sec - 600]) {
      const w = waveBySec.get(cand);
      if (w) {
        est = Number((wavePowerKw(w.wave_height_m, w.wave_period_s) * 1000 * width * eff).toFixed(1));
        break;
      }
    }
    return {
      t: fmtTimeHi(r.measured_at),
      p: Number(r.power_w),
      e: Number(r.energy_wh),
      batt: r.battery_pct !== null ? Number(r.battery_pct) : null,
      est,
    };
  });
  return skjaDownsample(points);
}

export function skjaBootEnergyDaily(days: number) {
  const devId = skjaBootDeviceId('owc');
  if (!devId) return [];
  const dDays = Math.max(1, Math.min(90, days));
  const cutoff = Date.now() - dDays * 86400 * 1000;

  const groups = new Map<string, Array<{ energy_wh: number; power_w: number }>>();
  for (const r of store.owcMeasurements) {
    if (r.device_id !== devId) continue;
    const ms = parseDbDateMs(r.measured_at);
    if (ms === null || ms < cutoff) continue;
    const day = formatYmd(ms);
    if (!groups.has(day)) groups.set(day, []);
    groups.get(day)!.push({ energy_wh: Number(r.energy_wh), power_w: Number(r.power_w) });
  }

  return Array.from(groups.entries())
    .sort((a, b) => a[0].localeCompare(b[0]))
    .map(([d, arr]) => {
      const energies = arr.map((x) => x.energy_wh);
      const powers = arr.map((x) => x.power_w);
      return {
        d,
        energy_wh: Number((Math.max(...energies) - Math.min(...energies)).toFixed(1)),
        peak_w: Math.max(...powers),
        avg_w: Number((powers.reduce((s, x) => s + x, 0) / powers.length).toFixed(1)),
      };
    });
}

export function skjaBootGeo() {
  const devices = store.devices
    .filter((d) => d.is_active === 1)
    .sort((a, b) => a.id - b.id);

  const lastByDev = new Map<number, string>();
  const bump = (devId: number | null, dt: string | null) => {
    if (!devId || !dt) return;
    const prev = lastByDev.get(devId);
    if (!prev || dt > prev) lastByDev.set(devId, dt);
  };
  for (const r of store.waveMeasurements) bump(r.device_id, r.measured_at);
  for (const r of store.owcMeasurements) bump(r.device_id, r.measured_at);
  for (const r of store.waterMeasurements) bump(r.device_id, r.measured_at);
  for (const r of store.fishcountResults) bump(r.device_id, r.created_at);

  const out = devices.map((d) => {
    const loc = store.deviceLocations.find((l) => l.device_id === d.id) ?? null;
    return {
      id: d.id,
      code: d.device_code,
      name: d.device_name,
      type: d.device_type,
      status: d.status,
      lat: loc ? Number(loc.latitude) : null,
      lng: loc ? Number(loc.longitude) : null,
      location_name: loc ? loc.location_name : null,
      data_status: dataStatus(lastByDev.get(d.id) ?? null),
    };
  });

  return {
    center: {
      lat: Number(setting('map_center_lat', -5.77)),
      lng: Number(setting('map_center_lng', 105.264)),
    },
    zoom: settingInt('map_zoom', 15),
    devices: out,
  };
}
