import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { Router, type Request, type Response } from 'express';
import { formatDbDate, formatYmd, nextId, parseDbDateMs, store } from '../data/store.ts';
import { canManage, csrfCheck, currentUser, requireLoginApi } from '../lib/auth.ts';
import {
  fishcountAiAnalyze,
  fishcountAiApply,
  fishcountAiEnabled,
  fishcountAiMarkFailed,
} from '../lib/fishcountAi.ts';
import {
  DEMO_MODE,
  UPLOAD_MAX_BYTES,
  dataStatus,
  evaluateValue,
  fmtDt,
  fmtNum,
  fmtTimeHi,
  fmtTimeHis,
  maybeCreateAlert,
  setting,
  settingFloat,
  wavePowerKw,
} from '../lib/helpers.ts';
import type { WaterMeasurement } from '../types.ts';

const router = Router();

function numOrNull(v: unknown, min: number, max: number): number | null {
  if (v === null || v === undefined || v === '') return null;
  const f = Number(v);
  if (!Number.isFinite(f) || f < min || f > max) return null;
  return Math.round(f * 10000) / 10000;
}

/* ==================== 1. /api/water-data.php ==================== */
router.get('/water-data.php', requireLoginApi, (req: Request, res: Response) => {
  const view = String(req.query.view ?? 'latest');
  const ALLOWED = ['temp_sensor_c', 'ph', 'volt_ph_v'] as const;
  const dev = store.devices
    .filter((d) => d.device_type === 'water_sensor' && d.is_active === 1)
    .sort((a, b) => a.id - b.id)[0];

  if (!dev) {
    res.json({ ok: true, data: { points: [], latest: null, status: 'offline', device: null } });
    return;
  }

  const shape = (r: WaterMeasurement) => ({
    measured_at: r.measured_at,
    temp_sensor_c: r.temp_sensor_c !== null ? Number(r.temp_sensor_c) : null,
    ph: r.ph !== null ? Number(r.ph) : null,
    volt_ph_v: r.volt_ph_v !== null ? Number(r.volt_ph_v) : null,
    is_simulated: Number(r.is_simulated),
  });

  const devRows = store.waterMeasurements
    .filter((m) => m.device_id === dev.id)
    .sort((a, b) => a.measured_at.localeCompare(b.measured_at) || a.id - b.id);

  if (view === 'latest') {
    const r = devRows[devRows.length - 1] ?? null;
    const data: Record<string, unknown> = {
      device: { id: dev.id, code: dev.device_code, name: dev.device_name },
      latest: r ? shape(r) : null,
      status: dataStatus(r?.measured_at ?? null),
    };
    if (r) {
      const evals: Record<string, { status: string; label: string }> = {};
      for (const p of ['temp_sensor_c', 'ph'] as const) {
        if (r[p] !== null) {
          const ev = evaluateValue(p, r[p]);
          evals[p] = { status: ev.status, label: ev.label };
        }
      }
      data.evaluation = evals;
    }
    res.json({ ok: true, data });
    return;
  }

  if (view === 'range') {
    const param = String(req.query.param ?? 'ph') as (typeof ALLOWED)[number];
    if (!ALLOWED.includes(param)) {
      res.status(400).json({ ok: false, error: 'Parameter tidak dikenal.' });
      return;
    }
    const minutes = Math.max(5, Math.min(20160, Number.parseInt(String(req.query.minutes ?? 1440), 10) || 1440));
    const cutoff = Date.now() - minutes * 60 * 1000;
    const rows = devRows
      .filter((r) => {
        const ms = parseDbDateMs(r.measured_at);
        return ms !== null && ms >= cutoff && r[param] !== null && r[param] !== undefined;
      })
      .slice(-3000);
    const points = rows.map((r) => ({
      t: fmtTimeHis(r.measured_at),
      v: Number(r[param]),
    }));
    const latest = devRows[devRows.length - 1] ?? null;
    res.json({
      ok: true,
      data: {
        device: { id: dev.id, code: dev.device_code, name: dev.device_name },
        param,
        points,
        latest: latest
          ? {
              measured_at: latest.measured_at,
              v: latest[param] !== null && latest[param] !== undefined ? Number(latest[param]) : null,
            }
          : null,
        status: dataStatus(latest?.measured_at ?? null),
      },
    });
    return;
  }

  if (view === 'multi') {
    const minutes = Math.max(5, Math.min(10080, Number.parseInt(String(req.query.minutes ?? 1440), 10) || 1440));
    const cutoff = Date.now() - minutes * 60 * 1000;
    const rows = devRows
      .filter((r) => {
        const ms = parseDbDateMs(r.measured_at);
        return ms !== null && ms >= cutoff;
      })
      .slice(0, 3000);
    const series: Record<string, Array<{ t: string; v: number }>> = {};
    for (const r of rows) {
      const t = fmtTimeHi(r.measured_at);
      for (const p of ALLOWED) {
        if (r[p] !== null && r[p] !== undefined) {
          if (!series[p]) series[p] = [];
          series[p].push({ t, v: Number(r[p]) });
        }
      }
    }
    res.json({ ok: true, data: { series } });
    return;
  }

  res.status(400).json({ ok: false, error: 'Parameter view tidak dikenal.' });
});

