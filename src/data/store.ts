import fs from 'node:fs';
import path from 'node:path';
import bcrypt from 'bcryptjs';
import type {
  AlertItem,
  Device,
  DeviceLocation,
  FishcountResult,
  FishSpecies,
  KjaSite,
  OwcMeasurement,
  SystemSetting,
  Threshold,
  User,
  WaveMeasurement,
  WaterMeasurement,
} from '../types.ts';

const WIB_OFFSET_MS = 7 * 3600 * 1000;

export function parseDbDateMs(dt: string | null | undefined): number | null {
  if (!dt) return null;
  const s = String(dt).trim();
  if (!s) return null;
  const iso = /^\d{4}-\d{2}-\d{2}$/.test(s)
    ? `${s}T00:00:00+07:00`
    : `${s.replace(' ', 'T')}+07:00`;
  const ms = Date.parse(iso);
  return Number.isNaN(ms) ? null : ms;
}

export function formatDbDate(ms: number = Date.now()): string {
  const d = new Date(ms + WIB_OFFSET_MS);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`;
}

export function formatYmd(ms: number = Date.now()): string {
  const d = new Date(ms + WIB_OFFSET_MS);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())}`;
}

function shiftDate(dt: string | null | undefined, deltaMs: number): string | null {
  const ms = parseDbDateMs(dt);
  if (ms === null) return null;
  return formatDbDate(ms + deltaMs);
}

interface RawSeedSql {
  devices?: Device[];
  device_locations?: DeviceLocation[];
  thresholds?: Threshold[];
  system_settings?: SystemSetting[];
  fish_species?: FishSpecies[];
  kja_sites?: KjaSite[];
  water_measurements?: Array<Omit<WaterMeasurement, 'volt_ph_v'> & { volt_ph_v?: number | null }>;
  owc_measurements?: OwcMeasurement[];
  wave_measurements?: WaveMeasurement[];
  fishcount_results?: FishcountResult[];
  alerts?: AlertItem[];
  users?: User[];
}

const seedPath = path.join(process.cwd(), 'src', 'data', 'seedSql.json');
const raw: RawSeedSql = JSON.parse(fs.readFileSync(seedPath, 'utf-8'));

// Shift all historical measurement timestamps so latest reading is ~2 minutes before startup
let maxMeasMs = 0;
for (const r of raw.water_measurements ?? []) {
  const ms = parseDbDateMs(r.measured_at);
  if (ms && ms > maxMeasMs) maxMeasMs = ms;
}
for (const r of raw.owc_measurements ?? []) {
  const ms = parseDbDateMs(r.measured_at);
  if (ms && ms > maxMeasMs) maxMeasMs = ms;
}
for (const r of raw.wave_measurements ?? []) {
  const ms = parseDbDateMs(r.measured_at);
  if (ms && ms > maxMeasMs) maxMeasMs = ms;
}
const targetLatestMs = Date.now() - 2 * 60 * 1000;
const deltaMs = maxMeasMs > 0 ? targetLatestMs - maxMeasMs : 0;

const defaultPasswords: Record<string, string> = {
  admin: 'Admin@123',
  operator: 'Operator@123',
  viewer: 'Viewer@123',
};

function num(v: unknown, fallback = 0): number {
  if (v === null || v === undefined || v === '') return fallback;
  const n = Number(v);
  return Number.isFinite(n) ? n : fallback;
}

