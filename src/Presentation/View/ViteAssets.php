<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * Подключение entrypoints Vite.
 * По умолчанию — same-origin /build (работает за системным прокси-браузером).
 * HMR (:5173) только если есть hot-файл от `npm run ui:hmr`.
 */
final class ViteAssets
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /** HTML-теги для entry (`ui` | `busy`). */
    public function tags(string $entry): string
    {
        $source = 'assets/ts/' . $entry . '.ts';
        $hotFile = $this->projectRoot . '/public/build/hot';

        // hot только от `npm run ui:hmr` — :5173 часто ломается в браузере с системным прокси.
        if (is_readable($hotFile)) {
            $origin = trim((string) file_get_contents($hotFile));
            if ($origin === '') {
                $origin = 'http://127.0.0.1:5173';
            }

            return '<script type="module" src="' . $this->e($origin . '/@vite/client') . '"></script>' . "\n"
                . '<script type="module" src="' . $this->e($origin . '/' . $source) . '"></script>';
        }

        return $this->manifestTags($source)
            ?? '<!-- Vite: нет public/build (npm run build / npm run ui) -->';
    }

    /** Same-origin бандл из manifest — надёжен за прокси. */
    private function manifestTags(string $source): ?string
    {
        $manifestPath = $this->projectRoot . '/public/build/.vite/manifest.json';
        if (!is_readable($manifestPath)) {
            $manifestPath = $this->projectRoot . '/public/build/manifest.json';
        }
        if (!is_readable($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || !isset($manifest[$source]) || !is_array($manifest[$source])) {
            return '<!-- Vite: entry не найден в manifest: ' . $this->e($source) . ' -->';
        }

        $file = $manifest[$source]['file'] ?? null;
        if (!is_string($file) || $file === '') {
            return '<!-- Vite: пустой file в manifest для ' . $this->e($source) . ' -->';
        }

        $html = '';
        $css = $manifest[$source]['css'] ?? null;
        if (is_array($css)) {
            foreach ($css as $href) {
                if (is_string($href) && $href !== '') {
                    $html .= '<link rel="stylesheet" href="/build/' . $this->e($href) . '">' . "\n";
                }
            }
        }

        // Vite режет shared-чанки с import — нужен type=module, иначе LiveReload/JS молча мёртвы.
        $html .= '<script type="module" src="/build/' . $this->e($file) . '"></script>';

        return $html;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