/* ==================== 2. /api/wave-data.php ==================== */
router.get('/wave-data.php', requireLoginApi, (req: Request, res: Response) => {
  const view = String(req.query.view ?? 'latest');
  const dev = store.devices
    .filter((d) => d.device_type === 'wave_sensor' && d.is_active === 1)
    .sort((a, b) => a.id - b.id)[0];

  if (!dev) {
    res.json({ ok: true, data: { points: [], latest: null, stats: null, status: 'offline', device: null } });
    return;
  }

  const devRows = store.waveMeasurements
    .filter((m) => m.device_id === dev.id)
    .sort((a, b) => a.measured_at.localeCompare(b.measured_at) || a.id - b.id);

  if (view === 'latest') {
    const r = devRows[devRows.length - 1] ?? null;
    res.json({
      ok: true,
      data: {
        device: { id: dev.id, code: dev.device_code, name: dev.device_name },
        latest: r
          ? {
              measured_at: r.measured_at,
              h: Number(r.wave_height_m),
              t: Number(r.wave_period_s),
              wd: r.wave_direction_deg !== null ? Number(r.wave_direction_deg) : null,
              w: r.wind_speed_ms !== null ? Number(r.wind_speed_ms) : null,
              wind_dir: r.wind_direction_deg !== null ? Number(r.wind_direction_deg) : null,
              kw: Number(wavePowerKw(r.wave_height_m, r.wave_period_s).toFixed(3)),
              is_simulated: Number(r.is_simulated),
            }
          : null,
        status: dataStatus(r?.measured_at ?? null),
      },
    });
    return;
  }

  if (view === 'range') {
    const from = String(req.query.from ?? '');
    const to = String(req.query.to ?? '');
    let fromSql: string;
    let toSql: string;
    if (/^\d{4}-\d{2}-\d{2}$/.test(from) && /^\d{4}-\d{2}-\d{2}$/.test(to)) {
      fromSql = `${from} 00:00:00`;
      toSql = `${to} 23:59:59`;
    } else {
      const minutes = Math.max(5, Math.min(10080, Number.parseInt(String(req.query.minutes ?? 60), 10) || 60));
      fromSql = formatDbDate(Date.now() - minutes * 60 * 1000);
      toSql = formatDbDate();
    }
    const rows = devRows
      .filter((r) => r.measured_at >= fromSql && r.measured_at <= toSql)
      .slice(0, 3000);

    const hs = rows.map((r) => Number(r.wave_height_m));
    const points = rows.map((r) => ({
      t: fmtTimeHi(r.measured_at),
      h: Number(r.wave_height_m),
      p: Number(r.wave_period_s),
      w: r.wind_speed_ms !== null ? Number(r.wind_speed_ms) : null,
    }));
    const latest = rows[rows.length - 1] ?? null;
    const stats =
      hs.length > 0
        ? {
            min: Number(Math.min(...hs).toFixed(3)),
            max: Number(Math.max(...hs).toFixed(3)),
            avg: Number((hs.reduce((s, v) => s + v, 0) / hs.length).toFixed(3)),
            count: hs.length,
          }
        : null;

    res.json({
      ok: true,
      data: {
        device: { id: dev.id, code: dev.device_code, name: dev.device_name },
        from: fromSql,
        to: toSql,
        points,
        stats,
        latest: latest
          ? {
              measured_at: latest.measured_at,
              h: Number(latest.wave_height_m),
              t: Number(latest.wave_period_s),
              w: latest.wind_speed_ms !== null ? Number(latest.wind_speed_ms) : null,
              kw: Number(wavePowerKw(latest.wave_height_m, latest.wave_period_s).toFixed(3)),
            }
          : null,
        status: dataStatus(latest?.measured_at ?? null),
      },
    });
    return;
  }

  res.status(400).json({ ok: false, error: 'Parameter view tidak dikenal.' });
});

/* ==================== 3. /api/energy-data.php ==================== */
router.get('/energy-data.php', requireLoginApi, (req: Request, res: Response) => {
  const view = String(req.query.view ?? 'latest');
  const dev = store.devices
    .filter((d) => d.device_type === 'owc' && d.is_active === 1)
    .sort((a, b) => a.id - b.id)[0];

  if (!dev) {
    res.json({ ok: true, data: { points: [], latest: null, status: 'offline', device: null } });
    return;
  }

  const devRows = store.owcMeasurements
    .filter((m) => m.device_id === dev.id)
    .sort((a, b) => a.measured_at.localeCompare(b.measured_at) || a.id - b.id);

  if (view === 'latest') {
    const r = devRows[devRows.length - 1] ?? null;
    res.json({
      ok: true,
      data: {
        device: { id: dev.id, code: dev.device_code, name: dev.device_name },
        latest: r
          ? {
              measured_at: r.measured_at,
              p: Number(r.power_w),
              v: Number(r.voltage_v),
              c: Number(r.current_a),
              e: Number(r.energy_wh),
              batt: r.battery_pct !== null ? Number(r.battery_pct) : null,
              is_simulated: Number(r.is_simulated),
            }
          : null,
        status: dataStatus(r?.measured_at ?? null),
      },
    });
    return;
  }

  if (view === 'range') {
    const minutes = Math.max(5, Math.min(10080, Number.parseInt(String(req.query.minutes ?? 1440), 10) || 1440));
    const cutoff = Date.now() - minutes * 60 * 1000;
    const waveCutoff = Date.now() - (minutes + 10) * 60 * 1000;
    const rows = devRows
      .filter((r) => {
        const ms = parseDbDateMs(r.measured_at);
        return ms !== null && ms >= cutoff;
      })
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

    const latest = rows[rows.length - 1] ?? null;
    res.json({
      ok: true,
      data: {
        device: { id: dev.id, code: dev.device_code, name: dev.device_name },
        points,
        latest: latest
          ? {
              measured_at: latest.measured_at,
              p: Number(latest.power_w),
              batt: latest.battery_pct !== null ? Number(latest.battery_pct) : null,
            }
          : null,
        status: dataStatus(latest?.measured_at ?? null),
      },
    });
    return;
  }

  if (view === 'daily') {
    const days = Math.max(1, Math.min(90, Number.parseInt(String(req.query.days ?? 7), 10) || 7));
    const cutoff = Date.now() - days * 86400 * 1000;
    const groups = new Map<string, Array<{ energy_wh: number; power_w: number }>>();
    for (const r of devRows) {
      const ms = parseDbDateMs(r.measured_at);
      if (ms === null || ms < cutoff) continue;
      const day = formatYmd(ms);
      if (!groups.has(day)) groups.set(day, []);
      groups.get(day)!.push({ energy_wh: Number(r.energy_wh), power_w: Number(r.power_w) });
    }
    const rows = Array.from(groups.entries())
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
    res.json({ ok: true, data: { rows } });
    return;
  }

  if (view === 'summary') {
    const todayStr = formatYmd();
    const todayRows = devRows.filter((r) => r.measured_at.startsWith(todayStr));
    const energyToday =
      todayRows.length > 0
        ? Number(
            (
              Math.max(...todayRows.map((x) => Number(x.energy_wh))) -
              Math.min(...todayRows.map((x) => Number(x.energy_wh)))
            ).toFixed(1)
          )
        : null;
    const peakToday = todayRows.length > 0 ? Math.max(...todayRows.map((x) => Number(x.power_w))) : null;
    const cutoff3h = Date.now() - 3 * 3600 * 1000;
    const rows3h = devRows.filter((r) => {
      const ms = parseDbDateMs(r.measured_at);
      return ms !== null && ms >= cutoff3h;
    });
    const avg3h =
      rows3h.length > 0
        ? Number((rows3h.reduce((s, x) => s + Number(x.power_w), 0) / rows3h.length).toFixed(1))
        : null;

    res.json({
      ok: true,
      data: {
        energy_today: energyToday,
        peak_today: peakToday,
        avg_power_3h: avg3h,
        low_power_threshold_w: settingFloat('low_power_threshold_w', 150),
        status: null,
      },
    });
    return;
  }

  res.status(400).json({ ok: false, error: 'Parameter view tidak dikenal.' });
});

