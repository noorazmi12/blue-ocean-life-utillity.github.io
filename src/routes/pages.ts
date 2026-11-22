import crypto from 'node:crypto';
import bcrypt from 'bcryptjs';
import express, { type Request, type Response } from 'express';
import multer from 'multer';
import { formatDbDate, formatYmd, nextId, parseDbDateMs, store } from '../data/store.ts';
import type { CalibrationStatus, DeviceStatus, DeviceType, UserRole } from '../types.ts';
import {
  canManage,
  csrfCheck,
  csrfField,
  csrfToken,
  currentUser,
  flashRender,
  flashSet,
  isAdmin,
  requireLogin,
} from '../lib/auth.ts';
import {
  skjaBootEnergy,
  skjaBootEnergyDaily,
  skjaBootGeo,
  skjaBootWater,
  skjaBootWave,
} from '../lib/bootData.ts';
import {
  APP_NAME,
  APP_VERSION,
  DEMO_MODE,
  PARAM_LABELS,
  SHOW_QUICK_ACCOUNTS,
  UPLOAD_MAX_BYTES,
  buildCsv,
  dataStatus,
  dataStatusLabel,
  dateRangeFromQuery,
  degToCompass,
  demoBadge,
  deviceStatusBadge,
  escHtml,
  evaluateValue,
  fmtDt,
  fmtDtShort,
  fmtNum,
  paramLabel,
  qsMerge,
  safeJsonForScript,
  saveSetting,
  setting,
  settingFloat,
  settingInt,
  severityBadge,
  statusPill,
  strTrimWidth,
  timeAgo,
  wavePowerKw,
} from '../lib/helpers.ts';

export const pagesRouter = express.Router();

const uploadSpecies = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: UPLOAD_MAX_BYTES },
});

function asArr(val: unknown): string[] {
  if (Array.isArray(val)) return val.map((x) => String(x ?? ''));
  if (val === undefined || val === null) return [];
  return [String(val)];
}

function getUnreadAlertsCount(): number {
  return store.alerts.filter((a) => a.status === 'open').length;
}

function getDeviceStats(): { total: number; online: number } {
  const staleMin = Math.max(1, settingInt('data_stale_minutes', 10));
  const cutoff = Date.now() - staleMin * 60 * 1000;
  let total = 0;
  let online = 0;
  for (const d of store.devices) {
    if (d.is_active !== 1) continue;
    total++;
    if (d.status === 'online' && d.last_communication) {
      const ms = parseDbDateMs(d.last_communication);
      if (ms !== null && ms >= cutoff) online++;
    }
  }
  return { total, online };
}

function buildViewLocals(
  req: Request,
  opts: {
    pageId: string;
    pageTitle: string;
    breadcrumb: Array<[string, string | null]>;
    pageScripts?: string[];
    needLeaflet?: boolean;
    needChart?: boolean;
  }
) {
  const cu = currentUser(req);
  const refreshSec = Math.max(5, settingInt('dashboard_refresh_seconds', 15));
  const staleMin = Math.max(1, settingInt('data_stale_minutes', 10));
  return {
    APP_NAME,
    APP_VERSION,
    DEMO_MODE,
    SHOW_QUICK_ACCOUNTS,
    PARAM_LABELS,
    PAGE_ID: opts.pageId,
    PAGE_TITLE: opts.pageTitle,
    BREADCRUMB: opts.breadcrumb,
    PAGE_SCRIPTS: opts.pageScripts ?? [],
    NEED_LEAFLET: opts.needLeaflet ?? false,
    NEED_CHART: opts.needChart ?? true,
    cu,
    userInitials: cu ? (cu.full_name || cu.username).slice(0, 1).toUpperCase() : 'U',
    devStats: getDeviceStats(),
    unreadAlerts: getUnreadAlertsCount(),
    refreshSec,
    staleMin,
    csrfTokenVal: csrfToken(req),
    csrfFieldHtml: csrfField(req),
    flashHtml: flashRender(req),
    isAdm: isAdmin(req),
    canMgr: canManage(req),
    siteLocation: setting('site_location', 'Lokasi KJA') ?? 'Lokasi KJA',
    year: new Date().getFullYear(),
    e: escHtml,
    safeJson: safeJsonForScript,
    fmtNum,
    fmtDt,
    fmtDtShort,
    timeAgo,
    dataStatus,
    dataStatusLabel,
    statusPill,
    deviceStatusBadge,
    severityBadge,
    demoBadge,
    paramLabel,
    degToCompass,
    wavePowerKw,
    setting,
    settingInt,
    settingFloat,
    strTrimWidth,
    qsMerge: (overrides: Record<string, unknown>) =>
      qsMerge(req.query as Record<string, unknown>, overrides),
  };
}

function detectImageMime(buf: Buffer): string {
  if (buf.length >= 3 && buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) {
    return 'image/jpeg';
  }
  if (
    buf.length >= 8 &&
    buf[0] === 0x89 &&
    buf[1] === 0x50 &&
    buf[2] === 0x4e &&
    buf[3] === 0x47
  ) {
    return 'image/png';
  }
  if (
    buf.length >= 12 &&
    buf.subarray(0, 4).toString('ascii') === 'RIFF' &&
    buf.subarray(8, 12).toString('ascii') === 'WEBP'
  ) {
    return 'image/webp';
  }
  return '';
}

function fopt(raw: unknown, min: number | null, max: number | null): number | null {
  if (raw === undefined || raw === null || String(raw).trim() === '') return null;
  const v = Number(raw);
  if (!Number.isFinite(v)) return null;
  if (min !== null && v < min) return null;
  if (max !== null && v > max) return null;
  return v;
}

const VALID_REDIRECT_PAGES = new Set([
  'dashboard.php',
  'penjelasan-kja.php',
  'kualitas-air.php',
  'gelombang.php',
  'energi-owc.php',
  'fishcount.php',
  'fishcount-species.php',
  'webgis.php',
  'batimetri.php',
  'lokasi-kja.php',
  'prediksi-ai.php',
  'peringatan.php',
  'laporan.php',
  'perangkat.php',
  'jenis-ikan.php',
  'pengaturan.php',
]);

/* ==================== 1. LANDING PAGE ==================== */
pagesRouter.get(['/', '/index.php'], (req: Request, res: Response) => {
  const cu = currentUser(req);
  const activeDevices = store.devices.filter((d) => d.is_active === 1);
  const onlineDevices = activeDevices.filter((d) => d.status === 'online').length;
  const stats = {
    devices: activeDevices.length,
    active: onlineDevices,
    measure: store.waterMeasurements.length,
  };
  const lpMenu: Array<[string, string, string]> = [
    ['Beranda', 'index.php', 'fa-house'],
    ['Dashboard', 'dashboard.php', 'fa-gauge-high'],
    ['Kualitas Air', 'kualitas-air.php', 'fa-droplet'],
    ['FishCount AI', 'fishcount.php', 'fa-fish'],
    ['WebGIS', 'webgis.php', 'fa-map-location-dot'],
    ['Batimetri', 'batimetri.php', 'fa-mountain'],
    ['Lokasi KJA', 'lokasi-kja.php', 'fa-anchor'],
    ['Prediksi AI', 'prediksi-ai.php', 'fa-brain'],
    ['Tim Kami', '#tim', 'fa-users'],
  ];
  res.render('index', {
    APP_NAME,
    loggedIn: Boolean(cu),
    sessionName: req.session.name ?? cu?.full_name ?? cu?.username ?? 'Pengguna',
    stats,
    lpMenu,
    year: new Date().getFullYear(),
    e: escHtml,
    lpNum: (v: number | null) => (v === null ? '—' : fmtNum(v, 0)),
  });
});

/* ==================== 2. LOGIN & LOGOUT ==================== */
function buildSplashTitle(): string {
  let html = '';
  let si = 0;
  const parts: Array<[string, string]> = [
    ['BOLU', ''],
    ['OCEAN', 'accent'],
  ];
  for (const [w, cls] of parts) {
    if (html !== '') {
      html += `<span class="sp-gap" aria-hidden="true" style="--i:${si++}">&nbsp;</span>`;
    }
    for (const ch of w) {
      html += `<span aria-hidden="true"${cls ? ` class="${cls}"` : ''} style="--i:${si++}">${escHtml(ch)}</span>`;
    }
  }
  return html;
}

pagesRouter.get(['/login.php', '/login'], (req: Request, res: Response) => {
  if (currentUser(req)) {
    res.redirect('/dashboard.php');
    return;
  }
  const next = typeof req.query.next === 'string' ? req.query.next : '';
  res.render('login', {
    APP_NAME,
    APP_VERSION,
    SHOW_QUICK_ACCOUNTS,
    error: '',
    username: '',
    next,
    siteLocation: setting('site_location', 'Lokasi KJA') ?? 'Lokasi KJA',
    splashTitle: buildSplashTitle(),
    csrfFieldHtml: csrfField(req),
    year: new Date().getFullYear(),
    e: escHtml,
  });
});

pagesRouter.post(['/login.php', '/login'], (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;

  const username = typeof req.body.username === 'string' ? req.body.username.trim() : '';
  const password = typeof req.body.password === 'string' ? req.body.password : '';
  const next = typeof req.query.next === 'string' ? req.query.next : '';

  let error = '';
  if (username === '' || password === '') {
    error = 'Username/email dan password wajib diisi.';
  } else if (username.length > 100 || password.length > 255) {
    error = 'Format input tidak valid.';
  } else {
    const nowSec = Math.floor(Date.now() / 1000);
    const attempts = (req.session.login_attempts ?? []).filter((t) => nowSec - t < 300);
    if (attempts.length >= 5) {
      error = 'Terlalu banyak percobaan gagal. Tunggu 30 detik lalu coba lagi.';
    } else {
      const user = store.users.find(
        (u) => u.is_active === 1 && (u.username === username || u.email === username)
      );
      if (user && bcrypt.compareSync(password, user.password_hash)) {
        const preservedCsrf = csrfToken(req);
        req.session.regenerate((err) => {
          if (err) {
            res.status(500).send('Gagal membuat sesi login.');
            return;
          }
          req.session.user_id = user.id;
          req.session.role = user.role;
          req.session.name = user.full_name || user.username;
          req.session.csrf_token = preservedCsrf;
          req.session.login_attempts = undefined;
          user.last_login = formatDbDate();

          let target = '/dashboard.php';
          if (next && VALID_REDIRECT_PAGES.has(next)) {
            target = `/${next}`;
          }
          req.session.save(() => res.redirect(target));
        });
        return;
      }
      attempts.push(nowSec);
      req.session.login_attempts = attempts;
      error = 'Username/email atau password salah.';
    }
  }

  res.render('login', {
    APP_NAME,
    APP_VERSION,
    SHOW_QUICK_ACCOUNTS,
    error,
    username,
    next,
    siteLocation: setting('site_location', 'Lokasi KJA') ?? 'Lokasi KJA',
    splashTitle: buildSplashTitle(),
    csrfFieldHtml: csrfField(req),
    year: new Date().getFullYear(),
    e: escHtml,
  });
});

