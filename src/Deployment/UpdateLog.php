<?php

namespace App\Deployment;

/**
 * The terminal output of an update, stored as a plain file so the process that
 * runs the deployment can append to it while the browser polls it.
 */
class UpdateLog
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function append(int $runId, string $text): void
    {
        $path = $this->path($runId);
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        file_put_contents($path, $text, FILE_APPEND | LOCK_EX);
    }

    public function replace(int $runId, string $text): void
    {
        $directory = \dirname($this->path($runId));
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        file_put_contents($this->path($runId), $text, LOCK_EX);
    }

    /**
     * @return array{chunk: string, offset: int}
     */
    public function read(int $runId, int $offset = 0): array
    {
        $path = $this->path($runId);
        if (!is_file($path)) {
            return ['chunk' => '', 'offset' => 0];
        }

        $size = (int) filesize($path);
        if ($offset >= $size) {
            return ['chunk' => '', 'offset' => $size];
        }

        $handle = fopen($path, 'rb');
        fseek($handle, max(0, $offset));
        $chunk = (string) stream_get_contents($handle, 400000);
        fclose($handle);

        return ['chunk' => $chunk, 'offset' => max(0, $offset) + \strlen($chunk)];
    }

    private function path(int $runId): string
    {
        return $this->projectDir . '/var/updates/run-' . $runId . '.log';
    }
}