/* ==================== 4. /api/alerts-data.php ==================== */
router.post('/alerts-data.php', requireLoginApi, (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;
  const action = String(req.query.action ?? '');
  const id = Number.parseInt(String(req.body?.id ?? 0), 10);
  if (action !== 'resolve' || !id || id <= 0) {
    res.status(400).json({ ok: false, error: 'Aksi tidak valid.' });
    return;
  }
  if (!canManage(req)) {
    res.status(403).json({ ok: false, error: 'Hanya admin/operator yang dapat menutup peringatan.' });
    return;
  }
  const cu = currentUser(req)!;
  const item = store.alerts.find((a) => a.id === id && a.status === 'open');
  if (!item) {
    res.json({ ok: false });
    return;
  }
  item.status = 'resolved';
  item.resolved_by = cu.id;
  item.resolved_at = formatDbDate();
  res.json({ ok: true });
});

router.get('/alerts-data.php', requireLoginApi, (req: Request, res: Response) => {
  const view = String(req.query.view ?? 'recent');
  const devName = (devId: number | null) =>
    devId ? (store.devices.find((d) => d.id === devId)?.device_name ?? null) : null;

  if (view === 'recent') {
    const limit = Math.max(1, Math.min(30, Number.parseInt(String(req.query.limit ?? 8), 10) || 8));
    const rows = store.alerts
      .slice()
      .sort((a, b) => {
        const ao = a.status === 'open' ? 1 : 0;
        const bo = b.status === 'open' ? 1 : 0;
        if (ao !== bo) return bo - ao;
        return b.occurred_at.localeCompare(a.occurred_at);
      })
      .slice(0, limit)
      .map((a) => ({
        id: a.id,
        parameter: a.parameter,
        severity: a.severity,
        title: a.title,
        occurred_at: a.occurred_at,
        status: a.status,
        device_name: devName(a.device_id),
      }));
    const open_count = store.alerts.filter((a) => a.status === 'open').length;
    res.json({ ok: true, data: rows, open_count });
    return;
  }

  if (view === 'list') {
    const from = String(req.query.from ?? '');
    const to = String(req.query.to ?? '');
    const severity = String(req.query.severity ?? '');
    const status = String(req.query.status ?? '');
    const param = String(req.query.param ?? '');
    const limit = Math.max(1, Math.min(200, Number.parseInt(String(req.query.limit ?? 50), 10) || 50));

    let rows = store.alerts.slice();
    if (/^\d{4}-\d{2}-\d{2}$/.test(from)) rows = rows.filter((a) => a.occurred_at >= `${from} 00:00:00`);
    if (/^\d{4}-\d{2}-\d{2}$/.test(to)) rows = rows.filter((a) => a.occurred_at <= `${to} 23:59:59`);
    if (['info', 'warning', 'critical'].includes(severity)) rows = rows.filter((a) => a.severity === severity);
    if (['open', 'resolved'].includes(status)) rows = rows.filter((a) => a.status === status);
    if (/^[a-z0-9_]{1,40}$/.test(param)) rows = rows.filter((a) => a.parameter === param);

    rows = rows
      .sort((a, b) => b.occurred_at.localeCompare(a.occurred_at))
      .slice(0, limit)
      .map((a) => ({ ...a, device_name: devName(a.device_id) }));

    res.json({ ok: true, data: rows });
    return;
  }

  res.status(400).json({ ok: false, error: 'Parameter view tidak dikenal.' });
});