pagesRouter.get(['/logout.php', '/logout'], (req: Request, res: Response) => {
  req.session.destroy(() => {
    res.clearCookie('bolu_sid');
    res.redirect('/login.php');
  });
});

/* ==================== 3. DASHBOARD ==================== */
pagesRouter.get(['/dashboard.php', '/dashboard'], requireLogin, (req: Request, res: Response) => {
  const devStats = getDeviceStats();
  const waterDevs = new Set(
    store.devices
      .filter((d) => d.device_type === 'water_sensor' && d.is_active === 1)
      .map((d) => d.id)
  );
  const water =
    store.waterMeasurements
      .filter((m) => waterDevs.has(m.device_id))
      .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)[0] ?? null;

  const recentAlerts = store.alerts
    .filter((a) => a.parameter !== 'salinity_psu' && a.parameter !== 'turbidity_ntu')
    .sort((a, b) => {
      const ao = a.status === 'open' ? 1 : 0;
      const bo = b.status === 'open' ? 1 : 0;
      if (ao !== bo) return bo - ao;
      return b.occurred_at.localeCompare(a.occurred_at) || b.id - a.id;
    })
    .slice(0, 6)
    .map((a) => {
      const dev = a.device_id ? store.devices.find((d) => d.id === a.device_id) : null;
      return { ...a, device_name: dev?.device_name ?? null };
    });

  const lastUpdate =
    store.waterMeasurements.reduce<string | null>(
      (max, m) => (!max || m.measured_at > max ? m.measured_at : max),
      null
    ) ?? null;

  const waterStatus = dataStatus(water?.measured_at ?? null);
  const wEval = {
    temp_sensor_c: water ? evaluateValue('temp_sensor_c', water.temp_sensor_c) : null,
    ph: water ? evaluateValue('ph', water.ph) : null,
  };

  const bootPayload = {
    wave: skjaBootWave(1440),
    power: skjaBootEnergy(1440),
    water: {
      ph: skjaBootWater('ph', 1440),
      temp_sensor_c: skjaBootWater('temp_sensor_c', 1440),
    },
    geo: skjaBootGeo(),
  };

  res.render('dashboard', {
    ...buildViewLocals(req, {
      pageId: 'dashboard',
      pageTitle: 'Dashboard',
      breadcrumb: [['Dashboard', null]],
      pageScripts: ['dashboard.js'],
      needLeaflet: true,
    }),
    devTotal: devStats.total,
    devOnline: devStats.online,
    water,
    recentAlerts,
    lastUpdate,
    waterStatus,
    wEval,
    bootPayload,
  });
});

/* ==================== 4. KUALITAS AIR ==================== */
pagesRouter.get(
  ['/kualitas-air.php', '/kualitas-air'],
  requireLogin,
  (req: Request, res: Response) => {
    const validMinutes: Record<string, number> = {
      '5': 5,
      '60': 60,
      '360': 360,
      '1440': 1440,
      '4320': 4320,
      '10080': 10080,
    };
    const rangeRaw = typeof req.query.range === 'string' ? req.query.range : '';
    const range = validMinutes[rangeRaw] ?? 5;
    let param = typeof req.query.param === 'string' ? req.query.param : 'temp_sensor_c';
    if (!['temp_sensor_c', 'ph', 'volt_ph_v'].includes(param)) param = 'temp_sensor_c';

    const device =
      store.devices
        .filter((d) => d.device_type === 'water_sensor' && d.is_active === 1)
        .sort((a, b) => a.id - b.id)[0] ?? null;

    const latest = device
      ? store.waterMeasurements
          .filter((m) => m.device_id === device.id)
          .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)[0] ?? null
      : null;

    const defFrom = formatYmd(Date.now() - 86400 * 1000);
    const defTo = formatYmd();
    const [hFrom, hTo] = dateRangeFromQuery(req.query as Record<string, unknown>, defFrom, defTo);

    if (req.query.export === 'csv') {
      const start = `${hFrom} 00:00:00`;
      const end = `${hTo} 23:59:59`;
      const code = device?.device_code ?? '';
      const exportRows = device
        ? store.waterMeasurements
            .filter(
              (m) => m.device_id === device.id && m.measured_at >= start && m.measured_at <= end
            )
            .sort((a, b) => b.measured_at.localeCompare(a.measured_at))
            .map((r) => [
              code,
              r.measured_at,
              r.temp_sensor_c,
              r.ph,
              r.volt_ph_v,
              r.is_simulated ? 'DEMO' : 'RIIL',
            ])
        : [];
      const csv = buildCsv(
        ['Kode Perangkat', 'Waktu', 'Suhu Air (°C)', 'pH', 'Tegangan Modul pH (V)', 'Sumber'],
        exportRows
      );
      res.setHeader('Content-Type', 'text/csv; charset=utf-8');
      res.setHeader(
        'Content-Disposition',
        `attachment; filename="data-kualitas-air-${hFrom}_${hTo}.csv"`
      );
      res.send(csv);
      return;
    }

    const start = `${hFrom} 00:00:00`;
    const end = `${hTo} 23:59:59`;
    const allFiltered = device
      ? store.waterMeasurements
          .filter(
            (m) => m.device_id === device.id && m.measured_at >= start && m.measured_at <= end
          )
          .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)
      : [];

    const total = allFiltered.length;
    const per = 15;
    const page = Math.max(1, Number.parseInt(String(req.query.page ?? '1'), 10) || 1);
    const pages = Math.max(1, Math.ceil(total / per));
    const rows = allFiltered.slice((page - 1) * per, page * per);

    const PARAMS = {
      temp_sensor_c: {
        label: 'Suhu Air',
        unit: '°C',
        icon: 'fa-temperature-half',
        dec: 1,
        source: 'Sensor suhu in-situ',
      },
      ph: {
        label: 'pH',
        unit: '',
        icon: 'fa-vial',
        dec: 2,
        source: 'Sensor pH in-situ',
      },
      volt_ph_v: {
        label: 'Tegangan Modul pH',
        unit: 'V',
        icon: 'fa-bolt',
        dec: 3,
        source: 'Output analog modul pH (bukan dinamo)',
      },
    } as const;

    const evals: Record<string, ReturnType<typeof evaluateValue> | null> = {
      temp_sensor_c:
        latest && latest.temp_sensor_c !== null
          ? evaluateValue('temp_sensor_c', latest.temp_sensor_c)
          : null,
      ph: latest && latest.ph !== null ? evaluateValue('ph', latest.ph) : null,
      volt_ph_v: null,
    };

    const waterAlerts = store.alerts
      .filter((a) => a.parameter === 'temp_sensor_c' || a.parameter === 'ph')
      .sort((a, b) => b.occurred_at.localeCompare(a.occurred_at) || b.id - a.id)
      .slice(0, 8)
      .map((a) => {
        const d = a.device_id ? store.devices.find((x) => x.id === a.device_id) : null;
        return { ...a, device_name: d?.device_name ?? null };
      });

    res.render('kualitas-air', {
      ...buildViewLocals(req, {
        pageId: 'kualitas-air',
        pageTitle: 'Monitoring Kualitas Air',
        breadcrumb: [['Dashboard', 'dashboard.php'], ['Kualitas Air', null]],
        pageScripts: ['water-chart.js'],
      }),
      range,
      param,
      PARAMS,
      device,
      latest,
      status: dataStatus(latest?.measured_at ?? null),
      evals,
      hFrom,
      hTo,
      page,
      pages,
      total,
      rows,
      waterAlerts,
      bootPayload: {
        param,
        points: skjaBootWater(param as 'temp_sensor_c' | 'ph' | 'volt_ph_v', range),
      },
    });
  }
);

/* ==================== 5. GELOMBANG ==================== */
pagesRouter.get(['/gelombang.php', '/gelombang'], requireLogin, (req: Request, res: Response) => {
  const validMinutes: Record<string, number> = { '15': 15, '60': 60, '360': 360, '1440': 1440 };
  const rawRange = typeof req.query.range === 'string' ? req.query.range : '';
  const parsedRange = rawRange === '0' ? 0 : validMinutes[rawRange] ?? 60;
  const customFrom = typeof req.query.from === 'string' ? req.query.from : '';
  const customTo = typeof req.query.to === 'string' ? req.query.to : '';
  const isCustom = parsedRange === 0 || (customFrom !== '' && customTo !== '');
  const range = parsedRange || 60;

  const waveDevs = new Set(
    store.devices
      .filter((d) => d.device_type === 'wave_sensor' && d.is_active === 1)
      .map((d) => d.id)
  );
  const waveRaw =
    store.waveMeasurements
      .filter((m) => waveDevs.has(m.device_id))
      .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)[0] ?? null;
  const waveDev = waveRaw ? store.devices.find((d) => d.id === waveRaw.device_id) : null;
  const wave = waveRaw
    ? { ...waveRaw, device_name: waveDev?.device_name ?? '', device_code: waveDev?.device_code ?? '' }
    : null;

  const defFrom = formatYmd(Date.now() - 86400 * 1000);
  const defTo = formatYmd();
  const [hFrom, hTo] = dateRangeFromQuery(req.query as Record<string, unknown>, defFrom, defTo);

  if (req.query.export === 'csv') {
    const start = `${hFrom} 00:00:00`;
    const end = `${hTo} 23:59:59`;
    const exportRows = wave
      ? store.waveMeasurements
          .filter((m) => m.device_id === wave.device_id && m.measured_at >= start && m.measured_at <= end)
          .sort((a, b) => b.measured_at.localeCompare(a.measured_at))
          .map((r) => [
            wave.device_code,
            r.measured_at,
            r.wave_height_m,
            r.wave_period_s,
            r.wave_direction_deg,
            r.wind_speed_ms,
            r.wind_direction_deg,
            r.is_simulated ? 'DEMO' : 'RIIL',
          ])
      : [];
    const csv = buildCsv(
      [
        'Kode Perangkat',
        'Waktu',
        'Tinggi Gelombang (m)',
        'Periode (s)',
        'Arah Gelombang (deg)',
        'Kecepatan Angin (m/s)',
        'Arah Angin (deg)',
        'Sumber',
      ],
      exportRows
    );
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    res.setHeader(
      'Content-Disposition',
      `attachment; filename="data-gelombang-${hFrom}_${hTo}.csv"`
    );
    res.send(csv);
    return;
  }

  const fromSql = isCustom
    ? /^\d{4}-\d{2}-\d{2}$/.test(customFrom)
      ? `${customFrom} 00:00:00`
      : `${defFrom} 00:00:00`
    : formatDbDate(Date.now() - range * 60 * 1000);
  const toSql =
    isCustom && /^\d{4}-\d{2}-\d{2}$/.test(customTo) ? `${customTo} 23:59:59` : formatDbDate();

  const statRows = wave
    ? store.waveMeasurements.filter(
        (m) => m.device_id === wave.device_id && m.measured_at >= fromSql && m.measured_at <= toSql
      )
    : [];
  const heights = statRows.map((r) => Number(r.wave_height_m));
  const stats =
    heights.length > 0
      ? {
          hmin: Math.min(...heights),
          hmax: Math.max(...heights),
          havg: heights.reduce((s, v) => s + v, 0) / heights.length,
          cnt: heights.length,
        }
      : { hmin: null, hmax: null, havg: null, cnt: 0 };

  const start = `${hFrom} 00:00:00`;
  const end = `${hTo} 23:59:59`;
  const allFiltered = wave
    ? store.waveMeasurements
        .filter((m) => m.device_id === wave.device_id && m.measured_at >= start && m.measured_at <= end)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)
    : [];

  const total = allFiltered.length;
  const per = 15;
  const page = Math.max(1, Number.parseInt(String(req.query.page ?? '1'), 10) || 1);
  const pages = Math.max(1, Math.ceil(total / per));
  const rows = allFiltered.slice((page - 1) * per, page * per);

  res.render('gelombang', {
    ...buildViewLocals(req, {
      pageId: 'gelombang',
      pageTitle: 'Monitoring Gelombang',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Monitoring Gelombang', null]],
      pageScripts: ['wave-chart.js'],
    }),
    range,
    customFrom,
    customTo,
    defFrom,
    defTo,
    hFrom,
    hTo,
    wave,
    status: dataStatus(wave?.measured_at ?? null),
    stats,
    total,
    page,
    pages,
    rows,
    bootPayload: isCustom ? skjaBootWave(0, customFrom, customTo) : skjaBootWave(range),
  });
});

