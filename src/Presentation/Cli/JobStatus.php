<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Cli;

/**
 * Маркер фонового UI-job: data/cache/job.status.json.
 */
final class JobStatus
{
    public function __construct(private readonly string $path) {}

    /** @param array<string, mixed> $data */
    public function write(array $data): void
    {
        $data['updated_at'] = time();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }
        $tmp = $this->path . '.tmp';
        file_put_contents($tmp, $json . "\n");
        rename($tmp, $this->path);
    }
}