/* ==================== 5. /api/devices-geo.php ==================== */
router.get('/devices-geo.php', requireLoginApi, (req: Request, res: Response) => {
  const view = String(req.query.view ?? 'geo');

  if (view === 'detail') {
    const id = Number.parseInt(String(req.query.id ?? 0), 10);
    const d = store.devices.find((x) => x.id === id);
    if (!d) {
      res.status(404).json({ ok: false, error: 'Perangkat tidak ditemukan.' });
      return;
    }

    const measurements: Array<{ time: string; vals: Record<string, string | number> }> = [];
    let columns: string[] = [];

    if (d.device_type === 'wave_sensor') {
      columns = ['Tinggi (m)', 'Periode (s)', 'Angin (m/s)'];
      const rows = store.waveMeasurements
        .filter((m) => m.device_id === id)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))
        .slice(0, 20);
      for (const r of rows) {
        measurements.push({
          time: fmtDt(r.measured_at),
          vals: {
            'Tinggi (m)': fmtNum(r.wave_height_m, 3),
            'Periode (s)': fmtNum(r.wave_period_s, 2),
            'Angin (m/s)': r.wind_speed_ms !== null ? fmtNum(r.wind_speed_ms, 1) : '—',
          },
        });
      }
    } else if (d.device_type === 'water_sensor') {
      columns = ['Suhu (°C)', 'pH'];
      const rows = store.waterMeasurements
        .filter((m) => m.device_id === id)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))
        .slice(0, 20);
      for (const r of rows) {
        measurements.push({
          time: fmtDt(r.measured_at),
          vals: {
            'Suhu (°C)': fmtNum(r.temp_sensor_c, 1),
            pH: fmtNum(r.ph, 2),
          },
        });
      }
    } else if (d.device_type === 'owc') {
      columns = ['Daya (W)', 'Tegangan (V)', 'Arus (A)'];
      const rows = store.owcMeasurements
        .filter((m) => m.device_id === id)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))
        .slice(0, 20);
      for (const r of rows) {
        measurements.push({
          time: fmtDt(r.measured_at),
          vals: {
            'Daya (W)': fmtNum(r.power_w, 1),
            'Tegangan (V)': fmtNum(r.voltage_v, 1),
            'Arus (A)': fmtNum(r.current_a, 2),
          },
        });
      }
    } else if (d.device_type === 'camera') {
      columns = ['Jumlah', 'Panjang (cm)', 'Status'];
      const rows = store.fishcountResults
        .filter((m) => m.device_id === id)
        .sort((a, b) => b.created_at.localeCompare(a.created_at))
        .slice(0, 20);
      for (const r of rows) {
        measurements.push({
          time: fmtDt(r.created_at),
          vals: {
            Jumlah: r.detected_count ?? '—',
            'Panjang (cm)': r.avg_length_cm !== null ? fmtNum(r.avg_length_cm, 1) : '—',
            Status: r.processing_status,
          },
        });
      }
    }

    res.json({
      ok: true,
      data: {
        id: d.id,
        code: d.device_code,
        name: d.device_name,
        type: d.device_type,
        data_status: dataStatus(d.last_communication),
        last_comm: d.last_communication ? fmtDt(d.last_communication) : '—',
        calibration: d.calibration_status,
        columns,
        measurements,
      },
    });
    return;
  }

  const devices = store.devices
    .filter((d) => d.is_active === 1)
    .sort((a, b) => a.id - b.id);

  const out = devices.map((d) => {
    const loc = store.deviceLocations.find((l) => l.device_id === d.id) ?? null;
    let latest: Record<string, unknown> | null = null;

    if (d.device_type === 'wave_sensor') {
      const row = store.waveMeasurements
        .filter((m) => m.device_id === d.id)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))[0];
      if (row) {
        latest = {
          measured_at: row.measured_at,
          is_simulated: Number(row.is_simulated),
          Tinggi: `${fmtNum(row.wave_height_m, 2)} m`,
          Periode: `${fmtNum(row.wave_period_s, 1)} s`,
        };
        if (row.wind_speed_ms !== null) latest.Angin = `${fmtNum(row.wind_speed_ms, 1)} m/s`;
      }
    } else if (d.device_type === 'water_sensor') {
      const row = store.waterMeasurements
        .filter((m) => m.device_id === d.id)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))[0];
      if (row) {
        latest = {
          measured_at: row.measured_at,
          is_simulated: Number(row.is_simulated),
          Suhu: `${fmtNum(row.temp_sensor_c, 1)} °C`,
          pH: fmtNum(row.ph, 2),
        };
      }
    } else if (d.device_type === 'owc') {
      const row = store.owcMeasurements
        .filter((m) => m.device_id === d.id)
        .sort((a, b) => b.measured_at.localeCompare(a.measured_at))[0];
      if (row) {
        latest = {
          measured_at: row.measured_at,
          is_simulated: Number(row.is_simulated),
          Daya: `${fmtNum(row.power_w, 1)} W`,
          Tegangan: `${fmtNum(row.voltage_v, 1)} V`,
        };
      }
    } else if (d.device_type === 'camera') {
      const row = store.fishcountResults
        .filter((m) => m.device_id === d.id)
        .sort((a, b) => b.created_at.localeCompare(a.created_at))[0];
      if (row) {
        latest = {
          measured_at: row.created_at,
          is_simulated: Number(row.is_simulated),
          Deteksi: row.detected_count !== null ? `${row.detected_count} ekor` : '—',
          Status: row.processing_status,
        };
      }
    }

    return {
      id: d.id,
      code: d.device_code,
      name: d.device_name,
      type: d.device_type,
      status: d.status,
      lat: loc ? Number(loc.latitude) : null,
      lng: loc ? Number(loc.longitude) : null,
      location_name: loc ? loc.location_name : null,
      latest,
      data_status: dataStatus((latest?.measured_at as string | null | undefined) ?? null),
    };
  });

  res.json({
    ok: true,
    data: {
      center: {
        lat: Number(setting('map_center_lat', -5.77)),
        lng: Number(setting('map_center_lng', 105.264)),
      },
      zoom: Number.parseInt(String(setting('map_zoom', 15)), 10) || 15,
      site_location: setting('site_location', ''),
      demo_mode: DEMO_MODE,
      devices: out,
    },
  });
});