/* ==================== 6. ENERGI OWC ==================== */
pagesRouter.get(['/energi-owc.php', '/energi-owc'], requireLogin, (req: Request, res: Response) => {
  const device =
    store.devices
      .filter((d) => d.device_type === 'owc' && d.is_active === 1)
      .sort((a, b) => a.id - b.id)[0] ?? null;

  const latest = device
    ? store.owcMeasurements
        .filter((m) => m.device_id === device.id)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)[0] ?? null
    : null;

  const dailyAsc = skjaBootEnergyDaily(7);
  const daily = dailyAsc.slice().sort((a, b) => b.d.localeCompare(a.d)).slice(0, 7);

  const status = dataStatus(latest?.measured_at ?? null);
  const lowTh = settingFloat('low_power_threshold_w', 150);
  let prediksi: { label: string; cls: string; desc: string; note?: string } = {
    label: 'Data belum cukup',
    cls: 'badge-gray',
    desc: 'Belum ada data daya untuk dianalisis.',
  };

  if (device && latest) {
    const cutoff3h = Date.now() - 3 * 3600 * 1000;
    const recent3h = store.owcMeasurements.filter((m) => {
      if (m.device_id !== device.id) return false;
      const ms = parseDbDateMs(m.measured_at);
      return ms !== null && ms >= cutoff3h;
    });
    if (recent3h.length > 0) {
      const avgPower3h =
        recent3h.reduce((s, r) => s + Number(r.power_w), 0) / recent3h.length;
      if (avgPower3h >= lowTh * 2) {
        prediksi = {
          label: 'Ketersediaan Daya Baik',
          cls: 'badge-green',
          desc: `Rata-rata daya 3 jam terakhir ${fmtNum(avgPower3h, 0)} W berada jauh di atas ambang rendah (${fmtNum(lowTh, 0)} W).`,
          note: 'Indikasi berbasis aturan sederhana (rata-rata bergerak), bukan model AI.',
        };
      } else if (avgPower3h >= lowTh) {
        prediksi = {
          label: 'Daya Moderat — Pantau Tren',
          cls: 'badge-yellow',
          desc: `Rata-rata daya 3 jam terakhir ${fmtNum(avgPower3h, 0)} W mendekati ambang rendah. Perhatikan tren gelombang pada beberapa jam ke depan.`,
          note: 'Indikasi berbasis aturan sederhana (rata-rata bergerak), bukan model AI.',
        };
      } else {
        prediksi = {
          label: 'Risiko Daya Rendah Tinggi',
          cls: 'badge-red',
          desc: `Rata-rata daya 3 jam terakhir ${fmtNum(avgPower3h, 0)} W di bawah ambang ${fmtNum(lowTh, 0)} W. Siapkan cadangan energi bila ada beban kritis.`,
          note: 'Indikasi berbasis aturan sederhana (rata-rata bergerak), bukan model AI.',
        };
      }
    }
  }

  const totalEnergy = latest ? Number(latest.energy_wh) : null;

  res.render('energi-owc', {
    ...buildViewLocals(req, {
      pageId: 'energi-owc',
      pageTitle: 'Monitoring Energi OWC',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Energi OWC', null]],
      pageScripts: ['energy-chart.js'],
    }),
    device,
    latest,
    daily,
    status,
    prediksi,
    totalEnergy,
    bootPayload: {
      points: skjaBootEnergy(1440),
      daily: { rows: dailyAsc },
    },
  });
});

/* ==================== 7. FISHCOUNT AI ==================== */
pagesRouter.get(['/fishcount.php', '/fishcount'], requireLogin, (req: Request, res: Response) => {
  const camDev =
    store.devices
      .filter((d) => d.device_type === 'camera' && d.is_active === 1)
      .sort((a, b) => a.id - b.id)[0] ?? null;
  const camLoc = camDev
    ? store.deviceLocations.find((l) => l.device_id === camDev.id) ?? null
    : null;

  const camera = camDev
    ? {
        id: camDev.id,
        device_code: camDev.device_code,
        device_name: camDev.device_name,
        status: camDev.status,
        latitude: camLoc ? camLoc.latitude : null,
        longitude: camLoc ? camLoc.longitude : null,
        location_name: camLoc ? camLoc.location_name : null,
        notes: camLoc ? camLoc.notes : null,
      }
    : null;

  const cameraObj = {
    id: camera ? camera.id : null,
    code: camera?.device_code ?? 'CAM-001',
    name: camera?.device_name ?? 'ESP32-CAM OV2640',
    lat: camera?.latitude !== null && camera?.latitude !== undefined ? Number(camera.latitude) : -5.7703,
    lon: camera?.longitude !== null && camera?.longitude !== undefined ? Number(camera.longitude) : 105.2642,
    location_name: camera?.location_name ?? 'Titik pemasangan kamera',
  };

  res.render('fishcount', {
    ...buildViewLocals(req, {
      pageId: 'fishcount',
      pageTitle: 'FishCount AI — Live Monitoring',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['FishCount AI', null]],
      pageScripts: ['fishcount-live.js'],
      needLeaflet: true,
    }),
    camera,
    cameraObj,
  });
});

/* ==================== 8. FISHCOUNT SPECIES ==================== */
const SPECIES_LABELS: Record<string, string> = {
  bandeng: 'Bandeng',
  kerapu_macan: 'Kerapu Macan',
  kakap_putih: 'Kakap Putih',
  bawal_bintang: 'Bawal Bintang',
};

pagesRouter.get(
  ['/fishcount-species.php', '/fishcount-species'],
  requireLogin,
  (req: Request, res: Response) => {
    res.render('fishcount-species', {
      ...buildViewLocals(req, {
        pageId: 'fishcount',
        pageTitle: 'Klasifikasi Spesies — EfficientNetV2',
        breadcrumb: [
          ['Dashboard', 'dashboard.php'],
          ['FishCount AI', 'fishcount.php'],
          ['Klasifikasi Spesies', null],
        ],
        pageScripts: [],
      }),
      result: null,
      error: null,
      preview: null,
      labels: SPECIES_LABELS,
    });
  }
);

pagesRouter.post(
  ['/fishcount-species.php', '/fishcount-species'],
  requireLogin,
  (req: Request, res: Response) => {
    uploadSpecies.single('fish_image')(req, res, async (uploadErr) => {
      let result: Record<string, unknown> | null = null;
      let error: string | null = null;
      let preview: string | null = null;

      if (uploadErr) {
        error = 'Pilih JPG/PNG/WEBP maksimal 5 MB.';
      } else {
        if (!csrfCheck(req, res)) return;
        const file = req.file;
        if (!file || file.size === 0 || file.size > UPLOAD_MAX_BYTES) {
          error = 'Pilih JPG/PNG/WEBP maksimal 5 MB.';
        } else {
          const mime = detectImageMime(file.buffer);
          if (!['image/jpeg', 'image/png', 'image/webp'].includes(mime)) {
            error = 'Format bukan JPG/PNG/WEBP yang valid.';
          } else {
            const b64 = file.buffer.toString('base64');
            preview = `data:${mime};base64,${b64}`;
            const apiUrl =
              process.env.FISHCOUNT_SPECIES_API_URL || 'http://127.0.0.1:8000/classify';
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 90000);
            try {
              const resp = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  Accept: 'application/json',
                },
                body: JSON.stringify({ image_base64: b64, mime }),
                signal: controller.signal,
              });
              const json = (await resp.json().catch(() => null)) as Record<
                string,
                unknown
              > | null;
              if (json && json.ok && typeof json.species === 'string') {
                result = json;
              } else {
                const detail = json
                  ? (json.detail ?? json.error ?? 'Respons AI belum tersedia')
                  : 'Python API tidak dapat dihubungi';
                error = typeof detail === 'string' ? detail : 'Respons server AI tidak valid';
              }
            } catch {
              error = 'Python API tidak dapat dihubungi';
            } finally {
              clearTimeout(timer);
            }
          }
        }
      }

      res.render('fishcount-species', {
        ...buildViewLocals(req, {
          pageId: 'fishcount',
          pageTitle: 'Klasifikasi Spesies — EfficientNetV2',
          breadcrumb: [
            ['Dashboard', 'dashboard.php'],
            ['FishCount AI', 'fishcount.php'],
            ['Klasifikasi Spesies', null],
          ],
          pageScripts: [],
        }),
        result,
        error,
        preview,
        labels: SPECIES_LABELS,
      });
    });
  }
);

