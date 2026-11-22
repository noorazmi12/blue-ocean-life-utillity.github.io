import { formatDbDate, formatYmd, nextId, parseDbDateMs, store } from '../data/store.ts';
import type { Threshold } from '../types.ts';

export const APP_NAME = 'BOLU';
export const APP_LONG_NAME =
  'Sistem Monitoring Energi Gelombang, Kualitas Perairan, dan Biota pada Keramba Jaring Apung';
export const APP_VERSION = '1.0.0';
export const DEMO_MODE = false;
export const SHOW_QUICK_ACCOUNTS = true;
export const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;

export const PARAM_LABELS: Record<string, string> = {
  wave_height_m: 'Tinggi Gelombang',
  wave_period_s: 'Periode Gelombang',
  temp_sensor_c: 'Suhu Air (Sensor)',
  sst_c: 'SST (Suhu Permukaan Laut)',
  salinity_psu: 'Salinitas',
  ph: 'pH',
  turbidity_ntu: 'Kekeruhan',
  depth_m: 'Kedalaman',
  power_w: 'Daya OWC',
  battery_pct: 'Status Baterai',
  device_offline: 'Perangkat Offline',
  fish_count: 'Jumlah Ikan',
};

const BULAN_ID = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const WIB_OFFSET_MS = 7 * 3600 * 1000;

