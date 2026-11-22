import crypto from 'node:crypto';
import path from 'node:path';
import express from 'express';
import session from 'express-session';
import apiRouter from './src/routes/api.ts';
import { pagesRouter } from './src/routes/pages.ts';

const app = express();
const PORT = Number(process.env.PORT) || 3000;
const HOST = '0.0.0.0';
const ROOT_DIR = process.cwd();
const SESSION_SECRET = process.env.SESSION_SECRET || crypto.randomBytes(32).toString('hex');

app.set('trust proxy', 1);
app.set('view engine', 'ejs');
app.set('views', path.join(ROOT_DIR, 'views'));
app.disable('x-powered-by');

app.use((req, res, next) => {
  if (!req.path.startsWith('/assets/') && !req.path.startsWith('/uploads/fishcount/')) {
    res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    res.setHeader('Pragma', 'no-cache');
  }
  next();
});

app.use(express.urlencoded({ extended: true, limit: '2mb' }));
app.use(express.json({ limit: '10mb' }));

// Static assets preserved identically from repository
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

const sessionMiddleware = session({
  name: 'bolu_sid',
  secret: SESSION_SECRET,
  resave: false,
  saveUninitialized: false,
  proxy: true,
  cookie: {
    httpOnly: true,
    path: '/',
    maxAge: 24 * 60 * 60 * 1000,
    sameSite: 'lax',
    secure: false,
  },
});

app.use(sessionMiddleware);

// Dynamically match HTTPS preview iframe requirements while keeping localhost HTTP working
app.use((req, _res, next) => {
  const fwdProto = String(req.headers['x-forwarded-proto'] || '').toLowerCase();
  const origin = String(req.headers.origin || '');
  const referer = String(req.headers.referer || '');
  const secFetchDest = String(req.headers['sec-fetch-dest'] || '');
  const isHttps = req.secure || fwdProto.includes('https');
  const isIframePreview =
    secFetchDest === 'iframe' ||
    /aistudio|googleusercontent|space-z\.ai|fcapp\.run/i.test(origin + ' ' + referer);

  if (req.session) {
    if (isHttps && isIframePreview) {
      req.session.cookie.sameSite = 'none';
      req.session.cookie.secure = true;
    } else if (isHttps) {
      req.session.cookie.sameSite = 'lax';
      req.session.cookie.secure = true;
    } else {
      req.session.cookie.sameSite = 'lax';
      req.session.cookie.secure = false;
    }
  }
  next();
});

app.use('/api', apiRouter);
app.use('/', pagesRouter);

app.use((_req, res) => {
  res.status(404).type('html').send('<h1>404 — Halaman tidak ditemukan</h1><p><a href="/">Kembali ke Beranda</a></p>');
});

app.use(
  (
    err: Error & { status?: number },
    req: express.Request,
    res: express.Response,
    _next: express.NextFunction
  ) => {
    console.error('[BOLU server error]', err.message);
    if (req.path.startsWith('/api/')) {
      res.status(err.status || 500).json({ ok: false, error: 'Terjadi kesalahan pada server.' });
      return;
    }
    res.status(err.status || 500).send('Terjadi kesalahan pada server.');
  }
);

app.listen(PORT, HOST, () => {
  console.log(`BOLU server running on http://${HOST}:${PORT}`);
});
