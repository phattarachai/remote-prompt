<?php

namespace Phattarachai\RemotePrompt;

use RuntimeException;

/**
 * File operations on one target's host, written as POSIX shell so any Linux box (and macOS in tests) runs them.
 */
final class Remote
{
    private const array SKIP = ['.git', 'node_modules', 'vendor'];

    public function __construct(
        private readonly Ssh $ssh,
        public readonly Target $target,
    ) {}

    /**
     * Directories first, then files, both by name. GNU find gives size and date; elsewhere a shell loop gives size only.
     *
     * @return list<RemoteEntry>
     */
    public function list(string $dir): array
    {
        $script = 'cd -- '.Ssh::quote($dir).' || exit 1; '
            ."if find . -maxdepth 0 -printf '' >/dev/null 2>&1; then "
            ."find . -mindepth 1 -maxdepth 1 -printf '%Y\\t%s\\t%TY-%Tm-%Td %TH:%TM\\t%P\\n' 2>/dev/null; else "
            .'for f in .[!.]* ..?* *; do [ -e "$f" ] || continue; '
            .'if [ -d "$f" ]; then printf "d\\t\\t\\t%s\\n" "$f"; else printf "f\\t%s\\t\\t%s\\n" "$(wc -c <"$f" | tr -d " ")" "$f"; fi; done; fi';

        $entries = array_filter(array_map(RemoteEntry::parse(...), explode("\n", $this->exec($script))));

        usort($entries, fn (RemoteEntry $a, RemoteEntry $b) => [! $a->isDir, strtolower($a->name)] <=> [! $b->isDir, strtolower($b->name)]);

        return $entries;
    }

    /**
     * @return list<string> paths relative to $dir
     */
    public function find(string $dir, int $limit = 20000): array
    {
        $prune = implode(' -o ', array_map(fn (string $name) => '-name '.Ssh::quote($name), self::SKIP));
        $script = 'cd -- '.Ssh::quote($dir)." && find . \\( {$prune} \\) -prune -o -type f -print 2>/dev/null | head -n {$limit}";

        return array_values(array_filter(array_map(fn (string $path) => substr($path, 2), explode("\n", $this->exec($script)))));
    }

    public function read(string $path): string
    {
        return $this->exec('cat -- '.Ssh::quote($path), raw: true);
    }

    public function exists(string $path): bool
    {
        return $this->ssh->exec($this->target->host, 'test -e '.Ssh::quote($path))->successful();
    }

    public function isDir(string $path): bool
    {
        return $this->ssh->exec($this->target->host, 'test -d '.Ssh::quote($path))->successful();
    }

    /**
     * A cheap fingerprint to notice the file changed on the server while it was being edited here.
     */
    public function checksum(string $path): string
    {
        return trim($this->exec('cksum < '.Ssh::quote($path).' 2>/dev/null || echo missing'));
    }

    public function latestLog(): ?string
    {
        $dir = $this->target->resolve('storage/logs');
        $name = trim($this->exec('cd -- '.Ssh::quote($dir).' 2>/dev/null && ls -t -- *.log 2>/dev/null | head -n 1 || true'));

        return $name === '' ? null : $dir.'/'.$name;
    }

    public function download(string $remote, string $local, bool $recursive = false): bool
    {
        return $this->ssh->download($this->target->host, $remote, $local, $recursive) === 0;
    }

    public function upload(string $local, string $remote): bool
    {
        return $this->ssh->upload($this->target->host, $local, $remote) === 0;
    }

    public function shell(string $dir): int
    {
        return $this->ssh->interactive($this->target->host, 'cd -- '.Ssh::quote($dir).' && exec "${SHELL:-/bin/sh}" -l');
    }

    public function interactive(string $script): int
    {
        return $this->ssh->interactive($this->target->host, $script);
    }

    private function exec(string $script, bool $raw = false): string
    {
        $result = $this->ssh->exec($this->target->host, $script);

        if ($result->failed()) {
            throw new RuntimeException("{$this->target->host}: ".($result->message() ?: "exit {$result->exitCode}"));
        }

        return $raw ? $result->output : trim($result->output);
    }
}