/* ==================== 9. WEBGIS ==================== */
pagesRouter.get(['/webgis.php', '/webgis'], requireLogin, (req: Request, res: Response) => {
  const deviceList = store.devices
    .filter((d) => d.is_active === 1)
    .sort((a, b) => a.device_name.localeCompare(b.device_name))
    .map((d) => {
      const loc = store.deviceLocations.find((l) => l.device_id === d.id) ?? null;
      return {
        id: d.id,
        device_code: d.device_code,
        device_name: d.device_name,
        device_type: d.device_type,
        status: d.status,
        latitude: loc ? loc.latitude : null,
        longitude: loc ? loc.longitude : null,
        location_name: loc ? loc.location_name : null,
        installed_at: loc ? loc.installed_at : null,
        notes: loc ? loc.notes : null,
      };
    });

  res.render('webgis', {
    ...buildViewLocals(req, {
      pageId: 'webgis',
      pageTitle: 'WebGIS',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['WebGIS', null]],
      pageScripts: ['map.js'],
      needLeaflet: true,
    }),
    deviceList,
    bootPayload: { geo: skjaBootGeo() },
  });
});

pagesRouter.post(['/webgis.php', '/webgis'], requireLogin, (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;
  if (req.body.action === 'save_location') {
    if (!isAdmin(req)) {
      flashSet(req, 'error', 'Hanya administrator yang dapat mengubah lokasi perangkat.');
      res.redirect('/webgis.php');
      return;
    }
    const deviceId = Number.parseInt(String(req.body.device_id ?? '0'), 10) || 0;
    const lat = Number(req.body.latitude);
    const lng = Number(req.body.longitude);
    const locName = typeof req.body.location_name === 'string' ? req.body.location_name.trim() : '';
    const installed =
      typeof req.body.installed_at === 'string' && req.body.installed_at !== ''
        ? req.body.installed_at
        : null;
    const notes =
      typeof req.body.notes === 'string' && req.body.notes.trim() !== ''
        ? req.body.notes.trim()
        : null;

    const latOk =
      req.body.latitude !== '' && req.body.latitude !== undefined && Number.isFinite(lat) && lat >= -90 && lat <= 90;
    const lngOk =
      req.body.longitude !== '' && req.body.longitude !== undefined && Number.isFinite(lng) && lng >= -180 && lng <= 180;

    if (deviceId <= 0 || !latOk || !lngOk) {
      flashSet(
        req,
        'error',
        'Data lokasi tidak valid (periksa perangkat, latitude -90..90, longitude -180..180).'
      );
    } else {
      const existing = store.deviceLocations.find((l) => l.device_id === deviceId);
      const now = formatDbDate();
      if (existing) {
        existing.latitude = lat;
        existing.longitude = lng;
        existing.location_name = locName || existing.location_name;
        existing.installed_at = installed;
        existing.notes = notes;
        existing.updated_at = now;
      } else {
        store.deviceLocations.push({
          id: nextId(store.deviceLocations),
          device_id: deviceId,
          latitude: lat,
          longitude: lng,
          location_name: locName || null,
          installed_at: installed,
          notes,
          updated_at: now,
        });
      }
      flashSet(req, 'success', 'Lokasi perangkat berhasil disimpan.');
    }
  }
  res.redirect('/webgis.php');
});

/* ==================== 10. BATIMETRI ==================== */
pagesRouter.get(['/batimetri.php', '/batimetri'], requireLogin, (req: Request, res: Response) => {
  res.render('batimetri', {
    ...buildViewLocals(req, {
      pageId: 'batimetri',
      pageTitle: 'Peta Batimetri',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Peta Batimetri', null]],
      pageScripts: ['batimetri-bmkg-v3.js', 'betri-layer.js', 'bmkg-wind.js'],
      needLeaflet: true,
    }),
  });
});

/* ==================== 11. LOKASI KJA ==================== */
pagesRouter.get(['/lokasi-kja.php', '/lokasi-kja'], requireLogin, (req: Request, res: Response) => {
  const sites = store.kjaSites
    .filter((s) => s.is_active === 1)
    .sort((a, b) => b.suitability_score - a.suitability_score || a.site_name.localeCompare(b.site_name));

  const CLASS_META: Record<string, { label: string; color: string }> = {
    S1: { label: 'Sangat cocok (S1)', color: '#2FA36B' },
    S2: { label: 'Cukup cocok (S2)', color: '#E8A13D' },
    S3: { label: 'Kurang cocok (S3)', color: '#E06A4C' },
    N: { label: 'Tidak direkomendasikan (N)', color: '#8D95A1' },
  };
  const SHELTER_LABEL: Record<string, string> = {
    teluk: 'Teluk tertutup',
    semi: 'Semi terlindung',
    terbuka: 'Perairan terbuka',
  };

  const sitesPayload = sites.map((s) => ({
    id: s.id,
    slug: s.slug,
    name: s.site_name,
    kab: s.kabupaten,
    kec: s.kecamatan,
    lat: Number(s.latitude),
    lon: Number(s.longitude),
    depth: s.depth_m !== null ? Number(s.depth_m) : null,
    src: s.depth_source,
    shelter: s.shelter,
    species: s.recommended_species,
    score: s.suitability_score,
    cls: s.suitability_class,
    sd: s.score_depth,
    ss: s.score_shelter,
    sa: s.score_access,
    sc: s.score_conflict,
    constraint: s.constraints_note,
    notes: s.notes,
  }));

  res.render('lokasi-kja', {
    ...buildViewLocals(req, {
      pageId: 'lokasi-kja',
      pageTitle: 'Lokasi KJA',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Lokasi KJA', null]],
      pageScripts: ['lokasi-kja.js'],
      needLeaflet: true,
    }),
    sites,
    CLASS_META,
    SHELTER_LABEL,
    sitesPayload,
  });
});

/* ==================== 12. PENJELASAN KJA ==================== */
pagesRouter.get(
  ['/penjelasan-kja.php', '/penjelasan-kja'],
  requireLogin,
  (req: Request, res: Response) => {
    const speciesList = store.fishSpecies
      .filter((s) => s.is_active === 1)
      .sort((a, b) => a.name.localeCompare(b.name))
      .slice(0, 8);
    const SP_CAT: Record<string, string> = {
      laut: 'laut',
      tawar: 'tawar/waduk',
      brackish: 'payau/muara',
    };
    const SP_ICON_COLOR = ['#3090A8', '#178456', '#0276A0', '#E5484D', '#16394D', '#22A06B'];

    res.render('penjelasan-kja', {
      ...buildViewLocals(req, {
        pageId: 'penjelasan-kja',
        pageTitle: 'Penjelasan KJA',
        breadcrumb: [['Dashboard', 'dashboard.php'], ['Penjelasan KJA', null]],
        pageScripts: [],
        needChart: false,
      }),
      speciesList,
      SP_CAT,
      SP_ICON_COLOR,
    });
  }
);

/* ==================== 13. PREDIKSI AI (Z-SCORE) ==================== */
pagesRouter.get(['/prediksi-ai.php', '/prediksi-ai'], requireLogin, (req: Request, res: Response) => {
  const anomalyParams: Array<'temp_sensor_c' | 'ph'> = ['temp_sensor_c', 'ph'];
  const cutoff24h = Date.now() - 24 * 3600 * 1000;
  const anomalies: Array<{
    time: string;
    value: number;
    z: number;
    param: string;
    mean: number;
    sd: number;
  }> = [];
  const anomalyChart: Record<string, Array<{ time: string; value: number; z: number }>> = {};
  const sampleCounts: Record<string, number> = {};

  for (const p of anomalyParams) {
    const pts = store.waterMeasurements
      .filter((m) => {
        if (m[p] === null || m[p] === undefined) return false;
        const ms = parseDbDateMs(m.measured_at);
        return ms !== null && ms >= cutoff24h;
      })
      .sort((a, b) => a.measured_at.localeCompare(b.measured_at) || a.id - b.id);

    const n = pts.length;
    sampleCounts[p] = n;
    anomalyChart[p] = [];
    if (n < 10) continue;

    const vals = pts.map((r) => Number(r[p]));
    const mean = vals.reduce((s, v) => s + v, 0) / n;
    const variance = vals.reduce((s, v) => s + (v - mean) ** 2, 0) / n;
    const sd = Math.sqrt(variance);
    if (sd <= 0) continue;

    for (const r of pts) {
      const val = Number(r[p]);
      const z = (val - mean) / sd;
      const point = {
        time: r.measured_at,
        value: val,
        z: Number(z.toFixed(2)),
      };
      anomalyChart[p].push(point);
      if (Math.abs(z) > 2.5) {
        anomalies.push({
          ...point,
          param: p,
          mean: Number(mean.toFixed(2)),
          sd: Number(sd.toFixed(2)),
        });
      }
    }
  }

  anomalies.sort((a, b) => b.time.localeCompare(a.time));
  const topAnomalies = anomalies.slice(0, 6);
  const hasChart = Object.values(anomalyChart).some((pts) => pts.length > 0);

  res.render('prediksi-ai', {
    ...buildViewLocals(req, {
      pageId: 'prediksi-ai',
      pageTitle: 'Analitik Kualitas Air',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Analitik Kualitas Air', null]],
      pageScripts: [],
      needChart: true,
    }),
    sampleCounts,
    anomalies: topAnomalies,
    anomalyChart,
    hasChart,
  });
});

