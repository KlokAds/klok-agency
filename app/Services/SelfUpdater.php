<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Brings the live install up to date with GitHub from Settings → App update:
 * fetch the branch, check it out, composer install when composer.lock changed,
 * migrate, rebuild caches. The built frontend (public/build) is committed, so the
 * server needs no Node. .env and storage are untracked and never touched.
 *
 * Each step runs as its own process with the CLI php that matches the web one,
 * so the new code is loaded fresh rather than mixed into this request.
 */
class SelfUpdater
{
    public const LAST_RUN_KEY = 'books:self-update:last';

    public function available(): bool
    {
        return is_dir(base_path('.git'));
    }

    public function branch(): string
    {
        return (string) config('books.update_branch', 'main');
    }

    /**
     * @return array{available: bool, branch: string, current: ?array{sha: string, subject: string, date: string}, latest: ?string, behind: ?bool, last_run: ?array<string, mixed>}
     */
    public function status(): array
    {
        $status = ['available' => $this->available(), 'branch' => $this->branch(), 'current' => null, 'latest' => null, 'behind' => null,
            'last_run' => Cache::get(self::LAST_RUN_KEY)];

        if (! $status['available']) {
            return $status;
        }

        $head = $this->git(['log', '-1', '--format=%H%x1f%s%x1f%cI']);
        if ($head->successful() && str_contains($head->output(), "\x1f")) {
            [$sha, $subject, $date] = explode("\x1f", trim($head->output()), 3);
            $status['current'] = compact('sha', 'subject', 'date');
        }

        $remote = $this->git(['ls-remote', 'origin', 'refs/heads/'.$this->branch()], 30);
        if ($remote->successful() && preg_match('/^([0-9a-f]{40})\s/', $remote->output(), $m)) {
            $status['latest'] = $m[1];
            $status['behind'] = $status['current'] !== null && $status['current']['sha'] !== $m[1];
        }

        return $status;
    }

    /**
     * @return array{ok: bool, from: ?string, to: ?string, log: list<string>, at: string}
     */
    public function update(): array
    {
        $lock = Cache::lock('books:self-update', 900);
        if (! $lock->get()) {
            throw new RuntimeException(__('একটা update আগে থেকেই চলছে।'));
        }

        @set_time_limit(900);
        ignore_user_abort(true);

        $log = [];
        $from = $to = null;
        $ok = false;

        try {
            if (! $this->available()) {
                throw new RuntimeException(__('এই install git checkout নয়, তাই নিজে update হতে পারে না।'));
            }

            $from = trim($this->run($log, 'git rev-parse HEAD', $this->git(['rev-parse', 'HEAD']))->output());
            $lockBefore = @md5_file(base_path('composer.lock')) ?: null;

            $this->run($log, 'git fetch', $this->git(['fetch', '--prune', 'origin', $this->branch()], 180));
            $this->run($log, 'git checkout', $this->git(['checkout', '--force', '-B', $this->branch(), 'FETCH_HEAD']));
            $to = trim($this->git(['rev-parse', 'HEAD'])->output());

            if ($from === $to) {
                $log[] = 'Already up to date.';
            }

            $artisan = fn (string $command, int $timeout = 300) => $this->run($log, "artisan {$command}",
                $this->process($timeout)->run(array_merge([$this->php(), 'artisan'], explode(' ', $command), ['--no-interaction'])));

            $artisan('down --retry=30');

            try {
                if ((@md5_file(base_path('composer.lock')) ?: null) !== $lockBefore || ! is_file(base_path('vendor/autoload.php'))) {
                    $this->run($log, 'composer install', $this->process(600)->run(
                        [$this->php(), $this->composer(), 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-progress']));
                }

                $artisan('migrate --force', 600);
                $artisan('optimize:clear');
                $artisan('optimize');
            } finally {
                $artisan('up');
            }

            $ok = true;
        } catch (Throwable $e) {
            $log[] = 'Failed: '.$this->clean($e->getMessage());
        } finally {
            $lock->release();
        }

        $result = ['ok' => $ok, 'from' => $from, 'to' => $to, 'log' => $log, 'at' => now()->toIso8601String()];
        Cache::forever(self::LAST_RUN_KEY, $result);
        activity('Update')->withProperties(['title' => 'App update', 'from' => $from, 'to' => $to, 'ok' => $ok])->log('update');

        return $result;
    }

    /** The command-line php that matches the web one (on Hostinger the web runs lsphp). */
    public function php(): string
    {
        if (filled($configured = config('books.php_cli'))) {
            return $configured;
        }

        $binary = PHP_BINARY;
        $cli = preg_replace('#/(lsphp|php-fpm|php-cgi)[^/]*$#', '/php', $binary);

        return $cli && is_file($cli) ? $cli : 'php';
    }

    private function composer(): string
    {
        if (filled($configured = config('books.composer'))) {
            return $configured;
        }

        foreach (['/usr/local/bin/composer', '/usr/bin/composer', $this->home().'/.local/bin/composer', base_path('composer.phar')] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $which = $this->process(10)->run(['sh', '-c', 'command -v composer']);
        if ($which->successful() && filled(trim($which->output()))) {
            return trim($which->output());
        }

        throw new RuntimeException('composer.lock changed but composer was not found. Set BOOKS_COMPOSER in .env.');
    }

    /** @param list<string> $args */
    private function git(array $args, int $timeout = 60): ProcessResult
    {
        // The web server's environment has no HOME, so git would not find the stored GitHub login.
        return $this->process($timeout)->run(array_merge(['git', '-c', 'credential.helper=store'], $args));
    }

    private function process(int $timeout): PendingProcess
    {
        return Process::path(base_path())->timeout($timeout)->env([
            'HOME' => $this->home(),
            'COMPOSER_HOME' => $this->home().'/.composer',
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
    }

    /** @param list<string> $log */
    private function run(array &$log, string $label, ProcessResult $result): ProcessResult
    {
        $output = $this->clean(trim($result->output()."\n".$result->errorOutput()));
        $log[] = ($result->successful() ? '✓ ' : '✗ ').$label.($output !== '' ? "\n".mb_substr($output, -1500) : '');

        if ($result->failed()) {
            throw new RuntimeException("{$label} failed (exit {$result->exitCode()}).");
        }

        return $result;
    }

    private function home(): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
        if (! $home && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $home = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        }

        return $home ?: dirname(base_path());
    }

    /** Never show a GitHub token that sits in a remote URL. */
    private function clean(string $text): string
    {
        return preg_replace('#(https?://)[^/@\s]+@#', '$1', $text) ?? $text;
    }
}
