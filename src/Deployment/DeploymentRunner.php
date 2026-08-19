<?php

namespace App\Deployment;

use Symfony\Component\Process\Process;

/**
 * Runs a fixed, whitelisted sequence of deploy commands — never arbitrary
 * shell input. This is deliberately narrow: it exists so a CEO can trigger
 * "pull latest + apply migrations + clear cache" from the UI, not so the
 * app can execute anything else.
 *
 * Security note: letting an authenticated web action rewrite the app's own
 * source code and run new code from it is a real elevation-of-privilege
 * surface (a compromised CEO session/credential becomes a compromised
 * server). It's accepted here as a deliberate, informed trade-off for a
 * small internal tool — see the "Incidents résolus" / deployment section of
 * the documentation. Each step still runs a fixed binary with fixed
 * arguments (no string concatenation from user input), and the whole thing
 * is gated behind ROLE_CEO specifically (not just ROLE_DEVELOPER).
 */
class DeploymentRunner
{
    private const TIMEOUT_SECONDS = 300.0;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{success: bool, steps: array<int, array{label: string, command: string, exitCode: ?int, output: string}>}
     */
    public function run(): array
    {
        $steps = [
            ['label' => 'Récupération du code (git pull)', 'command' => ['git', 'pull', '--ff-only']],
            ['label' => 'Installation des dépendances (composer install)', 'command' => ['composer', 'install', '--no-interaction']],
            ['label' => 'Application des migrations', 'command' => ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']],
            ['label' => 'Vidage du cache', 'command' => ['php', 'bin/console', 'cache:clear', '--no-interaction']],
        ];

        $results = [];
        $success = true;

        foreach ($steps as $step) {
            if (!$success) {
                $results[] = ['label' => $step['label'], 'command' => implode(' ', $step['command']), 'exitCode' => null, 'output' => '(ignoré — étape précédente en échec)'];

                continue;
            }

            $process = new Process($step['command'], $this->projectDir, null, null, self::TIMEOUT_SECONDS);
            $process->run();

            $results[] = [
                'label' => $step['label'],
                'command' => implode(' ', $step['command']),
                'exitCode' => $process->getExitCode(),
                'output' => trim($process->getOutput() . $process->getErrorOutput()),
            ];

            if (!$process->isSuccessful()) {
                $success = false;
            }
        }

        return ['success' => $success, 'steps' => $results];
    }
}