/* ==================== 14. PERINGATAN ==================== */
pagesRouter.get(['/peringatan.php', '/peringatan'], requireLogin, (req: Request, res: Response) => {
  const severity = typeof req.query.severity === 'string' ? req.query.severity : '';
  const status = typeof req.query.status === 'string' ? req.query.status : '';
  const param = typeof req.query.param === 'string' ? req.query.param : '';
  const defFrom = formatYmd(Date.now() - 7 * 86400 * 1000);
  const defTo = formatYmd();
  const [from, to] = dateRangeFromQuery(req.query as Record<string, unknown>, defFrom, defTo);

  const start = `${from} 00:00:00`;
  const end = `${to} 23:59:59`;

  const filtered = store.alerts
    .filter((a) => {
      if (a.occurred_at < start || a.occurred_at > end) return false;
      if (['info', 'warning', 'critical'].includes(severity) && a.severity !== severity) return false;
      if (['open', 'resolved'].includes(status) && a.status !== status) return false;
      if (param && /^[a-z0-9_]{1,40}$/.test(param) && a.parameter !== param) return false;
      return true;
    })
    .sort((a, b) => {
      const ao = a.status === 'open' ? 1 : 0;
      const bo = b.status === 'open' ? 1 : 0;
      if (ao !== bo) return bo - ao;
      return b.occurred_at.localeCompare(a.occurred_at) || b.id - a.id;
    });

  let detail = null;
  if (req.query.id !== undefined) {
    const did = Number.parseInt(String(req.query.id), 10);
    const hit = store.alerts.find((a) => a.id === did);
    if (hit) {
      const dev = hit.device_id ? store.devices.find((d) => d.id === hit.device_id) : null;
      const resolver = hit.resolved_by ? store.users.find((u) => u.id === hit.resolved_by) : null;
      detail = {
        ...hit,
        device_name: dev?.device_name ?? null,
        device_code: dev?.device_code ?? null,
        resolver: resolver?.username ?? null,
      };
    }
  }

  const total = filtered.length;
  const per = 12;
  const page = Math.max(1, Number.parseInt(String(req.query.page ?? '1'), 10) || 1);
  const pages = Math.max(1, Math.ceil(total / per));
  const rows = filtered.slice((page - 1) * per, page * per).map((a) => {
    const dev = a.device_id ? store.devices.find((d) => d.id === a.device_id) : null;
    return {
      ...a,
      device_name: dev?.device_name ?? null,
      device_code: dev?.device_code ?? null,
    };
  });

  const sum = {
    crit: store.alerts.filter((a) => a.severity === 'critical' && a.status === 'open').length,
    warn: store.alerts.filter((a) => a.severity === 'warning' && a.status === 'open').length,
    open: store.alerts.filter((a) => a.status === 'open').length,
  };

  res.render('peringatan', {
    ...buildViewLocals(req, {
      pageId: 'peringatan',
      pageTitle: 'Peringatan',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Peringatan', null]],
      pageScripts: [],
    }),
    severity,
    status,
    param,
    from,
    to,
    detail,
    total,
    page,
    pages,
    rows,
    sum,
  });
});

pagesRouter.post(['/peringatan.php', '/peringatan'], requireLogin, (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;
  const action = typeof req.body.action === 'string' ? req.body.action : '';
  const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;

  if (!['resolve', 'reopen'].includes(action) || id <= 0) {
    flashSet(req, 'error', 'Aksi tidak valid.');
  } else if (!canManage(req)) {
    flashSet(req, 'error', 'Hanya admin/operator yang dapat mengubah status peringatan.');
  } else {
    const cu = currentUser(req)!;
    const al = store.alerts.find((a) => a.id === id);
    if (action === 'resolve') {
      if (al && al.status === 'open') {
        al.status = 'resolved';
        al.resolved_by = cu.id;
        al.resolved_at = formatDbDate();
        flashSet(req, 'success', `Peringatan #${id} ditandai sebagai ditangani.`);
      } else {
        flashSet(req, 'error', 'Peringatan tidak ditemukan atau sudah ditangani.');
      }
    } else {
      if (al && al.status === 'resolved') {
        al.status = 'open';
        al.resolved_by = null;
        al.resolved_at = null;
        flashSet(req, 'success', `Peringatan #${id} dibuka kembali.`);
      } else {
        flashSet(req, 'error', 'Peringatan tidak ditemukan atau masih terbuka.');
      }
    }
  }
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(req.query)) {
    if (k !== 'id' && v !== undefined && v !== '') params.set(k, String(v));
  }
  const qs = params.toString();
  res.redirect(`/peringatan.php${qs ? `?${qs}` : ''}`);
});

/* ==================== 15. LAPORAN ==================== */
interface ReportSummaryRow {
  d: string;
  n: number;
  v1: number | null;
  v2: number | null;
  v3: number | null;
}

function summaryRows(type: string, from: string, to: string): ReportSummaryRow[] {
  const start = `${from} 00:00:00`;
  const end = `${to} 23:59:59`;

  if (type === 'wave') {
    const groups = new Map<string, number[]>();
    for (const r of store.waveMeasurements) {
      if (r.measured_at < start || r.measured_at > end) continue;
      const d = r.measured_at.slice(0, 10);
      if (!groups.has(d)) groups.set(d, []);
      groups.get(d)!.push(Number(r.wave_height_m));
    }
    return Array.from(groups.entries())
      .sort((a, b) => b[0].localeCompare(a[0]))
      .map(([d, arr]) => ({
        d,
        n: arr.length,
        v1: Math.min(...arr),
        v2: Math.max(...arr),
        v3: arr.reduce((s, v) => s + v, 0) / arr.length,
      }));
  }

  if (type === 'energy') {
    const groups = new Map<string, Array<{ e: number; p: number }>>();
    for (const r of store.owcMeasurements) {
      if (r.measured_at < start || r.measured_at > end) continue;
      const d = r.measured_at.slice(0, 10);
      if (!groups.has(d)) groups.set(d, []);
      groups.get(d)!.push({ e: Number(r.energy_wh), p: Number(r.power_w) });
    }
    return Array.from(groups.entries())
      .sort((a, b) => b[0].localeCompare(a[0]))
      .map(([d, arr]) => {
        const es = arr.map((x) => x.e);
        const ps = arr.map((x) => x.p);
        return {
          d,
          n: arr.length,
          v1: Number((Math.max(...es) - Math.min(...es)).toFixed(1)),
          v2: Math.max(...ps),
          v3: ps.reduce((s, v) => s + v, 0) / ps.length,
        };
      });
  }

  if (type === 'water') {
    const groups = new Map<string, Array<{ ph: number | null; temp: number | null }>>();
    for (const r of store.waterMeasurements) {
      if (r.measured_at < start || r.measured_at > end) continue;
      const d = r.measured_at.slice(0, 10);
      if (!groups.has(d)) groups.set(d, []);
      groups.get(d)!.push({
        ph: r.ph !== null ? Number(r.ph) : null,
        temp: r.temp_sensor_c !== null ? Number(r.temp_sensor_c) : null,
      });
    }
    return Array.from(groups.entries())
      .sort((a, b) => b[0].localeCompare(a[0]))
      .map(([d, arr]) => {
        const phs = arr.map((x) => x.ph).filter((x): x is number => x !== null);
        const temps = arr.map((x) => x.temp).filter((x): x is number => x !== null);
        return {
          d,
          n: arr.length,
          v1: phs.length ? Math.min(...phs) : null,
          v2: phs.length ? Math.max(...phs) : null,
          v3: temps.length ? temps.reduce((s, v) => s + v, 0) / temps.length : null,
        };
      });
  }

  const groups = new Map<string, Array<{ det: number | null; len: number | null; bio: number | null }>>();
  for (const r of store.fishcountResults) {
    if (r.created_at < start || r.created_at > end) continue;
    const d = r.created_at.slice(0, 10);
    if (!groups.has(d)) groups.set(d, []);
    groups.get(d)!.push({
      det: r.detected_count !== null ? Number(r.detected_count) : null,
      len: r.avg_length_cm !== null ? Number(r.avg_length_cm) : null,
      bio: r.biomass_kg !== null ? Number(r.biomass_kg) : null,
    });
  }
  return Array.from(groups.entries())
    .sort((a, b) => b[0].localeCompare(a[0]))
    .map(([d, arr]) => {
      const dets = arr.map((x) => x.det).filter((x): x is number => x !== null);
      const lens = arr.map((x) => x.len).filter((x): x is number => x !== null);
      const bios = arr.map((x) => x.bio).filter((x): x is number => x !== null);
      return {
        d,
        n: arr.length,
        v1: dets.length ? Math.max(...dets) : null,
        v2: lens.length ? lens.reduce((s, v) => s + v, 0) / lens.length : null,
        v3: bios.length ? bios.reduce((s, v) => s + v, 0) : null,
      };
    });
}

