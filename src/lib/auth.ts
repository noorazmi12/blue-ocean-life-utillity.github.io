import crypto from 'node:crypto';
import type { NextFunction, Request, Response } from 'express';
import { store } from '../data/store.ts';
import type { User } from '../types.ts';
import { escHtml } from './helpers.ts';

declare module 'express-session' {
  interface SessionData {
    user_id?: number;
    role?: string;
    name?: string;
    csrf_token?: string;
    login_attempts?: number[];
    flash?: Array<{ t: 'success' | 'error' | 'info' | 'warn'; m: string }>;
  }
}

export function currentUser(req: Request): User | null {
  const uid = req.session?.user_id;
  if (!uid) return null;
  const u = store.users.find((x) => x.id === uid);
  if (!u || Number(u.is_active) !== 1) {
    req.session.user_id = undefined;
    req.session.role = undefined;
    req.session.name = undefined;
    return null;
  }
  req.session.role = u.role;
  return u;
}

export function isAdmin(req: Request): boolean {
  return currentUser(req)?.role === 'admin';
}

export function canManage(req: Request): boolean {
  const role = currentUser(req)?.role;
  return role === 'admin' || role === 'operator';
}

export function csrfToken(req: Request): string {
  if (!req.session.csrf_token) {
    req.session.csrf_token = crypto.randomBytes(32).toString('hex');
  }
  return req.session.csrf_token;
}

export function csrfField(req: Request): string {
  return `<input type="hidden" name="csrf" value="${escHtml(csrfToken(req))}">`;
}

export function csrfCheck(req: Request, res: Response): boolean {
  const bodyToken = typeof req.body?.csrf === 'string' ? req.body.csrf : '';
  const headerToken = typeof req.headers['x-csrf-token'] === 'string' ? req.headers['x-csrf-token'] : '';
  const token = bodyToken || headerToken;
  const expected = csrfToken(req);

  const valid =
    token.length === expected.length &&
    token.length > 0 &&
    crypto.timingSafeEqual(Buffer.from(token, 'utf8'), Buffer.from(expected, 'utf8'));

  if (!valid) {
    res.status(419);
    if (req.path.startsWith('/api/') || req.headers['x-requested-with'] === 'XMLHttpRequest') {
      res.json({ ok: false, error: 'CSRF token tidak valid. Muat ulang halaman.' });
    } else {
      res.send('CSRF token tidak valid. Silakan muat ulang halaman.');
    }
    return false;
  }
  return true;
}

export function flashSet(req: Request, t: 'success' | 'error' | 'info' | 'warn', m: string): void {
  if (!req.session.flash) req.session.flash = [];
  req.session.flash.push({ t, m });
}

export function flashRender(req: Request): string {
  const list = req.session.flash;
  if (!list || list.length === 0) return '';
  req.session.flash = [];
  let html = '<div class="flash-stack">';
  for (const f of list) {
    const cls = f.t === 'error' ? 'danger' : f.t;
    const icon =
      f.t === 'success'
        ? 'fa-circle-check'
        : f.t === 'error'
          ? 'fa-circle-exclamation'
          : 'fa-circle-info';
    html +=
      `<div class="callout callout-${cls} flash-item" role="alert">` +
      `<i class="fa-solid ${icon}"></i><div>${escHtml(f.m)}</div>` +
      `<button type="button" class="flash-close" onclick="this.parentElement.remove()">&times;</button></div>`;
  }
  html += '</div>';
  return html;
}

export function requireLogin(req: Request, res: Response, next: NextFunction): void {
  if (currentUser(req)) {
    next();
    return;
  }
  const script = req.path.replace(/^\//, '');
  const nextParam =
    script && script !== 'login.php' && script !== 'index.php'
      ? `?next=${encodeURIComponent(script)}`
      : '';
  res.redirect(`/login.php${nextParam}`);
}

export function requireLoginApi(req: Request, res: Response, next: NextFunction): void {
  if (currentUser(req)) {
    next();
    return;
  }
  res.status(401).json({ ok: false, error: 'Tidak terautentikasi. Silakan login kembali.' });
}