export function escHtml(s: unknown): string {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export function safeJsonForScript(obj: unknown): string {
  return JSON.stringify(obj)
    .replace(/</g, '\\u003C')
    .replace(/>/g, '\\u003E')
    .replace(/&/g, '\\u0026')
    .replace(/'/g, '\\u0027');
}

export function setting(key: string, def: string | number | null = null): string | null {
  const hit = store.systemSettings.get(key);
  if (hit !== undefined) return hit.setting_value;
  return def !== null ? String(def) : null;
}

export function settingInt(key: string, def: number): number {
  const v = setting(key, def);
  const n = Number.parseInt(String(v ?? def), 10);
  return Number.isNaN(n) ? def : n;
}

export function settingFloat(key: string, def: number): number {
  const v = setting(key, def);
  const n = Number.parseFloat(String(v ?? def));
  return Number.isNaN(n) ? def : n;
}

export function saveSetting(key: string, value: string): void {
  const existing = store.systemSettings.get(key);
  const now = formatDbDate();
  if (existing) {
    existing.setting_value = value;
    existing.updated_at = now;
  } else {
    store.systemSettings.set(key, {
      id: store.systemSettings.size + 1,
      setting_key: key,
      setting_value: value,
      description: null,
      updated_at: now,
    });
  }
}

export function fmtNum(v: unknown, dec = 2): string {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  if (!Number.isFinite(n)) return '—';
  const fixed = n.toFixed(dec);
  const [intPart, decPart] = fixed.split('.');
  const withThousands = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return dec > 0 && decPart !== undefined ? `${withThousands},${decPart}` : withThousands;
}

export function fmtDt(dt: string | null | undefined): string {
  const ms = parseDbDateMs(dt);
  if (ms === null) return '—';
  const d = new Date(ms + WIB_OFFSET_MS);
  const p = (n: number) => String(n).padStart(2, '0');
  const mon = BULAN_ID[d.getUTCMonth() + 1] ?? '';
  return `${d.getUTCDate()} ${mon} ${d.getUTCFullYear()} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`;
}

export function fmtDtShort(dt: string | null | undefined): string {
  const ms = parseDbDateMs(dt);
  if (ms === null) return '—';
  const d = new Date(ms + WIB_OFFSET_MS);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(d.getUTCDate())}/${p(d.getUTCMonth() + 1)} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`;
}

export function fmtTimeHis(dt: string | null | undefined): string {
  const ms = parseDbDateMs(dt);
  if (ms === null) return '00:00:00';
  const d = new Date(ms + WIB_OFFSET_MS);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`;
}

export function fmtTimeHi(dt: string | null | undefined): string {
  const ms = parseDbDateMs(dt);
  if (ms === null) return '00:00';
  const d = new Date(ms + WIB_OFFSET_MS);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`;
}

export function timeAgo(dt: string | null | undefined): string {
  if (!dt) return 'belum pernah';
  const ms = parseDbDateMs(dt);
  if (ms === null) return '—';
  const diff = Math.max(0, Math.floor((Date.now() - ms) / 1000));
  if (diff < 60) return `${diff} detik lalu`;
  if (diff < 3600) return `${Math.floor(diff / 60)} menit lalu`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} jam lalu`;
  return `${Math.floor(diff / 86400)} hari lalu`;
}

export function dataStatus(measuredAt: string | null | undefined): 'live' | 'delayed' | 'offline' {
  const ms = parseDbDateMs(measuredAt);
  if (ms === null) return 'offline';
  const stale = Math.max(1, settingInt('data_stale_minutes', 10));
  const ageMin = (Date.now() - ms) / 60000;
  if (ageMin <= stale) return 'live';
  if (ageMin <= stale * 3) return 'delayed';
  return 'offline';
}

export function dataStatusLabel(status: string): string {
  const map: Record<string, string> = { live: 'LIVE', delayed: 'DELAYED', offline: 'OFFLINE' };
  return map[status] ?? 'OFFLINE';
}

export function statusPill(status: string): string {
  const label = dataStatusLabel(status);
  return `<span class="status-pill st-${escHtml(status)}"><span class="dot"></span>${label}</span>`;
}

export function deviceStatusBadge(status: string): string {
  const map: Record<string, [string, string, string]> = {
    online: ['badge-green', 'fa-circle-check', 'Online'],
    offline: ['badge-gray', 'fa-circle-xmark', 'Offline'],
    maintenance: ['badge-yellow', 'fa-screwdriver-wrench', 'Perawatan'],
  };
  const [cls, icon, label] = map[status] ?? map.offline;
  return `<span class="badge ${cls}"><i class="fa-solid ${icon}"></i>${label}</span>`;
}

export function severityBadge(sev: string): string {
  const map: Record<string, [string, string, string]> = {
    info: ['badge-blue', 'fa-circle-info', 'Informasi'],
    warning: ['badge-yellow', 'fa-triangle-exclamation', 'Peringatan'],
    critical: ['badge-red', 'fa-circle-exclamation', 'Kritis'],
  };
  const [cls, icon, label] = map[sev] ?? map.info;
  return `<span class="badge ${cls}"><i class="fa-solid ${icon}"></i>${label}</span>`;
}

export function demoBadge(isSimulated: boolean | number): string {
  if (!isSimulated || !DEMO_MODE) return '';
  return (
    '<span class="badge badge-yellow badge-demo" title="Data ini dihasilkan oleh seeder demo, bukan sensor nyata">' +
    '<i class="fa-solid fa-flask"></i>DEMO</span>'
  );
}

export function paramLabel(param: string): string {
  return PARAM_LABELS[param] ?? param.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export function getThreshold(param: string, species = 'Umum'): Threshold | null {
  return store.thresholds.find((t) => t.parameter === param && t.species === species) ?? null;
}

export function evaluateValue(
  param: string,
  value: unknown
): { status: 'normal' | 'warning' | 'critical'; label: string; threshold: Threshold | null; dir?: 'below' | 'above' } {
  const th = getThreshold(param);
  if (!th || value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
    return { status: 'normal', label: 'Normal', threshold: th };
  }
  const v = Number(value);
  const min = th.min_value !== null ? Number(th.min_value) : -Infinity;
  const max = th.max_value !== null ? Number(th.max_value) : Infinity;
  const span = th.warn_span !== null ? Number(th.warn_span) : 0;
  if (v >= min && v <= max) {
    return { status: 'normal', label: 'Normal', threshold: th };
  }
  const over = v < min ? min - v : v - max;
  const dir = v < min ? 'below' : 'above';
  if (over <= span) {
    return { status: 'warning', label: 'Peringatan', threshold: th, dir };
  }
  return { status: 'critical', label: 'Kritis', threshold: th, dir };
}

export function maybeCreateAlert(deviceId: number, param: string, value: number, isSimulated = false): boolean {
  const ev = evaluateValue(param, value);
  if (ev.status === 'normal' || !ev.threshold || !ev.dir) return false;
  const cutoff = Date.now() - 15 * 60 * 1000;
  const recent = store.alerts.find((a) => {
    if (a.device_id !== deviceId || a.parameter !== param || a.status !== 'open') return false;
    const ms = parseDbDateMs(a.occurred_at);
    return ms !== null && ms >= cutoff;
  });
  if (recent) return false;

  const th = ev.threshold;
  const lim = ev.dir === 'below' ? th.min_value : th.max_value;
  const sev = ev.status === 'critical' ? 'critical' : 'warning';
  const unit = th.unit ?? '';
  const title =
    paramLabel(param) +
    (ev.dir === 'below' ? ' di bawah batas ' : ' di atas batas ') +
    (sev === 'critical' ? 'kritis' : 'normal');
  const msg =
    'Nilai terukur ' +
    fmtNum(value, 2) +
    (unit !== '' ? ' ' + unit : '') +
    ', batas ' +
    (ev.dir === 'below' ? 'minimum' : 'maksimum') +
    ' ' +
    fmtNum(lim, 2) +
    '.';

  const now = formatDbDate();
  store.alerts.push({
    id: nextId(store.alerts),
    device_id: deviceId,
    parameter: param,
    severity: sev,
    title,
    message: msg,
    actual_value: value,
    threshold_value: lim,
    occurred_at: now,
    status: 'open',
    resolved_by: null,
    resolved_at: null,
    is_simulated: isSimulated ? 1 : 0,
    created_at: now,
  });
  return true;
}

export function wavePowerKw(h: number | null | undefined, t: number | null | undefined): number {
  if (h === null || h === undefined || t === null || t === undefined) return 0;
  if (!Number.isFinite(Number(h)) || !Number.isFinite(Number(t))) return 0;
  return 0.49 * Number(h) * Number(h) * Number(t);
}

export function electricEstimateW(h: number | null | undefined, t: number | null | undefined): number {
  const width = settingFloat('owc_capture_width_m', 3.0);
  const eff = settingFloat('owc_efficiency', 0.3);
  return Math.max(0, wavePowerKw(h, t) * 1000 * width * eff);
}

export function degToCompass(deg: number | null | undefined): string {
  if (deg === null || deg === undefined || !Number.isFinite(Number(deg))) return '—';
  const dirs = ['U', 'TL', 'T', 'TG', 'S', 'BD', 'B', 'BL'];
  const idx = Math.round((((Number(deg) % 360) + 360) % 360) / 45) % 8;
  return dirs[idx];
}

export function dateRangeFromQuery(
  query: Record<string, unknown>,
  defaultFrom?: string,
  defaultTo?: string
): [string, string] {
  const defFrom = defaultFrom ?? formatYmd(Date.now() - 7 * 86400 * 1000);
  const defTo = defaultTo ?? formatYmd();
  let from = typeof query.from === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(query.from) ? query.from : defFrom;
  let to = typeof query.to === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(query.to) ? query.to : defTo;
  if (from > to) {
    [from, to] = [to, from];
  }
  return [from, to];
}

export function qsMerge(query: Record<string, unknown>, overrides: Record<string, unknown>): string {
  const params = new URLSearchParams();
  const merged = { ...query, ...overrides };
  delete merged.export;
  for (const [k, v] of Object.entries(merged)) {
    if (v !== undefined && v !== null && v !== '') {
      params.set(k, String(v));
    }
  }
  return '?' + params.toString();
}

export function buildCsv(header: string[], rows: Array<Array<unknown>>): string {
  const csvCell = (val: unknown) => {
    const s = val === null || val === undefined ? '' : String(val);
    if (/[",\r\n]/.test(s)) {
      return `"${s.replace(/"/g, '""')}"`;
    }
    return s;
  };
  const lines = [header.map(csvCell).join(',')];
  for (const r of rows) {
    lines.push(r.map(csvCell).join(','));
  }
  return '\uFEFF' + lines.join('\r\n') + '\r\n';
}

export function strTrimWidth(s: string | null | undefined, maxLen = 60, marker = '…'): string {
  const str = String(s ?? '');
  if (str.length <= maxLen) return str;
  return str.slice(0, Math.max(0, maxLen - marker.length)) + marker;
}