function numOrNull(v: unknown): number | null {
  if (v === null || v === undefined || v === '') return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

export const store = {
  users: (raw.users ?? []).map((u) => ({
    ...u,
    id: num(u.id),
    is_active: num(u.is_active, 1),
    password_hash: bcrypt.hashSync(defaultPasswords[u.username] ?? 'Bolu@12345', 10),
    last_login: shiftDate(u.last_login, deltaMs),
    created_at: shiftDate(u.created_at, deltaMs) ?? formatDbDate(),
  })) as User[],

  devices: (raw.devices ?? []).map((d) => ({
    ...d,
    id: num(d.id),
    is_active: num(d.is_active, 1),
    last_communication: shiftDate(d.last_communication, deltaMs),
    created_at: shiftDate(d.created_at, deltaMs) ?? formatDbDate(),
  })) as Device[],

  deviceLocations: (raw.device_locations ?? []).map((l) => ({
    ...l,
    id: num(l.id),
    device_id: num(l.device_id),
    latitude: num(l.latitude),
    longitude: num(l.longitude),
    updated_at: shiftDate(l.updated_at, deltaMs),
  })) as DeviceLocation[],

  thresholds: (raw.thresholds ?? []).map((t) => ({
    ...t,
    id: num(t.id),
    min_value: numOrNull(t.min_value),
    max_value: numOrNull(t.max_value),
    warn_span: numOrNull(t.warn_span),
    updated_by: numOrNull(t.updated_by),
    updated_at: shiftDate(t.updated_at, deltaMs),
  })) as Threshold[],

  systemSettings: new Map<string, SystemSetting>(
    (raw.system_settings ?? []).map((s) => [
      s.setting_key,
      { ...s, id: num(s.id) },
    ])
  ),

  fishSpecies: (raw.fish_species ?? []).map((f) => ({
    ...f,
    id: num(f.id),
    cycle_months_min: numOrNull(f.cycle_months_min),
    cycle_months_max: numOrNull(f.cycle_months_max),
    target_weight_kg: numOrNull(f.target_weight_kg),
    lw_a: num(f.lw_a, 0.01),
    lw_b: num(f.lw_b, 3),
    optimal_temp_min: numOrNull(f.optimal_temp_min),
    optimal_temp_max: numOrNull(f.optimal_temp_max),
    optimal_ph_min: numOrNull(f.optimal_ph_min),
    optimal_ph_max: numOrNull(f.optimal_ph_max),
    optimal_salinity_min: numOrNull(f.optimal_salinity_min),
    optimal_salinity_max: numOrNull(f.optimal_salinity_max),
    is_active: num(f.is_active, 1),
    created_at: shiftDate(f.created_at, deltaMs) ?? formatDbDate(),
    updated_at: shiftDate(f.updated_at, deltaMs),
  })) as FishSpecies[],

  kjaSites: (raw.kja_sites ?? []).map((k) => ({
    ...k,
    id: num(k.id),
    latitude: num(k.latitude),
    longitude: num(k.longitude),
    depth_m: numOrNull(k.depth_m),
    score_depth: num(k.score_depth),
    score_shelter: num(k.score_shelter),
    score_access: num(k.score_access),
    score_conflict: num(k.score_conflict),
    suitability_score: num(k.suitability_score),
    is_active: num(k.is_active, 1),
  })) as KjaSite[],

  waterMeasurements: (raw.water_measurements ?? []).map((w) => {
    const shifted = shiftDate(w.measured_at, deltaMs) ?? formatDbDate();
    const phVal = numOrNull(w.ph);
    const voltPh =
      w.volt_ph_v !== undefined && w.volt_ph_v !== null
        ? numOrNull(w.volt_ph_v)
        : phVal !== null
          ? Number((1.5 + (7.0 - phVal) * 0.17).toFixed(3))
          : null;
    return {
      ...w,
      id: num(w.id),
      device_id: num(w.device_id),
      temp_sensor_c: numOrNull(w.temp_sensor_c),
      sst_c: numOrNull(w.sst_c),
      salinity_psu: numOrNull(w.salinity_psu),
      ph: phVal,
      turbidity_ntu: numOrNull(w.turbidity_ntu),
      depth_m: numOrNull(w.depth_m),
      is_simulated: num(w.is_simulated),
      measured_at: shifted,
      volt_ph_v: voltPh,
      created_at: shiftDate(w.created_at, deltaMs) ?? shifted,
    };
  }) as WaterMeasurement[],

  owcMeasurements: (raw.owc_measurements ?? []).map((o) => {
    const shifted = shiftDate(o.measured_at, deltaMs) ?? formatDbDate();
    return {
      ...o,
      id: num(o.id),
      device_id: num(o.device_id),
      power_w: num(o.power_w),
      voltage_v: num(o.voltage_v),
      current_a: num(o.current_a),
      energy_wh: num(o.energy_wh),
      battery_pct: numOrNull(o.battery_pct),
      is_simulated: num(o.is_simulated),
      measured_at: shifted,
      created_at: shiftDate(o.created_at, deltaMs) ?? shifted,
    };
  }) as OwcMeasurement[],

  waveMeasurements: (raw.wave_measurements ?? []).map((w) => {
    const shifted = shiftDate(w.measured_at, deltaMs) ?? formatDbDate();
    return {
      ...w,
      id: num(w.id),
      device_id: num(w.device_id),
      wave_height_m: num(w.wave_height_m),
      wave_period_s: num(w.wave_period_s),
      wave_direction_deg: numOrNull(w.wave_direction_deg),
      wind_speed_ms: numOrNull(w.wind_speed_ms),
      wind_direction_deg: numOrNull(w.wind_direction_deg),
      is_simulated: num(w.is_simulated),
      measured_at: shifted,
      created_at: shiftDate(w.created_at, deltaMs) ?? shifted,
    };
  }) as WaveMeasurement[],

  fishcountResults: (raw.fishcount_results ?? []).map((f) => ({
    ...f,
    id: num(f.id),
    device_id: numOrNull(f.device_id),
    media_duration_s: numOrNull(f.media_duration_s),
    frames_analyzed: numOrNull(f.frames_analyzed),
    detected_count: numOrNull(f.detected_count),
    avg_length_cm: numOrNull(f.avg_length_cm),
    biomass_kg: numOrNull(f.biomass_kg),
    confidence_pct: numOrNull(f.confidence_pct),
    is_simulated: num(f.is_simulated),
    is_manual: num(f.is_manual),
    manual_count: numOrNull(f.manual_count),
    validated_by: numOrNull(f.validated_by),
    validated_at: shiftDate(f.validated_at, deltaMs),
    created_at: shiftDate(f.created_at, deltaMs) ?? formatDbDate(),
  })) as FishcountResult[],

  alerts: (raw.alerts ?? []).map((a) => ({
    ...a,
    id: num(a.id),
    device_id: numOrNull(a.device_id),
    actual_value: numOrNull(a.actual_value),
    threshold_value: numOrNull(a.threshold_value),
    resolved_by: numOrNull(a.resolved_by),
    is_simulated: num(a.is_simulated),
    occurred_at: shiftDate(a.occurred_at, deltaMs) ?? formatDbDate(),
    resolved_at: shiftDate(a.resolved_at, deltaMs),
    created_at: shiftDate(a.created_at, deltaMs) ?? formatDbDate(),
  })) as AlertItem[],
};

export function nextId(arr: Array<{ id: number }>): number {
  let max = 0;
  for (const item of arr) {
    if (item.id > max) max = item.id;
  }
  return max + 1;
}