/* ==================== 6. /api/fishcount-data.php ==================== */
router.post('/fishcount-data.php', requireLoginApi, async (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;
  const action = String(req.query.action ?? '');

  if (action === 'snapshot') {
    const dataUrl = String(req.body?.image ?? '');
    if (!/^data:image\/jpeg;base64,/.test(dataUrl)) {
      res.status(400).json({ ok: false, error: 'Format gambar tidak didukung (hanya JPEG dari kamera).' });
      return;
    }
    const b64 = dataUrl.slice('data:image/jpeg;base64,'.length);
    const bin = Buffer.from(b64, 'base64');
    if (bin.length === 0 || bin.length > UPLOAD_MAX_BYTES) {
      res.status(400).json({ ok: false, error: 'Gambar kosong atau melebihi batas ukuran.' });
      return;
    }
    if (bin[0] !== 0xff || bin[1] !== 0xd8) {
      res.status(400).json({ ok: false, error: 'Berkas bukan gambar JPEG yang valid.' });
      return;
    }
    const uploadDir = path.join(process.cwd(), 'uploads', 'fishcount');
    await fs.mkdir(uploadDir, { recursive: true });
    const stamp = formatDbDate().replace(/[-:]/g, '').replace(' ', '_');
    const name = `snap_${stamp}_${crypto.randomBytes(4).toString('hex')}.jpg`;
    const absFile = path.join(uploadDir, name);
    await fs.writeFile(absFile, bin);

    const dev = store.devices
      .filter((d) => d.device_type === 'camera' && d.is_active === 1)
      .sort((a, b) => a.id - b.id)[0];

    const notesRaw = String(req.body?.notes ?? '').trim();
    const newId = nextId(store.fishcountResults);
    store.fishcountResults.push({
      id: newId,
      device_id: dev ? dev.id : null,
      image_path: `uploads/fishcount/${name}`,
      media_type: 'image',
      media_duration_s: null,
      frames_analyzed: null,
      thumb_path: null,
      detected_count: null,
      avg_length_cm: null,
      biomass_kg: null,
      confidence_pct: null,
      processing_status: 'no_ai',
      status_message: fishcountAiEnabled()
        ? 'Snapshot tersimpan, memproses analisis AI…'
        : 'Snapshot kamera — layanan AI belum dikonfigurasi.',
      is_simulated: 0,
      is_manual: 0,
      manual_count: null,
      validated_by: null,
      validated_at: null,
      notes: notesRaw !== '' ? notesRaw.slice(0, 255) : null,
      created_at: formatDbDate(),
    });

    let aiOut = null;
    if (fishcountAiEnabled()) {
      const ai = await fishcountAiAnalyze(absFile, 'image/jpeg');
      if (ai.ok && fishcountAiApply(newId, ai.data)) {
        aiOut = ai.data;
      } else {
        fishcountAiMarkFailed(newId, ai.ok ? 'gagal simpan' : ai.error);
      }
    }

    res.json({ ok: true, id: newId, image: `uploads/fishcount/${name}`, ai: aiOut });
    return;
  }

  if (action === 'validate') {
    if (!canManage(req)) {
      res.status(403).json({ ok: false, error: 'Hanya admin/operator yang dapat memvalidasi.' });
      return;
    }
    const id = Number.parseInt(String(req.body?.id ?? 0), 10);
    const countRaw = req.body?.manual_count;
    const count = Number(countRaw);
    if (!id || id <= 0 || !Number.isInteger(count) || count < 0 || count > 100000) {
      res.status(400).json({ ok: false, error: 'Data validasi tidak valid.' });
      return;
    }
    const row = store.fishcountResults.find((r) => r.id === id);
    if (!row) {
      res.json({ ok: false });
      return;
    }
    const cu = currentUser(req)!;
    const notesRaw = String(req.body?.notes ?? '').trim();
    row.manual_count = count;
    row.is_manual = 1;
    row.validated_by = cu.id;
    row.validated_at = formatDbDate();
    row.notes = notesRaw !== '' ? notesRaw.slice(0, 255) : null;
    res.json({ ok: true });
    return;
  }

  res.status(400).json({ ok: false, error: 'Aksi tidak dikenal.' });
});

router.get('/fishcount-data.php', requireLoginApi, (req: Request, res: Response) => {
  const view = String(req.query.view ?? 'recent');
  if (view === 'recent') {
    const limit = Math.max(1, Math.min(100, Number.parseInt(String(req.query.limit ?? 20), 10) || 20));
    const rows = store.fishcountResults
      .slice()
      .sort((a, b) => b.created_at.localeCompare(a.created_at))
      .slice(0, limit)
      .map((fr) => ({
        ...fr,
        device_name: fr.device_id ? (store.devices.find((d) => d.id === fr.device_id)?.device_name ?? null) : null,
      }));
    res.json({ ok: true, data: rows });
    return;
  }

  if (view === 'stats') {
    const days = Math.max(1, Math.min(90, Number.parseInt(String(req.query.days ?? 7), 10) || 7));
    const cutoff = Date.now() - days * 86400 * 1000;
    const byDay = new Map<string, number | null>();
    for (const r of store.fishcountResults) {
      const ms = parseDbDateMs(r.created_at);
      if (ms === null || ms < cutoff) continue;
      const d = formatYmd(ms);
      const val = r.detected_count ?? r.manual_count;
      const prev = byDay.get(d);
      if (val !== null && (prev === undefined || prev === null || val > prev)) {
        byDay.set(d, val);
      } else if (!byDay.has(d)) {
        byDay.set(d, null);
      }
    }
    const series = Array.from(byDay.entries())
      .sort((a, b) => a[0].localeCompare(b[0]))
      .map(([t, c]) => ({ t, c }));

    const cutoff30 = Date.now() - 30 * 86400 * 1000;
    const buckets: Record<string, number> = { '10-14': 0, '15-19': 0, '20-24': 0, '25-29': 0, '30+': 0 };
    for (const r of store.fishcountResults) {
      if (r.avg_length_cm === null) continue;
      const ms = parseDbDateMs(r.created_at);
      if (ms === null || ms < cutoff30) continue;
      const l = Number(r.avg_length_cm);
      if (l < 15) buckets['10-14']++;
      else if (l < 20) buckets['15-19']++;
      else if (l < 25) buckets['20-24']++;
      else if (l < 30) buckets['25-29']++;
      else buckets['30+']++;
    }
    const sizes = Object.entries(buckets).map(([bucket, n]) => ({ bucket, n }));
    res.json({ ok: true, data: { series, sizes } });
    return;
  }

  res.status(400).json({ ok: false, error: 'Parameter view tidak dikenal.' });
});

