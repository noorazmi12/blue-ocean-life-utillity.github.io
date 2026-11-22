import fs from 'node:fs';
import path from 'node:path';
import type { Request, Response } from 'express';
import { getPHPLoaderModule, jspi } from '@php-wasm/node-8-3';
import { loadPHPRuntime, PHP, PHPRequestHandler, type HTTPMethod } from '@php-wasm/universal';

interface AdapterInstance {
  php: PHP;
  handler: PHPRequestHandler;
}

let adapterPromise: Promise<AdapterInstance> | null = null;
let requestQueue: Promise<void> = Promise.resolve();

async function createPhpAdapter(): Promise<AdapterInstance> {
  const rootDir = process.cwd();
  const mod = await getPHPLoaderModule();
  const mode = (await jspi()) ? 'jspi' : 'asyncify';
  const wasmPath = path.resolve(rootDir, 'node_modules/@php-wasm/node-8-3', mode, mod.dependencyFilename);

  const env: Record<string, string> = {
    SMART_KJA_DB_DRIVER: 'sqlite',
    SMART_KJA_SQLITE_PATH: '/tmp/bolu_php.sqlite',
    SMART_KJA_SEED_JSON: '/var/www/html/src/data/seedSql.json',
    SMART_KJA_ENV: process.env.NODE_ENV === 'production' ? 'production' : 'development',
    SMART_KJA_FISHCOUNT_AI_URL: process.env.FISHCOUNT_AI_URL || '',
    FISHCOUNT_SPECIES_API_URL: process.env.FISHCOUNT_SPECIES_API_URL || 'http://127.0.0.1:8000/classify',
    SMART_KJA_WASM: '1',
  };

  const runtimeId = await loadPHPRuntime(mod, {
    wasmBinary: fs.readFileSync(wasmPath),
    ENV: env,
  });

  const php = new PHP(runtimeId);
  await php.mkdir('/var/www/html');
  await php.mount('/var/www/html', (_phpInstance, FS, vfsMountPoint) => {
    FS.mount(FS.filesystems.NODEFS, { root: rootDir }, vfsMountPoint);
    return () => FS.unmount(vfsMountPoint);
  });

  // Ensure /tmp exists and has permissive session/sqlite storage
  if (!php.fileExists('/tmp')) {
    await php.mkdir('/tmp');
  }

  const handler = new PHPRequestHandler({
    php,
    documentRoot: '/var/www/html',
    absoluteUrl: 'http://127.0.0.1:3000',
    cookieStore: false,
  });

  return { php, handler };
}

export function getPhpAdapter(): Promise<AdapterInstance> {
  if (!adapterPromise) {
    adapterPromise = createPhpAdapter().catch((err) => {
      adapterPromise = null;
      throw err;
    });
  }
  return adapterPromise;
}

function enqueue<T>(task: () => Promise<T>): Promise<T> {
  const next = requestQueue.then(task, task);
  requestQueue = next.then(
    () => undefined,
    () => undefined
  );
  return next;
}

async function fetchTextWithTimeout(url: string, headers: Record<string, string>, timeoutMs = 10000): Promise<string | null> {
  try {
    const resp = await fetch(url, {
      headers,
      signal: AbortSignal.timeout(timeoutMs),
    });
    if (!resp.ok) return null;
    return await resp.text();
  } catch {
    return null;
  }
}

async function prefetchExternalForPhp(php: PHP, targetUrl: string): Promise<void> {
  const parsed = new URL(targetUrl, 'http://127.0.0.1:3000');
  if (parsed.pathname === '/api/bmkg-wind.php') {
    const adm4 = parsed.searchParams.get('adm4') || '36.73.02.1001';
    if (!/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/.test(adm4)) return;
    const cacheFile = `/tmp/bolu_bmkg_${adm4.replace(/\./g, '_')}.json`;
    if (php.fileExists(cacheFile)) return;
    const bmkgUrl = `https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=${encodeURIComponent(adm4)}`;
    const text = await fetchTextWithTimeout(bmkgUrl, {
      'User-Agent': 'BOLU/1.0 (BMKG wind forecast)',
      Accept: 'application/json',
    }, 10000);
    if (text && text.length < 1_500_000) {
      await php.writeFile(cacheFile, text);
    }
  } else if (parsed.pathname === '/api/bathymetry-point.php') {
    const lat = Number(parsed.searchParams.get('lat'));
    const lon = Number(parsed.searchParams.get('lon'));
    if (!Number.isFinite(lat) || !Number.isFinite(lon) || lat < -90 || lat > 90 || lon < -180 || lon > 180) {
      return;
    }
    const key = `${lat.toFixed(3)}_${lon.toFixed(3)}`;
    if (php.fileExists(`/tmp/bolu_bathy_${key}.json`)) return;

    const sibatnasLayers = [
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
    ].join(',');
    const d = 0.2;
    const qs = new URLSearchParams({
      service: 'WMS',
      version: '1.1.1',
      request: 'GetFeatureInfo',
      layers: sibatnasLayers,
      query_layers: sibatnasLayers,
      styles: '',
      srs: 'EPSG:4326',
      bbox: `${lon - d},${lat - d},${lon + d},${lat + d}`,
      width: '101',
      height: '101',
      x: '50',
      y: '50',
      info_format: 'application/json',
      feature_count: '20',
    });
    const sibatnasUrl = `https://sibatnas.big.go.id/geoserver/ows?${qs.toString()}`;
    const gebcoUrl = `https://api.opentopodata.org/v1/gebco2020?locations=${encodeURIComponent(`${lat.toFixed(6)},${lon.toFixed(6)}`)}`;

    const [rawBatnas, rawGebco] = await Promise.all([
      fetchTextWithTimeout(sibatnasUrl, { 'User-Agent': 'BOLU-Monitoring/1.0 (+bathymetry proxy)', Accept: 'application/json' }, 10000),
      fetchTextWithTimeout(gebcoUrl, { 'User-Agent': 'BOLU-Monitoring/1.0 (+bathymetry proxy)', Accept: 'application/json' }, 10000),
    ]);
    if (rawBatnas) {
      await php.writeFile(`/tmp/bolu_raw_batnas_${key}.json`, rawBatnas);
    }
    if (rawGebco) {
      await php.writeFile(`/tmp/bolu_raw_gebco_${key}.json`, rawGebco);
    }
  }
}

