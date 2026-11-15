<?php
declare(strict_types=1);

namespace JobSearch\Presentation\View;

/**
 * View: печатает данные как pretty JSON в stdout.
 * Без обращений к API и конфигу.
 */
final class JsonStdoutView implements View
{
    public function render(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
}