pagesRouter.get(['/laporan.php', '/laporan'], requireLogin, (req: Request, res: Response) => {
  const types: Record<string, string> = {
    wave: 'Gelombang',
    energy: 'Energi OWC',
    water: 'Kualitas Air',
    fish: 'FishCount',
  };
  const rawType = typeof req.query.type === 'string' ? req.query.type : 'wave';
  const type = types[rawType] ? rawType : 'wave';

  const validPeriods: Record<string, string> = {
    today: 'Hari Ini',
    '7d': '7 Hari',
    '30d': '30 Hari',
    custom: 'Khusus',
  };
  const rawPeriod = typeof req.query.period === 'string' ? req.query.period : '7d';
  const period = validPeriods[rawPeriod] ? rawPeriod : '7d';

  const defFrom = formatYmd(Date.now() - 7 * 86400 * 1000);
  const defTo = formatYmd();
  let [from, to] = dateRangeFromQuery(req.query as Record<string, unknown>, defFrom, defTo);
  if (period === 'today') {
    from = to = formatYmd();
  }

  const devCodeMap = new Map(store.devices.map((d) => [d.id, d.device_code]));

  if (req.query.export === 'csv') {
    const start = `${from} 00:00:00`;
    const end = `${to} 23:59:59`;
    if (type === 'wave') {
      const out = store.waveMeasurements
        .filter((m) => m.measured_at >= start && m.measured_at <= end)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))
        .map((r) => [
          devCodeMap.get(r.device_id) ?? '',
          r.measured_at,
          r.wave_height_m,
          r.wave_period_s,
          r.wind_speed_ms,
          r.is_simulated ? 'DEMO' : 'RIIL',
        ]);
      res.setHeader('Content-Type', 'text/csv; charset=utf-8');
      res.setHeader(
        'Content-Disposition',
        `attachment; filename="laporan-gelombang-${from}_${to}.csv"`
      );
      res.send(
        buildCsv(['Perangkat', 'Waktu', 'Tinggi (m)', 'Periode (s)', 'Angin (m/s)', 'Sumber'], out)
      );
      return;
    }
    if (type === 'energy') {
      const out = store.owcMeasurements
        .filter((m) => m.measured_at >= start && m.measured_at <= end)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))
        .map((r) => [
          devCodeMap.get(r.device_id) ?? '',
          r.measured_at,
          r.power_w,
          r.voltage_v,
          r.current_a,
          r.energy_wh,
          r.is_simulated ? 'DEMO' : 'RIIL',
        ]);
      res.setHeader('Content-Type', 'text/csv; charset=utf-8');
      res.setHeader(
        'Content-Disposition',
        `attachment; filename="laporan-energi-owc-${from}_${to}.csv"`
      );
      res.send(
        buildCsv(
          ['Perangkat', 'Waktu', 'Daya (W)', 'Tegangan (V)', 'Arus (A)', 'Energi Kumulatif (Wh)', 'Sumber'],
          out
        )
      );
      return;
    }
    if (type === 'water') {
      const out = store.waterMeasurements
        .filter((m) => m.measured_at >= start && m.measured_at <= end)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))
        .map((r) => [
          devCodeMap.get(r.device_id) ?? '',
          r.measured_at,
          r.temp_sensor_c,
          r.ph,
          r.is_simulated ? 'DEMO' : 'RIIL',
        ]);
      res.setHeader('Content-Type', 'text/csv; charset=utf-8');
      res.setHeader(
        'Content-Disposition',
        `attachment; filename="laporan-kualitas-air-${from}_${to}.csv"`
      );
      res.send(buildCsv(['Perangkat', 'Waktu', 'Suhu (°C)', 'pH', 'Sumber'], out));
      return;
    }
    const out = store.fishcountResults
      .filter((r) => r.created_at >= start && r.created_at <= end)
      .sort((a, b) => b.created_at.localeCompare(a.created_at))
      .map((r) => [
        r.device_id ? (devCodeMap.get(r.device_id) ?? '') : '',
        r.created_at,
        r.detected_count,
        r.manual_count,
        r.avg_length_cm,
        r.biomass_kg,
        r.confidence_pct,
        r.processing_status,
        r.is_simulated ? 'DEMO' : 'RIIL',
      ]);
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    res.setHeader(
      'Content-Disposition',
      `attachment; filename="laporan-fishcount-${from}_${to}.csv"`
    );
    res.send(
      buildCsv(
        [
          'Perangkat',
          'Waktu',
          'Deteksi AI',
          'Hitung Manual',
          'Panjang Rata² (cm)',
          'Biomassa (kg)',
          'Keyakinan (%)',
          'Status',
          'Sumber',
        ],
        out
      )
    );
    return;
  }

  const summary = summaryRows(type, from, to);
  const todayYmd = formatYmd();
  const monthStartYmd = `${todayYmd.slice(0, 7)}-01`;
  const monthAgg = summaryRows(type, monthStartYmd, todayYmd);
  const monthN = monthAgg.reduce((s, r) => s + r.n, 0);
  const cutoff6d = formatYmd(Date.now() - 6 * 86400 * 1000);
  const weekN = summary.reduce((s, r) => (r.d >= cutoff6d ? s + r.n : s), 0);
  const todayCount = summary.find((r) => r.d === todayYmd)?.n ?? 0;
  const totalPeriodCount = summary.reduce((s, r) => s + r.n, 0);

  const limit = 20;
  let lastRows: Array<Record<string, unknown>> = [];
  if (type === 'wave') {
    lastRows = store.waveMeasurements
      .slice()
      .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)
      .slice(0, limit)
      .map((r) => ({ ...r, device_code: devCodeMap.get(r.device_id) ?? '—' }));
  } else if (type === 'energy') {
    lastRows = store.owcMeasurements
      .slice()
      .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)
      .slice(0, limit)
      .map((r) => ({ ...r, device_code: devCodeMap.get(r.device_id) ?? '—' }));
  } else if (type === 'water') {
    lastRows = store.waterMeasurements
      .slice()
      .sort((a, b) => b.measured_at.localeCompare(a.measured_at) || b.id - a.id)
      .slice(0, limit)
      .map((r) => ({ ...r, device_code: devCodeMap.get(r.device_id) ?? '—' }));
  } else {
    lastRows = store.fishcountResults
      .slice()
      .sort((a, b) => b.created_at.localeCompare(a.created_at) || b.id - a.id)
      .slice(0, limit)
      .map((r) => ({
        ...r,
        device_code: r.device_id ? (devCodeMap.get(r.device_id) ?? '—') : '—',
      }));
  }

  const chartData = summary
    .slice()
    .reverse()
    .map((r) => ({
      t: r.d,
      v:
        type === 'wave'
          ? Number((r.v3 ?? 0).toFixed(3))
          : type === 'energy'
            ? Number(((r.v1 ?? 0) / 1000).toFixed(3))
            : type === 'water'
              ? Number((r.v3 ?? 0).toFixed(1))
              : Math.round(r.v1 ?? 0),
    }));

  const nowWib = new Date(Date.now() + 7 * 3600 * 1000);
  const p2 = (n: number) => String(n).padStart(2, '0');
  const printedAt = `${p2(nowWib.getUTCDate())}/${p2(nowWib.getUTCMonth() + 1)}/${nowWib.getUTCFullYear()} ${p2(nowWib.getUTCHours())}:${p2(nowWib.getUTCMinutes())}`;

  res.render('laporan', {
    ...buildViewLocals(req, {
      pageId: 'laporan',
      pageTitle: 'Histori & Laporan',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Histori & Laporan', null]],
      pageScripts: ['laporan.js'],
    }),
    types,
    type,
    validPeriods,
    period,
    from,
    to,
    summary,
    monthN,
    weekN,
    todayCount,
    totalPeriodCount,
    lastRows,
    chartData,
    printedAt,
  });
});

/* ==================== 16. PERANGKAT ==================== */
pagesRouter.get(['/perangkat.php', '/perangkat'], requireLogin, (req: Request, res: Response) => {
  const devices = store.devices
    .slice()
    .sort((a, b) => b.is_active - a.is_active || a.device_code.localeCompare(b.device_code))
    .map((d) => {
      const loc = store.deviceLocations.find((l) => l.device_id === d.id) ?? null;
      return {
        ...d,
        latitude: loc ? loc.latitude : null,
        longitude: loc ? loc.longitude : null,
        location_name: loc ? loc.location_name : null,
      };
    });

  const staleMin = Math.max(1, settingInt('data_stale_minutes', 10));
  const calMap: Record<string, [string, string]> = {
    calibrated: ['badge-green', 'Terkalibrasi'],
    due: ['badge-yellow', 'Jatuh tempo'],
    overdue: ['badge-red', 'Terlambat'],
    none: ['badge-gray', '—'],
  };

  res.render('perangkat', {
    ...buildViewLocals(req, {
      pageId: 'perangkat',
      pageTitle: 'Manajemen Perangkat',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Manajemen Perangkat', null]],
      pageScripts: ['perangkat.js'],
    }),
    devices,
    staleMin,
    calMap,
    nowMs: Date.now(),
    parseDbDateMs,
    canEdit: canManage(req),
  });
});

pagesRouter.post(['/perangkat.php', '/perangkat'], requireLogin, (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;
  if (!canManage(req)) {
    flashSet(req, 'error', 'Hanya admin/operator yang dapat mengubah perangkat.');
    res.redirect('/perangkat.php');
    return;
  }

  const action = typeof req.body.action === 'string' ? req.body.action : '';

  if (action === 'add' || action === 'edit') {
    const code = typeof req.body.device_code === 'string' ? req.body.device_code.trim().toUpperCase() : '';
    const name = typeof req.body.device_name === 'string' ? req.body.device_name.trim() : '';
    const type = typeof req.body.device_type === 'string' ? req.body.device_type : '';
    const stt = typeof req.body.status === 'string' ? req.body.status : 'offline';
    const cal = (typeof req.body.calibration_status === 'string'
      ? req.body.calibration_status
      : 'none') as CalibrationStatus;
    const calDate =
      typeof req.body.calibration_date === 'string' && req.body.calibration_date !== ''
        ? req.body.calibration_date
        : null;
    const notes =
      typeof req.body.notes === 'string' && req.body.notes.trim() !== ''
        ? req.body.notes.trim()
        : null;
    const locName =
      typeof req.body.location_name === 'string' && req.body.location_name.trim() !== ''
        ? req.body.location_name.trim()
        : null;
    let lat = req.body.latitude !== '' && req.body.latitude !== undefined ? Number(req.body.latitude) : null;
    let lng = req.body.longitude !== '' && req.body.longitude !== undefined ? Number(req.body.longitude) : null;

    const validTypes = ['owc', 'wave_sensor', 'water_sensor', 'camera', 'gateway'];
    if (
      code === '' ||
      !/^[A-Z0-9_\-]{2,50}$/.test(code) ||
      name === '' ||
      !validTypes.includes(type) ||
      !['online', 'offline', 'maintenance'].includes(stt)
    ) {
      flashSet(req, 'error', 'Data perangkat tidak valid. Kode hanya huruf besar/angka/garis.');
      res.redirect('/perangkat.php');
      return;
    }

    if (lat !== null && (!Number.isFinite(lat) || lat < -90 || lat > 90)) lat = null;
    if (lng !== null && (!Number.isFinite(lng) || lng < -180 || lng > 180)) lng = null;

    if (action === 'add') {
      if (store.devices.some((d) => d.device_code === code)) {
        flashSet(req, 'error', 'Gagal menambah perangkat — kode mungkin sudah dipakai.');
      } else {
        const token = crypto.randomBytes(24).toString('hex');
        const newId = nextId(store.devices);
        const now = formatDbDate();
        store.devices.push({
          id: newId,
          device_code: code,
          device_name: name,
          device_type: type as DeviceType,
          status: stt as DeviceStatus,
          is_active: 1,
          api_token: crypto.createHash('sha256').update(token).digest('hex'),
          last_communication: null,
          calibration_status: cal,
          calibration_date: calDate,
          notes,
          created_at: now,
        });
        if (lat !== null && lng !== null) {
          store.deviceLocations.push({
            id: nextId(store.deviceLocations),
            device_id: newId,
            latitude: lat,
            longitude: lng,
            location_name: locName,
            installed_at: null,
            notes: null,
            updated_at: now,
          });
        }
        flashSet(
          req,
          'success',
          `Perangkat ${code} berhasil ditambahkan. TOKEN API (tampilkan sekarang, jangan hilang): ${token}`
        );
      }
    } else {
      const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;
      const dev = store.devices.find((d) => d.id === id);
      if (dev) {
        dev.device_name = name;
        dev.device_type = type as DeviceType;
        dev.status = stt as DeviceStatus;
        dev.calibration_status = cal;
        dev.calibration_date = calDate;
        dev.notes = notes;
        if (lat !== null && lng !== null) {
          const loc = store.deviceLocations.find((l) => l.device_id === id);
          const now = formatDbDate();
          if (loc) {
            loc.latitude = lat;
            loc.longitude = lng;
            loc.location_name = locName;
            loc.updated_at = now;
          } else {
            store.deviceLocations.push({
              id: nextId(store.deviceLocations),
              device_id: id,
              latitude: lat,
              longitude: lng,
              location_name: locName,
              installed_at: null,
              notes: null,
              updated_at: now,
            });
          }
        }
        flashSet(req, 'success', 'Perangkat berhasil diperbarui.');
      } else {
        flashSet(req, 'error', 'Perangkat tidak ditemukan.');
      }
    }
  } else if (action === 'toggle') {
    if (!isAdmin(req)) {
      flashSet(req, 'error', 'Hanya administrator yang dapat mengubah status aktivasi.');
    } else {
      const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;
      const dev = store.devices.find((d) => d.id === id);
      if (dev) dev.is_active = 1 - dev.is_active;
      flashSet(req, 'success', 'Status aktivasi perangkat diperbarui.');
    }
  } else if (action === 'reset_token') {
    if (!isAdmin(req)) {
      flashSet(req, 'error', 'Hanya administrator yang dapat mereset token API.');
    } else {
      const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;
      const dev = store.devices.find((d) => d.id === id);
      if (dev) {
        const token = crypto.randomBytes(24).toString('hex');
        dev.api_token = crypto.createHash('sha256').update(token).digest('hex');
        flashSet(req, 'success', `Token baru perangkat #${id} (tampilkan sekarang): ${token}`);
      } else {
        flashSet(req, 'error', 'Perangkat tidak ditemukan.');
      }
    }
  } else {
    flashSet(req, 'error', 'Aksi tidak dikenal.');
  }

  res.redirect('/perangkat.php');
});

