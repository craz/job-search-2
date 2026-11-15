import fs from 'node:fs';
import path from 'node:path';
import { defineConfig, type Plugin, type ViteDevServer } from 'vite';

const hotFile = path.resolve('public/build/hot');
const reloadStamp = path.resolve('public/build/reload.json');
const viteOrigin = 'http://127.0.0.1:5173';

function writeReloadStamp(): void {
  fs.mkdirSync(path.dirname(reloadStamp), { recursive: true });
  fs.writeFileSync(reloadStamp, `${JSON.stringify({ v: Date.now() })}\n`);
}

/** HMR-сервер (`npm run ui:hmr`): hot-файл + full-reload для PHP/CSS. */
function phpHotFilePlugin(): Plugin {
  const clearHot = (): void => {
    try {
      fs.unlinkSync(hotFile);
    } catch {
      // ignore
    }
  };

  return {
    name: 'php-hot-file',
    configureServer(server: ViteDevServer) {
      fs.mkdirSync(path.dirname(hotFile), { recursive: true });
      fs.writeFileSync(hotFile, viteOrigin);

      server.watcher.add([
        path.resolve('src/Presentation'),
        path.resolve('src/Application'),
        path.resolve('public/style.css'),
        path.resolve('public/index.php'),
      ]);

      server.httpServer?.once('close', clearHot);
      process.once('exit', clearHot);
      process.once('SIGINT', () => {
        clearHot();
        process.exit();
      });
    },
    handleHotUpdate({ file, server }) {
      if (file.endsWith('.php') || file.endsWith('style.css')) {
        server.ws.send({ type: 'full-reload', path: '*' });
      }
    },
  };
}

/**
 * Для `vite build --watch`: stamp `/build/reload.json` после сборки
 * и при правках PHP/CSS — страница сама перезагрузится (LiveReload).
 */
function liveReloadStampPlugin(): Plugin {
  let watchingExtras = false;

  return {
    name: 'live-reload-stamp',
    apply: 'build',
    writeBundle() {
      writeReloadStamp();
      // fs.watch держит процесс — только в `vite build --watch`, не в разовом build.
      if (!this.meta.watchMode || watchingExtras) {
        return;
      }
      watchingExtras = true;

      const watchTarget = (target: string, recursive: boolean): void => {
        try {
          fs.watch(target, { recursive }, () => writeReloadStamp());
        } catch {
          // ignore missing paths
        }
      };

      // Каталог public: атомарная запись style.css (rename) на Linux часто не ловится
      // через fs.watch(файл), зато ловится событие на директории.
      try {
        fs.watch(path.resolve('public'), (_event, filename) => {
          const name = filename == null ? '' : String(filename);
          if (name === 'style.css' || name === 'index.php') {
            writeReloadStamp();
          }
        });
      } catch {
        watchTarget(path.resolve('public/style.css'), false);
        watchTarget(path.resolve('public/index.php'), false);
      }
      // Views/use cases — Onion `src/Presentation` / `src/Application` (не устаревший src/View).
      watchTarget(path.resolve('src/Presentation'), true);
      watchTarget(path.resolve('src/Application'), true);
    },
  };
}

export default defineConfig({
  publicDir: false,
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        ui: path.resolve('assets/ts/ui.ts'),
        busy: path.resolve('assets/ts/busy.ts'),
        vacancies: path.resolve('assets/ts/vacancies.ts'),
        chats: path.resolve('assets/ts/chats.ts'),
        companies: path.resolve('assets/ts/companies.ts'),
        errors: path.resolve('assets/ts/errors.ts'),
      },
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    origin: viteOrigin,
    cors: true,
  },
  plugins: [phpHotFilePlugin(), liveReloadStampPlugin()],
});