/* ==================== 7. /api/ingest.php ==================== */
router.post('/ingest.php', (req: Request, res: Response) => {
  const input = req.body;
  if (!input || typeof input !== 'object' || Array.isArray(input) || Object.keys(input).length === 0) {
    res.status(400).json({ ok: false, error: 'Body JSON tidak valid atau kosong.' });
    return;
  }

  const deviceCode = String(input.device_code ?? '').trim();
  const apiKey = String(input.api_key ?? '');
  if (!deviceCode || !apiKey) {
    res.status(401).json({ ok: false, error: 'device_code dan api_key wajib diisi.' });
    return;
  }
  if (deviceCode.length > 50 || apiKey.length > 128) {
    res.status(401).json({ ok: false, error: 'Format kredensial tidak valid.' });
    return;
  }

  const device = store.devices.find((d) => d.device_code === deviceCode && d.is_active === 1);
  const keyHash = crypto.createHash('sha256').update(apiKey, 'utf8').digest('hex');
  const tokenMatch =
    device?.api_token &&
    device.api_token.length === keyHash.length &&
    crypto.timingSafeEqual(Buffer.from(device.api_token, 'utf8'), Buffer.from(keyHash, 'utf8'));

  if (!device || !tokenMatch) {
    res.status(401).json({ ok: false, error: 'Autentikasi perangkat gagal.' });
    return;
  }

  let ts = String(input.timestamp ?? '').trim();
  if (!ts) {
    ts = formatDbDate();
  } else if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(ts) || parseDbDateMs(ts) === null) {
    res.status(400).json({ ok: false, error: 'Format timestamp tidak valid (YYYY-MM-DD HH:MM:SS).' });
    return;
  } else {
    const ms = parseDbDateMs(ts)!;
    if (Math.abs(Date.now() - ms) > 86400 * 1000) {
      res.status(400).json({ ok: false, error: 'Timestamp terlalu jauh dari waktu server (maks 24 jam).' });
      return;
    }
  }

  const data = input.data;
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    res.status(400).json({ ok: false, error: 'Field "data" harus berupa objek.' });
    return;
  }

  let inserted = 0;
  let alertsCreated = 0;
  const warnings: string[] = [];
  let insertId = 0;
  const now = formatDbDate();

  if (device.device_type === 'wave_sensor') {
    const h = numOrNull(data.wave_height_m, 0, 15);
    const t = numOrNull(data.wave_period_s, 0, 30);
    if (h === null || t === null) {
      res.status(400).json({ ok: false, error: 'wave_height_m dan wave_period_s wajib (angka dalam rentang wajar).' });
      return;
    }
    insertId = nextId(store.waveMeasurements);
    store.waveMeasurements.push({
      id: insertId,
      device_id: device.id,
      measured_at: ts,
      wave_height_m: h,
      wave_period_s: t,
      wave_direction_deg: numOrNull(data.wave_direction_deg, 0, 360),
      wind_speed_ms: numOrNull(data.wind_speed_ms, 0, 75),
      wind_direction_deg: numOrNull(data.wind_direction_deg, 0, 360),
      is_simulated: 0,
      created_at: now,
    });
    inserted = 1;
    if (maybeCreateAlert(device.id, 'wave_height_m', h, false)) alertsCreated++;
  } else if (device.device_type === 'water_sensor') {
    const temp = numOrNull(data.temp_sensor_c, -5, 45);
    const ph = numOrNull(data.ph, 0.05, 14);
    const voltPh = numOrNull(data.volt_ph_v, 0, 3.3);
    if (temp === null && ph === null) {
      res.status(400).json({ ok: false, error: 'temp_sensor_c dan/atau ph wajib ada (minimal satu angka valid).' });
      return;
    }
    insertId = nextId(store.waterMeasurements);
    store.waterMeasurements.push({
      id: insertId,
      device_id: device.id,
      measured_at: ts,
      temp_sensor_c: temp,
      sst_c: numOrNull(data.sst_c, -5, 45),
      salinity_psu: numOrNull(data.salinity_psu, 0, 45),
      ph,
      volt_ph_v: voltPh,
      turbidity_ntu: numOrNull(data.turbidity_ntu, 0, 1000),
      depth_m: numOrNull(data.depth_m, 0, 500),
      is_simulated: 0,
      created_at: now,
    });
    inserted = 1;
    const skipped: string[] = [];
    if (temp === null) skipped.push('temp_sensor_c');
    if (ph === null) skipped.push('ph');
    if (skipped.length > 0) {
      warnings.push(`Nilai tidak valid, disimpan NULL: ${skipped.join(', ')}.`);
    }
    if (temp !== null && maybeCreateAlert(device.id, 'temp_sensor_c', temp, false)) alertsCreated++;
    if (ph !== null && maybeCreateAlert(device.id, 'ph', ph, false)) alertsCreated++;
  } else if (device.device_type === 'owc') {
    const p = numOrNull(data.power_w, 0, 100000);
    const v = numOrNull(data.voltage_v, 0, 1000);
    const c = numOrNull(data.current_a, 0, 1000);
    if (p === null || v === null || c === null) {
      res.status(400).json({ ok: false, error: 'power_w, voltage_v, current_a wajib (angka valid).' });
      return;
    }
    insertId = nextId(store.owcMeasurements);
    store.owcMeasurements.push({
      id: insertId,
      device_id: device.id,
      measured_at: ts,
      power_w: p,
      voltage_v: v,
      current_a: c,
      energy_wh: numOrNull(data.energy_wh, 0, 1e9) ?? 0,
      battery_pct: numOrNull(data.battery_pct, 0, 100),
      is_simulated: 0,
      created_at: now,
    });
    inserted = 1;
  } else if (device.device_type === 'camera') {
    const cnt = numOrNull(data.detected_count, 0, 100000);
    insertId = nextId(store.fishcountResults);
    store.fishcountResults.push({
      id: insertId,
      device_id: device.id,
      image_path: null,
      media_type: 'image',
      media_duration_s: null,
      frames_analyzed: null,
      thumb_path: null,
      detected_count: cnt !== null ? Math.trunc(cnt) : null,
      avg_length_cm: numOrNull(data.avg_length_cm, 0, 300),
      biomass_kg: numOrNull(data.biomass_kg, 0, 100000),
      confidence_pct: numOrNull(data.confidence_pct, 0, 100),
      processing_status: cnt !== null ? 'done' : 'failed',
      status_message: 'Hasil dari layanan AI eksternal via api/ingest.php',
      is_simulated: 0,
      is_manual: 0,
      manual_count: null,
      validated_by: null,
      validated_at: null,
      notes: null,
      created_at: now,
    });
    inserted = 1;
  } else {
    res.status(400).json({ ok: false, error: 'Jenis perangkat tidak dikenal.' });
    return;
  }

  device.status = 'online';
  device.last_communication = ts;

  res.json({
    ok: true,
    id: insertId,
    device: device.device_code,
    inserted,
    alerts_created: alertsCreated,
    warnings,
    message: 'Data diterima dan disimpan.',
  });
});