/* ==================== 17. JENIS IKAN ==================== */
const SPECIES_CATEGORIES: Record<string, string> = {
  laut: 'Laut',
  tawar: 'Tawar',
  brackish: 'Rawa Payau',
};
const SPECIES_CAT_BADGE: Record<string, string> = {
  laut: 'badge-blue',
  tawar: 'badge-green',
  brackish: 'badge-yellow',
};

pagesRouter.get(['/jenis-ikan.php', '/jenis-ikan'], requireLogin, (req: Request, res: Response) => {
  const species = store.fishSpecies
    .slice()
    .sort((a, b) => b.is_active - a.is_active || a.name.localeCompare(b.name))
    .map((fsItem) => ({
      ...fsItem,
      threshold_count: store.thresholds.filter((t) => t.species === fsItem.name).length,
    }));
  const nActive = store.fishSpecies.filter((f) => f.is_active === 1).length;

  res.render('jenis-ikan', {
    ...buildViewLocals(req, {
      pageId: 'jenis-ikan',
      pageTitle: 'Jenis Ikan',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Jenis Ikan', null]],
      pageScripts: ['jenis-ikan.js'],
    }),
    species,
    nActive,
    CATEGORIES: SPECIES_CATEGORIES,
    CAT_BADGE: SPECIES_CAT_BADGE,
    canEdit: canManage(req),
  });
});

pagesRouter.post(['/jenis-ikan.php', '/jenis-ikan'], requireLogin, (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;
  if (!canManage(req)) {
    flashSet(req, 'error', 'Hanya admin/operator yang dapat mengubah jenis ikan.');
    res.redirect('/jenis-ikan.php');
    return;
  }

  const action = typeof req.body.action === 'string' ? req.body.action : '';

  if (action === 'add' || action === 'edit') {
    const name = typeof req.body.name === 'string' ? req.body.name.trim() : '';
    const latin =
      typeof req.body.latin_name === 'string' && req.body.latin_name.trim() !== ''
        ? req.body.latin_name.trim()
        : null;
    const rawCat = typeof req.body.category === 'string' ? req.body.category : 'laut';
    const cat = (SPECIES_CATEGORIES[rawCat] ? rawCat : 'laut') as 'laut' | 'tawar' | 'brackish';
    const desc =
      typeof req.body.description === 'string' && req.body.description.trim() !== ''
        ? req.body.description.trim()
        : null;
    const makeThreshold = req.body.make_threshold !== undefined;

    let cycMin = fopt(req.body.cycle_months_min, 1, 60);
    let cycMax = fopt(req.body.cycle_months_max, 1, 60);
    const tw = fopt(req.body.target_weight_kg, 0.005, 500);
    const lwA = fopt(req.body.lw_a, 0.0001, 1.0) ?? 0.015;
    const lwB = fopt(req.body.lw_b, 2.0, 4.0) ?? 3.0;
    let tMin = fopt(req.body.optimal_temp_min, -5, 45);
    let tMax = fopt(req.body.optimal_temp_max, -5, 45);
    let phMin = fopt(req.body.optimal_ph_min, 0, 14);
    let phMax = fopt(req.body.optimal_ph_max, 0, 14);
    let sMin = fopt(req.body.optimal_salinity_min, 0, 60);
    let sMax = fopt(req.body.optimal_salinity_max, 0, 60);

    if (tMin !== null && tMax !== null && tMin > tMax) [tMin, tMax] = [tMax, tMin];
    if (phMin !== null && phMax !== null && phMin > phMax) [phMin, phMax] = [phMax, phMin];
    if (sMin !== null && sMax !== null && sMin > sMax) [sMin, sMax] = [sMax, sMin];
    if (cycMin !== null && cycMax !== null && cycMin > cycMax) [cycMin, cycMax] = [cycMax, cycMin];

    let ok = false;
    if (
      name === '' ||
      name.length > 100 ||
      (latin !== null && latin.length > 120) ||
      (desc !== null && desc.length > 500)
    ) {
      flashSet(
        req,
        'error',
        'Nama wajib diisi (maks. 100 karakter); latin maks. 120; deskripsi maks. 500.'
      );
    } else if (action === 'add') {
      if (store.fishSpecies.some((f) => f.name.toLowerCase() === name.toLowerCase())) {
        flashSet(
          req,
          'error',
          `Gagal menyimpan — nama "${name}" mungkin sudah dipakai jenis ikan lain.`
        );
      } else {
        const now = formatDbDate();
        store.fishSpecies.push({
          id: nextId(store.fishSpecies),
          name,
          latin_name: latin,
          category: cat,
          cycle_months_min: cycMin,
          cycle_months_max: cycMax,
          target_weight_kg: tw,
          lw_a: lwA,
          lw_b: lwB,
          optimal_temp_min: tMin,
          optimal_temp_max: tMax,
          optimal_ph_min: phMin,
          optimal_ph_max: phMax,
          optimal_salinity_min: sMin,
          optimal_salinity_max: sMax,
          description: desc,
          is_active: 1,
          created_at: now,
          updated_at: now,
        });
        flashSet(req, 'success', `Jenis ikan "${name}" berhasil ditambahkan.`);
        ok = true;
      }
    } else {
      const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;
      const duplicate = store.fishSpecies.some(
        (f) => f.id !== id && f.name.toLowerCase() === name.toLowerCase()
      );
      const target = store.fishSpecies.find((f) => f.id === id);
      if (!target || duplicate) {
        flashSet(
          req,
          'error',
          `Gagal menyimpan — nama "${name}" mungkin sudah dipakai jenis ikan lain.`
        );
      } else {
        target.name = name;
        target.latin_name = latin;
        target.category = cat;
        target.cycle_months_min = cycMin;
        target.cycle_months_max = cycMax;
        target.target_weight_kg = tw;
        target.lw_a = lwA;
        target.lw_b = lwB;
        target.optimal_temp_min = tMin;
        target.optimal_temp_max = tMax;
        target.optimal_ph_min = phMin;
        target.optimal_ph_max = phMax;
        target.optimal_salinity_min = sMin;
        target.optimal_salinity_max = sMax;
        target.description = desc;
        target.updated_at = formatDbDate();
        flashSet(req, 'success', `Jenis ikan "${name}" berhasil diperbarui.`);
        ok = true;
      }
    }

    if (ok && makeThreshold && name !== '') {
      const cu = currentUser(req)!;
      const thRows: Array<[string, number | null, number | null, number, string]> = [
        ['temp_sensor_c', tMin, tMax, 1.0, '°C'],
        ['ph', phMin, phMax, 0.3, ''],
        ['salinity_psu', sMin, sMax, 1.5, 'PSU'],
      ];
      let n = 0;
      const now = formatDbDate();
      for (const [p, mn, mx, span, unit] of thRows) {
        if (mn === null && mx === null) continue;
        const existing = store.thresholds.find((t) => t.parameter === p && t.species === name);
        if (existing) {
          existing.min_value = mn;
          existing.max_value = mx;
          existing.warn_span = span;
          existing.unit = unit;
          existing.updated_by = cu.id;
          existing.updated_at = now;
        } else {
          store.thresholds.push({
            id: nextId(store.thresholds),
            parameter: p,
            species: name,
            min_value: mn,
            max_value: mx,
            warn_span: span,
            unit,
            updated_by: cu.id,
            updated_at: now,
          });
        }
        n++;
      }
      if (n > 0) {
        flashSet(
          req,
          'info',
          `Ambang kualitas air untuk "${name}" disimpan (${n} parameter) — dipakai evaluasi Kualitas Air & Peringatan otomatis.`
        );
      }
    }
    res.redirect('/jenis-ikan.php');
    return;
  }

  if (action === 'toggle') {
    const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;
    const target = store.fishSpecies.find((f) => f.id === id);
    if (target) target.is_active = 1 - target.is_active;
    flashSet(req, 'success', 'Status jenis ikan diperbarui.');
    res.redirect('/jenis-ikan.php');
    return;
  }

  if (action === 'delete') {
    const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;
    const idx = store.fishSpecies.findIndex((f) => f.id === id);
    if (idx !== -1) {
      const [removed] = store.fishSpecies.splice(idx, 1);
      flashSet(req, 'success', `Jenis ikan "${removed.name}" dihapus.`);
    } else {
      flashSet(req, 'error', 'Jenis ikan tidak ditemukan.');
    }
    res.redirect('/jenis-ikan.php');
    return;
  }

  res.redirect('/jenis-ikan.php');
});

/* ==================== 18. PENGATURAN ==================== */
pagesRouter.get(['/pengaturan.php', '/pengaturan'], requireLogin, (req: Request, res: Response) => {
  const userMap = new Map(store.users.map((u) => [u.id, u.username]));
  const thresholds = store.thresholds
    .filter((t) => t.parameter !== 'salinity_psu' && t.parameter !== 'turbidity_ntu')
    .slice()
    .sort((a, b) => a.parameter.localeCompare(b.parameter) || a.species.localeCompare(b.species))
    .map((t) => ({
      ...t,
      updater: t.updated_by ? (userMap.get(t.updated_by) ?? null) : null,
    }));

  const speciesNames = store.fishSpecies
    .filter((f) => f.is_active === 1)
    .map((f) => f.name)
    .sort((a, b) => a.localeCompare(b));

  const users = store.users.slice().sort((a, b) => a.id - b.id);

  res.render('pengaturan', {
    ...buildViewLocals(req, {
      pageId: 'pengaturan',
      pageTitle: 'Pengaturan',
      breadcrumb: [['Dashboard', 'dashboard.php'], ['Pengaturan', null]],
      pageScripts: ['pengaturan.js'],
    }),
    thresholds,
    speciesNames,
    users,
  });
});

