#!/usr/bin/env node
/**
 * Dev UI на :8888: статика с диска, PHP — на :8889.
 * php -S однопоточный: LiveReload/CSS не должны ждать тяжёлую главную.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const PUBLIC = path.join(ROOT, 'public');
const LISTEN_HOST = '127.0.0.1';
const LISTEN_PORT = Number(process.env.UI_PORT || 8888);
const PHP_HOST = '127.0.0.1';
// Не :8889 — часто занят Jupyter; снаружи всё равно :8888.
const PHP_PORT = Number(process.env.UI_PHP_PORT || 18789);

const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.html': 'text/html; charset=utf-8',
};

function safePublicPath(urlPath) {
  const decoded = decodeURIComponent((urlPath || '/').split('?')[0]);
  const rel = decoded.replace(/^\/+/, '');
  const full = path.normalize(path.join(PUBLIC, rel));
  if (!full.startsWith(PUBLIC + path.sep) && full !== PUBLIC) {
    return null;
  }
  return full;
}

function isStaticPath(urlPath) {
  const p = (urlPath || '/').split('?')[0];
  if (p === '/style.css' || p.startsWith('/style.css/')) {
    return true;
  }
  if (p.startsWith('/build/')) {
    return true;
  }
  // favicon и прочие файлы в корне public
  if (/^\/[a-zA-Z0-9._-]+\.(ico|png|svg|jpg|jpeg|webp)$/.test(p)) {
    return true;
  }
  return false;
}

function sendFile(res, filePath) {
  const ext = path.extname(filePath).toLowerCase();
  const type = MIME[ext] || 'application/octet-stream';
  res.writeHead(200, {
    'Content-Type': type,
    'Cache-Control': 'no-store',
  });
  fs.createReadStream(filePath).pipe(res);
}

function proxyToPhp(req, res) {
  const headers = { ...req.headers, host: `${PHP_HOST}:${PHP_PORT}` };
  const opts = {
    hostname: PHP_HOST,
    port: PHP_PORT,
    path: req.url,
    method: req.method,
    headers,
  };
  const upstream = http.request(opts, (up) => {
    res.writeHead(up.statusCode || 502, up.headers);
    up.pipe(res);
  });
  upstream.on('error', (err) => {
    if (!res.headersSent) {
      res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
    }
    res.end(`PHP upstream ${PHP_HOST}:${PHP_PORT} недоступен: ${err.message}`);
  });
  req.pipe(upstream);
}

const server = http.createServer((req, res) => {
  const urlPath = req.url || '/';
  if (req.method === 'GET' || req.method === 'HEAD') {
    if (isStaticPath(urlPath)) {
      const filePath = safePublicPath(urlPath);
      if (filePath && fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
        if (req.method === 'HEAD') {
          const ext = path.extname(filePath).toLowerCase();
          res.writeHead(200, {
            'Content-Type': MIME[ext] || 'application/octet-stream',
            'Cache-Control': 'no-store',
          });
          res.end();
          return;
        }
        sendFile(res, filePath);
        return;
      }
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('Not found');
      return;
    }
  }
  proxyToPhp(req, res);
});

server.listen(LISTEN_PORT, LISTEN_HOST, () => {
  console.log(
    `UI proxy http://${LISTEN_HOST}:${LISTEN_PORT}/ → static+PHP :${PHP_PORT}`,
  );
});
