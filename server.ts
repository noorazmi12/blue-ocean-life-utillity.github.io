import fs from 'node:fs';
import path from 'node:path';
import express, { type NextFunction, type Request, type Response } from 'express';
import { getPhpAdapter, handlePhpRequest } from './src/phpAdapter.ts';

const app = express();
const PORT = Number(process.env.PORT) || 3000;
const HOST = '0.0.0.0';
const ROOT_DIR = process.cwd();

app.set('trust proxy', 1);
app.disable('x-powered-by');

app.use((req, res, next) => {
  if (!req.path.startsWith('/assets/') && !req.path.startsWith('/uploads/fishcount/')) {
    res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    res.setHeader('Pragma', 'no-cache');
  }
  next();
});

// Serve static assets identically from repository
app.use(
  '/assets',
  express.static(path.join(ROOT_DIR, 'assets'), {
    fallthrough: false,
  })
);

// Block directory browsing on /uploads/ while serving uploaded media under /uploads/fishcount/
app.get(['/uploads', '/uploads/'], (_req, res) => {
  res
    .status(403)
    .type('html')
    .send(
      '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>403</title></head>' +
        '<body><h1>Akses langsung ke direktori ini tidak diizinkan.</h1></body></html>'
    );
});
app.use(
  '/uploads',
  express.static(path.join(ROOT_DIR, 'uploads'), {
    index: false,
  })
);

// Block direct HTTP access to internal config/includes/database/source directories
app.use(['/config', '/includes', '/database', '/src', '/views', '/node_modules'], (_req, res) => {
  res.status(403).type('html').send('<h1>403 — Akses ditolak</h1>');
});

// Capture raw request body for native PHP $_POST / php://input / $_FILES parsing
app.use(express.raw({ type: '*/*', limit: '12mb' }));

const EXTENSIONLESS_PAGES = new Set([
  'login',
  'logout',
  'dashboard',
  'penjelasan-kja',
  'kualitas-air',
  'gelombang',
  'energi-owc',
  'fishcount',
  'fishcount-species',
  'webgis',
  'batimetri',
  'lokasi-kja',
  'prediksi-ai',
  'peringatan',
  'laporan',
  'perangkat',
  'jenis-ikan',
  'pengaturan',
]);

// Route all PHP pages, /, extensionless aliases, and /api/*.php through the PHP 8.3 WASM adapter
app.use((req: Request, res: Response, next: NextFunction) => {
  const reqPath = req.path;

  let targetScript: string | null = null;
  if (reqPath === '/' || reqPath === '/index.php') {
    targetScript = '/index.php';
  } else if (/^\/(?:api\/)?[a-z0-9_-]+\.php$/i.test(reqPath)) {
    const abs = path.resolve(ROOT_DIR, reqPath.replace(/^\/+/, ''));
    if (abs.startsWith(ROOT_DIR + path.sep) && fs.existsSync(abs) && fs.statSync(abs).isFile()) {
      targetScript = reqPath;
    }
  } else {
    const bare = reqPath.replace(/^\/+|\/+$/g, '');
    if (EXTENSIONLESS_PAGES.has(bare)) {
      targetScript = `/${bare}.php`;
    }
  }

  if (!targetScript) {
    next();
    return;
  }

  const qIdx = req.originalUrl.indexOf('?');
  const qs = qIdx >= 0 ? req.originalUrl.slice(qIdx) : '';
  const targetUrl = `${targetScript}${qs}`;

  void handlePhpRequest(req, res, targetUrl).catch(next);
});

app.use((_req, res) => {
  res
    .status(404)
    .type('html')
    .send('<h1>404 — Halaman tidak ditemukan</h1><p><a href="/">Kembali ke Beranda</a></p>');
});

app.use(
  (
    err: Error & { status?: number },
    req: Request,
    res: Response,
    _next: NextFunction
  ) => {
    console.error('[BOLU PHP-WASM server error]', err.message);
    if (req.path.startsWith('/api/')) {
      res.status(err.status || 500).json({ ok: false, error: 'Terjadi kesalahan pada server PHP.' });
      return;
    }
    res.status(err.status || 500).send('Terjadi kesalahan pada server PHP.');
  }
);

app.listen(PORT, HOST, () => {
  console.log(`BOLU PHP-WASM adapter server running on http://${HOST}:${PORT}`);
  void getPhpAdapter()
    .then(() => console.log('PHP 8.3 runtime initialized and mounted at /var/www/html'))
    .catch((err) => console.error('Failed to initialize PHP 8.3 runtime:', err));
});