/* ==================== 8. /api/bathymetry-point.php ==================== */
const SIBATNAS_OWS = 'https://sibatnas.big.go.id/geoserver/ows';
const OPENTOPO_URL = 'https://api.opentopodata.org/v1/gebco2020';
const SIBATNAS_LAYERS = [
  'sibatnas:AREA_MBES_LKI_BARAT_SUMATERA',
  'sibatnas:BALIKPAPAN_100M',
  'sibatnas:BANJARMASIN_100M',
  'sibatnas:Batam',
  'sibatnas:MBTK',
  'sibatnas:MOROWALI',
  'sibatnas:SAMARINDA_100M',
  'sibatnas:SAMPIT_100M',
  'sibatnas:SORONG_100M',
  'sibatnas:TERNATE_100m',
  'sibatnas:TITIKDALAMPERUM_BTG_100M_GCS',
  'sibatnas:TJK_100M',
  'sibatnas:Titik_Kedalaman_Pemeruman_100m',
  'sibatnas:bantaeng_100m',
  'sibatnas:big_tanjungpriuk_000020190906161134',
  'sibatnas:morotai_100m',
  'sibatnas:palembang_100m',
];

const bathyCache = new Map<string, { expires: number; payload: Record<string, unknown> }>();

async function bathyHttpGet(url: string, timeoutMs = 15000): Promise<string | null> {
  try {
    const resp = await fetch(url, {
      headers: { 'User-Agent': 'BOLU-Monitoring/1.0 (+bathymetry proxy)' },
      signal: AbortSignal.timeout(timeoutMs),
    });
    if (!resp.ok) return null;
    return await resp.text();
  } catch {
    return null;
  }
}

async function fetchBatnas(lat: number, lon: number) {
  const d = 0.2;
  const bbox = `${lon - d},${lat - d},${lon + d},${lat + d}`;
  const layers = SIBATNAS_LAYERS.join(',');
  const qs = new URLSearchParams({
    service: 'WMS',
    version: '1.1.1',
    request: 'GetFeatureInfo',
    layers,
    query_layers: layers,
    styles: '',
    srs: 'EPSG:4326',
    bbox,
    width: '101',
    height: '101',
    x: '50',
    y: '50',
    info_format: 'application/json',
    feature_count: '20',
  });
  const raw = await bathyHttpGet(`${SIBATNAS_OWS}?${qs.toString()}`, 20000);
  if (raw === null) return { depth_m: null, layer: null, error: 'SIBATNAS tidak merespons' };
  try {
    const json = JSON.parse(raw) as { features?: Array<{ id?: string; properties?: Record<string, unknown> }> };
    if (!json.features?.length) return { depth_m: null, layer: null };
    for (const f of json.features) {
      const props = f.properties ?? {};
      for (const [k, v] of Object.entries(props)) {
        if (k.toUpperCase().startsWith('NIKDLM') && v !== null && Number.isFinite(Number(v))) {
          let layer: string | null = null;
          if (typeof f.id === 'string') {
            const parts = f.id.split('.');
            if (parts.length >= 3) layer = parts[parts.length - 2];
            else if (parts.length === 2) layer = parts[0];
          }
          return { depth_m: Number(Number(v).toFixed(2)), layer };
        }
      }
    }
  } catch {
    return { depth_m: null, layer: null, error: 'Respon SIBATNAS tidak valid' };
  }
  return { depth_m: null, layer: null };
}

async function fetchGebco(lat: number, lon: number) {
  const url = `${OPENTOPO_URL}?locations=${encodeURIComponent(`${lat.toFixed(6)},${lon.toFixed(6)}`)}`;
  let raw = await bathyHttpGet(url, 15000);
  if (raw === null) {
    await new Promise((r) => setTimeout(r, 1000));
    raw = await bathyHttpGet(url, 15000);
  }
  if (raw === null) return { elev_m: null, error: 'API GEBCO tidak merespons' };
  try {
    const json = JSON.parse(raw) as { status?: string; results?: Array<{ elevation?: number | null }> };
    if (String(json.status ?? '').toUpperCase() !== 'OK' || !json.results?.[0]) {
      return { elev_m: null, error: 'Respon API GEBCO tidak valid' };
    }
    const elev = json.results[0].elevation;
    return { elev_m: elev === null || elev === undefined ? null : Number(Number(elev).toFixed(1)) };
  } catch {
    return { elev_m: null, error: 'Respon API GEBCO tidak valid' };
  }
}

router.get('/bathymetry-point.php', requireLoginApi, async (req: Request, res: Response) => {
  const lat = Number(req.query.lat ?? 999);
  const lon = Number(req.query.lon ?? 999);
  if (!Number.isFinite(lat) || !Number.isFinite(lon) || lat < -90 || lat > 90 || lon < -180 || lon > 180) {
    res.status(400).json({ ok: false, error: 'Parameter lat/lon tidak valid (lat -90..90, lon -180..180).' });
    return;
  }

  const key = `${lat.toFixed(3)}_${lon.toFixed(3)}`;
  const cached = bathyCache.get(key);
  if (cached && cached.expires > Date.now()) {
    res.json({ ...cached.payload, cached: true });
    return;
  }

  const [batnas, gebco] = await Promise.all([fetchBatnas(lat, lon), fetchGebco(lat, lon)]);
  const out = {
    ok: true,
    lat: Number(lat.toFixed(6)),
    lon: Number(lon.toFixed(6)),
    batnas,
    gebco,
    cached: false,
    fetched_at: new Date().toISOString(),
  };
  bathyCache.set(key, { expires: Date.now() + 6 * 3600 * 1000, payload: out });
  res.json(out);
});

/* ==================== 9. /api/bmkg-wind.php ==================== */
const bmkgCache = new Map<string, { expires: number; raw: string }>();