pagesRouter.post(['/pengaturan.php', '/pengaturan'], requireLogin, (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;
  const cu = currentUser(req)!;
  const isAdm = isAdmin(req);
  const canMgr = canManage(req);
  const action = typeof req.body.action === 'string' ? req.body.action : '';

  if (action === 'update_profile') {
    const fullName = typeof req.body.full_name === 'string' ? req.body.full_name.trim() : '';
    const email = typeof req.body.email === 'string' ? req.body.email.trim() : '';
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (fullName === '' || fullName.length > 100) {
      flashSet(req, 'error', 'Nama lengkap tidak valid.');
    } else if (!emailRegex.test(email) || email.length > 100) {
      flashSet(req, 'error', 'Alamat email tidak valid.');
    } else if (store.users.some((u) => u.email === email && u.id !== cu.id)) {
      flashSet(req, 'error', 'Email sudah dipakai akun lain.');
    } else {
      cu.full_name = fullName;
      cu.email = email;
      req.session.name = fullName;
      flashSet(req, 'success', 'Profil berhasil diperbarui.');
    }
    res.redirect('/pengaturan.php');
    return;
  }

  if (action === 'change_password') {
    const cur = typeof req.body.current_password === 'string' ? req.body.current_password : '';
    const nw = typeof req.body.new_password === 'string' ? req.body.new_password : '';
    const cfm = typeof req.body.confirm_password === 'string' ? req.body.confirm_password : '';
    if (!bcrypt.compareSync(cur, cu.password_hash)) {
      flashSet(req, 'error', 'Password saat ini salah.');
    } else if (nw.length < 8 || nw.length > 255) {
      flashSet(req, 'error', 'Password baru minimal 8 karakter.');
    } else if (nw !== cfm) {
      flashSet(req, 'error', 'Konfirmasi password tidak cocok.');
    } else if (cur === nw) {
      flashSet(req, 'error', 'Password baru harus berbeda dari yang lama.');
    } else {
      cu.password_hash = bcrypt.hashSync(nw, 10);
      flashSet(req, 'success', 'Password berhasil diubah.');
    }
    res.redirect('/pengaturan.php');
    return;
  }

  if (action === 'save_thresholds' && canMgr) {
    const ids = asArr(req.body.th_id ?? req.body['th_id[]']);
    const mins = asArr(req.body.th_min ?? req.body['th_min[]']);
    const maxs = asArr(req.body.th_max ?? req.body['th_max[]']);
    const spans = asArr(req.body.th_span ?? req.body['th_span[]']);

    const now = formatDbDate();
    for (let i = 0; i < ids.length; i++) {
      const id = Number.parseInt(ids[i], 10) || 0;
      const min = mins[i] === undefined || mins[i] === '' ? null : Number(mins[i]);
      const max = maxs[i] === undefined || maxs[i] === '' ? null : Number(maxs[i]);
      const spanRaw = Number(spans[i]);
      const span = Number.isFinite(spanRaw) ? spanRaw : 0;
      if (id <= 0 || (min !== null && max !== null && min > max)) continue;
      const th = store.thresholds.find((t) => t.id === id);
      if (th) {
        th.min_value = min;
        th.max_value = max;
        th.warn_span = span;
        th.updated_by = cu.id;
        th.updated_at = now;
      }
    }

    const speciesNew = typeof req.body.th_species === 'string' ? req.body.th_species.trim() : '';
    const paramNew = typeof req.body.th_param === 'string' ? req.body.th_param : '';
    const minNew = Number(req.body.th_min_new);
    const maxNew = Number(req.body.th_max_new);
    const spanNewRaw = Number(req.body.th_span_new);
    const unitNew = typeof req.body.th_unit_new === 'string' ? req.body.th_unit_new.trim() : '';

    if (
      speciesNew !== '' &&
      paramNew !== '' &&
      req.body.th_min_new !== '' &&
      req.body.th_max_new !== '' &&
      Number.isFinite(minNew) &&
      Number.isFinite(maxNew) &&
      speciesNew.length <= 100
    ) {
      const existing = store.thresholds.find(
        (t) => t.parameter === paramNew && t.species === speciesNew
      );
      const spanNew = Number.isFinite(spanNewRaw) ? spanNewRaw : 0;
      if (existing) {
        existing.min_value = minNew;
        existing.max_value = maxNew;
        existing.warn_span = spanNew;
        existing.unit = unitNew;
        existing.updated_by = cu.id;
        existing.updated_at = now;
      } else {
        store.thresholds.push({
          id: nextId(store.thresholds),
          parameter: paramNew,
          species: speciesNew,
          min_value: minNew,
          max_value: maxNew,
          warn_span: spanNew,
          unit: unitNew,
          updated_by: cu.id,
          updated_at: now,
        });
      }
      flashSet(req, 'success', `Ambang baru untuk "${speciesNew}" disimpan.`);
    }

    flashSet(req, 'success', 'Ambang parameter berhasil diperbarui.');
    res.redirect('/pengaturan.php');
    return;
  }

  if (action === 'save_settings' && isAdm) {
    for (const k of ['site_name', 'site_location', 'map_center_lat', 'map_center_lng', 'map_zoom']) {
      if (typeof req.body[k] === 'string') {
        saveSetting(k, req.body[k].trim());
      }
    }
    if (req.body.dashboard_refresh_seconds !== undefined) {
      const sec = Math.max(
        5,
        Math.min(600, Number.parseInt(String(req.body.dashboard_refresh_seconds), 10) || 15)
      );
      saveSetting('dashboard_refresh_seconds', String(sec));
    }
    if (req.body.data_stale_minutes !== undefined) {
      const mn = Math.max(
        1,
        Math.min(120, Number.parseInt(String(req.body.data_stale_minutes), 10) || 10)
      );
      saveSetting('data_stale_minutes', String(mn));
    }
    saveSetting('notify_browser', req.body.notify_browser !== undefined ? '1' : '0');
    saveSetting('notify_email', req.body.notify_email !== undefined ? '1' : '0');
    if (req.body.low_power_threshold_w !== undefined) {
      saveSetting(
        'low_power_threshold_w',
        String(Math.max(0, Number(req.body.low_power_threshold_w) || 0))
      );
    }
    if (req.body.owc_capture_width_m !== undefined) {
      saveSetting(
        'owc_capture_width_m',
        String(Math.max(0.1, Number(req.body.owc_capture_width_m) || 3))
      );
    }
    if (req.body.owc_efficiency !== undefined) {
      saveSetting(
        'owc_efficiency',
        String(Math.min(1, Math.max(0.01, Number(req.body.owc_efficiency) || 0.3)))
      );
    }
    if (req.body.fish_lw_a !== undefined) {
      saveSetting('fish_lw_a', String(Math.max(0.0001, Number(req.body.fish_lw_a) || 0.015)));
    }
    if (req.body.fish_lw_b !== undefined) {
      saveSetting(
        'fish_lw_b',
        String(Math.max(1, Math.min(5, Number(req.body.fish_lw_b) || 3)))
      );
    }
    flashSet(req, 'success', 'Pengaturan sistem berhasil disimpan.');
    res.redirect('/pengaturan.php');
    return;
  }

  if (action === 'add_user' && isAdm) {
    const username = typeof req.body.username === 'string' ? req.body.username.trim().toLowerCase() : '';
    const email = typeof req.body.email === 'string' ? req.body.email.trim() : '';
    const fullName = typeof req.body.full_name === 'string' ? req.body.full_name.trim() : '';
    const rawRole = typeof req.body.role === 'string' ? req.body.role : 'viewer';
    const role = (['admin', 'operator', 'viewer'].includes(rawRole) ? rawRole : 'viewer') as UserRole;
    const password = typeof req.body.password === 'string' ? req.body.password : '';
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (!/^[a-z0-9_.]{3,50}$/.test(username)) {
      flashSet(req, 'error', 'Username 3-50 karakter (huruf kecil, angka, titik, underscore).');
    } else if (!emailRegex.test(email)) {
      flashSet(req, 'error', 'Email tidak valid.');
    } else if (password.length < 8) {
      flashSet(req, 'error', 'Password minimal 8 karakter.');
    } else if (store.users.some((u) => u.username === username || u.email === email)) {
      flashSet(req, 'error', 'Username atau email sudah digunakan.');
    } else {
      store.users.push({
        id: nextId(store.users),
        username,
        email,
        password_hash: bcrypt.hashSync(password, 10),
        full_name: fullName || username,
        role,
        is_active: 1,
        last_login: null,
        created_at: formatDbDate(),
      });
      flashSet(req, 'success', `Pengguna ${username} (${role}) berhasil dibuat.`);
    }
    res.redirect('/pengaturan.php');
    return;
  }

  if (action === 'toggle_user' && isAdm) {
    const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;
    if (id === cu.id) {
      flashSet(req, 'error', 'Tidak dapat menonaktifkan akun sendiri.');
    } else {
      const target = store.users.find((u) => u.id === id);
      if (target) target.is_active = 1 - target.is_active;
      flashSet(req, 'success', 'Status pengguna diperbarui.');
    }
    res.redirect('/pengaturan.php');
    return;
  }

  if (action === 'reset_password' && isAdm) {
    const id = Number.parseInt(String(req.body.id ?? '0'), 10) || 0;
    const nw = typeof req.body.new_password === 'string' ? req.body.new_password : '';
    if (nw.length < 8) {
      flashSet(req, 'error', 'Password baru minimal 8 karakter.');
    } else {
      const target = store.users.find((u) => u.id === id);
      if (target) {
        target.password_hash = bcrypt.hashSync(nw, 10);
        flashSet(req, 'success', `Password pengguna #${id} berhasil direset.`);
      } else {
        flashSet(req, 'error', 'Pengguna tidak ditemukan.');
      }
    }
    res.redirect('/pengaturan.php');
    return;
  }

  flashSet(req, 'error', 'Aksi tidak dikenal atau tidak diizinkan.');
  res.redirect('/pengaturan.php');
});