function normalizeCookieForContext(cookieStr: string, isHttps: boolean, isIframePreview: boolean): string {
  // Strip any existing SameSite/Secure directives so we align cleanly with preview iframe or localhost
  const cleaned = cookieStr
    .replace(/;\s*SameSite=[^;]*/gi, '')
    .replace(/;\s*Secure\b/gi, '');
  if (isHttps && isIframePreview) {
    return `${cleaned}; SameSite=None; Secure`;
  }
  if (isHttps) {
    return `${cleaned}; SameSite=Lax; Secure`;
  }
  return `${cleaned}; SameSite=Lax`;
}

export async function handlePhpRequest(req: Request, res: Response, overrideUrl?: string): Promise<void> {
  const { php, handler } = await getPhpAdapter();
  const targetUrl = overrideUrl ?? req.originalUrl;

  await prefetchExternalForPhp(php, targetUrl);

  const fwdProto = String(req.headers['x-forwarded-proto'] || '').toLowerCase();
  const origin = String(req.headers.origin || '');
  const referer = String(req.headers.referer || '');
  const secFetchDest = String(req.headers['sec-fetch-dest'] || '');
  const isHttps = req.secure || fwdProto.includes('https');
  const isIframePreview =
    secFetchDest === 'iframe' ||
    /aistudio|googleusercontent|space-z\.ai|fcapp\.run/i.test(`${origin} ${referer}`);

  const forwardHeaders: Record<string, string> = {};
  for (const [k, v] of Object.entries(req.headers)) {
    if (v === undefined) continue;
    const key = k.toLowerCase();
    if (key === 'host' || key === 'content-length' || key === 'connection') continue;
    forwardHeaders[key] = Array.isArray(v) ? v.join(', ') : String(v);
  }
  forwardHeaders.host = String(req.headers.host || '127.0.0.1:3000');
  if (isHttps) {
    forwardHeaders['x-forwarded-proto'] = 'https';
  }

  let bodyArg: Uint8Array | undefined;
  if (req.method !== 'GET' && req.method !== 'HEAD') {
    if (Buffer.isBuffer(req.body) && req.body.length > 0) {
      bodyArg = new Uint8Array(req.body);
    } else if (typeof req.body === 'string' && req.body.length > 0) {
      bodyArg = new TextEncoder().encode(req.body);
    }
  }

  const phpRes = await enqueue(async () => {
    try {
      return await handler.request({
        url: targetUrl,
        method: (req.method.toUpperCase() || 'GET') as HTTPMethod,
        headers: forwardHeaders,
        body: bodyArg,
      });
    } catch (err) {
      adapterPromise = null;
      throw err;
    }
  });

  const statusCode = phpRes.httpStatusCode || 200;
  res.status(statusCode);

  for (const [hdrName, values] of Object.entries(phpRes.headers || {})) {
    const lower = hdrName.toLowerCase();
    if (lower === 'transfer-encoding' || lower === 'connection' || lower === 'content-length') {
      continue;
    }
    if (!Array.isArray(values) || values.length === 0) continue;
    if (lower === 'set-cookie') {
      res.setHeader(
        'Set-Cookie',
        values.map((c) => normalizeCookieForContext(c, isHttps, isIframePreview))
      );
    } else if (lower === 'location') {
      // Rewrite internal 127.0.0.1:3000 origin if PHPRequestHandler injected an absolute URL
      const loc = values[values.length - 1].replace(/^https?:\/\/127\.0\.0\.1:3000/i, '');
      res.setHeader('Location', loc);
    } else {
      res.setHeader(hdrName, values.length === 1 ? values[0] : values);
    }
  }

  if (phpRes.errors && phpRes.errors.trim()) {
    console.error('[PHP-WASM stderr]', phpRes.errors.trim());
  }

  const payload = Buffer.from(phpRes.bytes);
  if (statusCode >= 500 && /Fatal error:/i.test(payload.toString('utf8', 0, 512))) {
    adapterPromise = null;
  }
  res.send(payload);
}
