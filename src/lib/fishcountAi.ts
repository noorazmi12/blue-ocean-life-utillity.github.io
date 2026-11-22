import fs from 'node:fs/promises';
import { store } from '../data/store.ts';
import { settingFloat } from './helpers.ts';

export interface FishcountAiData {
  fish_count: number;
  confidence_pct: number;
  avg_length_cm: number | null;
  notes: string;
  model: string;
}

export function fishcountAiUrl(): string {
  return process.env.FISHCOUNT_AI_URL ?? process.env.SMART_KJA_FISHCOUNT_AI_URL ?? '';
}

export function fishcountAiEnabled(): boolean {
  return fishcountAiUrl() !== '';
}

export async function fishcountAiAnalyze(
  absPath: string,
  mime: string
): Promise<{ ok: true; data: FishcountAiData } | { ok: false; error: string }> {
  const url = fishcountAiUrl();
  if (!url) {
    return { ok: false, error: 'Layanan AI belum dikonfigurasi.' };
  }
  let buf: Buffer;
  try {
    buf = await fs.readFile(absPath);
  } catch {
    return { ok: false, error: 'Berkas gambar tidak ditemukan.' };
  }

  try {
    const resp = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        image_base64: buf.toString('base64'),
        mime,
      }),
      signal: AbortSignal.timeout(90000),
    });
    const j = (await resp.json().catch(() => null)) as Record<string, unknown> | null;
    const dataObj = (j?.data ?? null) as Record<string, unknown> | null;
    if (!j || !j.ok || !dataObj || dataObj.fish_count === undefined) {
      const err = j && typeof j.error === 'string' ? j.error : 'Respons layanan tidak valid.';
      return { ok: false, error: err.slice(0, 160) };
    }
    const conf = Math.max(0, Math.min(100, Math.trunc(Number(dataObj.confidence_pct ?? 0))));
    let len =
      dataObj.avg_length_cm !== null && dataObj.avg_length_cm !== undefined
        ? Number(dataObj.avg_length_cm)
        : null;
    if (len !== null && (!Number.isFinite(len) || len <= 0 || len > 300)) {
      len = null;
    }
    return {
      ok: true,
      data: {
        fish_count: Math.max(0, Math.trunc(Number(dataObj.fish_count))),
        confidence_pct: conf,
        avg_length_cm: len,
        notes: String(dataObj.notes ?? '').trim().slice(0, 140),
        model: String(dataObj.model ?? 'ai').slice(0, 60),
      },
    };
  } catch {
    return { ok: false, error: 'Layanan AI tidak dapat dihubungi (tidak aktif / timeout).' };
  }
}

export function fishcountAiApply(resultId: number, ai: FishcountAiData): boolean {
  const row = store.fishcountResults.find((r) => r.id === resultId);
  if (!row) return false;
  const a = settingFloat('fish_lw_a', 0.015);
  const b = settingFloat('fish_lw_b', 3.0);
  let bio: number | null = null;
  if (ai.avg_length_cm !== null && ai.fish_count > 0) {
    bio = Number(((a * Math.pow(ai.avg_length_cm, b) * ai.fish_count) / 1000).toFixed(2));
  }
  let msg =
    `Estimasi AI vision (${ai.model}): ${ai.fish_count} ekor · keyakinan ${ai.confidence_pct}% — angka kasar, mohon validasi manual.` +
    (ai.notes !== '' ? ` Catatan AI: ${ai.notes}` : '');
  msg = msg.slice(0, 255);

  row.detected_count = ai.fish_count;
  row.avg_length_cm = ai.avg_length_cm;
  row.biomass_kg = bio;
  row.confidence_pct = ai.confidence_pct;
  row.processing_status = 'ai_done';
  row.status_message = msg;
  return true;
}

export function fishcountAiMarkFailed(resultId: number, error: string): void {
  const row = store.fishcountResults.find((r) => r.id === resultId);
  if (!row) return;
  row.status_message = `Layanan AI gagal diproses: ${error} — gambar tersimpan, status tetap Menunggu AI.`.slice(0, 255);
}