router.get('/bmkg-wind.php', requireLoginApi, async (req: Request, res: Response) => {
  res.setHeader('Cache-Control', 'private, max-age=120');
  const adm4 = String(req.query.adm4 ?? '36.73.02.1001');
  if (!/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/.test(adm4)) {
    res.status(400).json({ ok: false, error: 'Kode ADM4 tidak valid (contoh 36.73.02.1001).' });
    return;
  }

  const cached = bmkgCache.get(adm4);
  let raw = cached && cached.expires > Date.now() ? cached.raw : null;

  if (!raw) {
    try {
      const resp = await fetch(`https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=${encodeURIComponent(adm4)}`, {
        headers: {
          'User-Agent': 'BOLU/1.0 (BMKG wind forecast)',
          Accept: 'application/json',
        },
        signal: AbortSignal.timeout(15000),
      });
      if (!resp.ok) {
        if (cached?.raw) {
          raw = cached.raw;
        } else {
          res.status(502).json({ ok: false, error: `BMKG tidak dapat diakses (${resp.status}).` });
          return;
        }
      } else {
        raw = await resp.text();
        if (raw.length < 1500000) {
          bmkgCache.set(adm4, { expires: Date.now() + 1800 * 1000, raw });
        }
      }
    } catch (err) {
      if (cached?.raw) {
        raw = cached.raw;
      } else {
        const msg = err instanceof Error ? err.message : 'timeout';
        res.status(502).json({ ok: false, error: `BMKG tidak dapat diakses (${msg}).` });
        return;
      }
    }
  }

  let data: { data?: unknown[]; lokasi?: Record<string, unknown> } | null = null;
  try {
    data = JSON.parse(raw) as { data?: unknown[]; lokasi?: Record<string, unknown> };
  } catch {
    data = null;
  }
  if (!data || !Array.isArray(data.data)) {
    res.status(502).json({ ok: false, error: 'Struktur respons BMKG tidak sesuai.' });
    return;
  }

  const loc = data.lokasi && typeof data.lokasi === 'object' ? data.lokasi : {};
  const items: Array<{ stamp: number; time: string; speed: number; direction: string }> = [];
  for (const group of data.data) {
    if (!group || typeof group !== 'object') continue;
    const days = (group as { cuaca?: unknown[] }).cuaca;
    if (!Array.isArray(days)) continue;
    for (const forecasts of days) {
      if (!Array.isArray(forecasts)) continue;
      for (const it of forecasts) {
        if (!it || typeof it !== 'object') continue;
        const item = it as Record<string, unknown>;
        if (item.ws === undefined || item.local_datetime === undefined || !Number.isFinite(Number(item.ws))) continue;
        const ms = parseDbDateMs(String(item.local_datetime));
        if (ms === null) continue;
        items.push({
          stamp: Math.floor(ms / 1000),
          time: String(item.local_datetime),
          speed: Number(item.ws),
          direction: String(item.wd ?? '—'),
        });
      }
    }
  }

  if (items.length === 0) {
    res.status(502).json({ ok: false, error: 'Tidak ada data kecepatan angin untuk ADM4 ini.' });
    return;
  }
  items.sort((a, b) => a.stamp - b.stamp);
  const nowSec = Math.floor(Date.now() / 1000);
  const choice = items.find((it) => it.stamp >= nowSec - 3600) ?? items[items.length - 1];
  const place = [loc.desa, loc.kecamatan, loc.kotkab, loc.provinsi]
    .map((x) => String(x ?? '').trim())
    .filter(Boolean)
    .join(', ');

  res.json({
    ok: true,
    source: 'BMKG — Prakiraan Cuaca Terbuka',
    type: 'coastal_village_forecast',
    adm4,
    place,
    forecast_local: choice.time,
    speed_kmh: choice.speed,
    speed_knots: Number((choice.speed / 1.852).toFixed(1)),
    wind_from: choice.direction,
    stale: choice.stamp < nowSec - 3600,
    retrieved_at: new Date().toISOString(),
  });
});

/* ==================== 10. /api/camera-location.php ==================== */
router.get('/camera-location.php', requireLoginApi, (_req: Request, res: Response) => {
  const cam = store.devices
    .filter((d) => d.device_type === 'camera' && d.is_active === 1)
    .sort((a, b) => a.id - b.id)[0];
  if (!cam) {
    res.json({ ok: true, data: null });
    return;
  }
  const loc = store.deviceLocations.find((l) => l.device_id === cam.id) ?? null;
  res.json({
    ok: true,
    data: {
      id: cam.id,
      device_code: cam.device_code,
      device_name: cam.device_name,
      latitude: loc?.latitude ?? null,
      longitude: loc?.longitude ?? null,
      location_name: loc?.location_name ?? null,
    },
  });
});

router.post('/camera-location.php', requireLoginApi, (req: Request, res: Response) => {
  if (!csrfCheck(req, res)) return;
  if (!canManage(req)) {
    res.status(403).json({ ok: false, error: 'Hanya admin/operator yang dapat mengubah posisi kamera.' });
    return;
  }
  const lat = Number(req.body?.latitude);
  const lon = Number(req.body?.longitude);
  let name = String(req.body?.location_name ?? '').trim();
  if (!Number.isFinite(lat) || lat < -90 || lat > 90 || !Number.isFinite(lon) || lon < -180 || lon > 180) {
    res.status(400).json({ ok: false, error: 'Koordinat latitude/longitude tidak valid.' });
    return;
  }
  if (!name) name = 'Titik pemasangan kamera';
  name = name.slice(0, 120);

  const cam = store.devices
    .filter((d) => d.device_type === 'camera' && d.is_active === 1)
    .sort((a, b) => a.id - b.id)[0];
  if (!cam) {
    res.status(404).json({ ok: false, error: 'Perangkat bertipe camera belum terdaftar.' });
    return;
  }

  const existing = store.deviceLocations.find((l) => l.device_id === cam.id);
  const now = formatDbDate();
  if (existing) {
    existing.latitude = lat;
    existing.longitude = lon;
    existing.location_name = name;
    existing.updated_at = now;
  } else {
    store.deviceLocations.push({
      id: nextId(store.deviceLocations),
      device_id: cam.id,
      latitude: lat,
      longitude: lon,
      location_name: name,
      installed_at: formatYmd(),
      notes: 'Posisi pemasangan ESP32-CAM FishCount',
      updated_at: now,
    });
  }

  res.json({
    ok: true,
    message: 'Posisi kamera berhasil disimpan.',
    data: {
      device_id: cam.id,
      latitude: lat,
      longitude: lon,
      location_name: name,
    },
  });
});

export default router;
