<?php

namespace App\Backup;

use Symfony\Component\Process\Process;

/**
 * Light archive of the application code and configuration (no vendor, no
 * var, no compiled assets, no uploads), taken before an update. It is the
 * fallback of the rollback: the normal way back is "git reset" to the previous
 * commit, but this archive also preserves local, uncommitted changes.
 */
class CodeSnapshot
{
    private const INCLUDE = ['src', 'templates', 'config', 'migrations', 'assets', 'translations', 'public', 'bin', 'deploy', 'composer.json', 'composer.lock', 'symfony.lock', 'importmap.php', '.env', '.env.local'];

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{file: string, size: int}
     */
    public function create(): array
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Dossier de sauvegarde inaccessible : ' . $directory);
        }

        $filename = \sprintf('cyllos-code-%s.tar.gz', date('Ymd-His'));
        $paths = array_values(array_filter(self::INCLUDE, fn (string $p) => file_exists($this->projectDir . '/' . $p)));

        $process = new Process(
            ['tar', '-czf', $directory . '/' . $filename, '--exclude=public/assets', '--exclude=public/uploads', '--exclude=public/bundles', '-C', $this->projectDir, ...$paths],
            $this->projectDir,
            null,
            null,
            300,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($directory . '/' . $filename);

            throw new \RuntimeException('Archive du code impossible : ' . trim($process->getErrorOutput()));
        }

        return ['file' => $filename, 'size' => (int) filesize($directory . '/' . $filename)];
    }

    public function delete(string $filename): void
    {
        @unlink($this->path($filename));
    }

    public function exists(string $filename): bool
    {
        return is_file($this->path($filename));
    }

    public function path(string $filename): string
    {
        return $this->directory() . '/' . basename($filename);
    }

    private function directory(): string
    {
        return $this->projectDir . '/var/backups/code';
    }
}
