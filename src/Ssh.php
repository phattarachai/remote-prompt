<?php

namespace Phattarachai\RemotePrompt;

/**
 * ssh and scp with one shared connection per host: the first call opens a control master
 * that later calls reuse for ten minutes, so a slow handshake (an IAP tunnel) is paid once.
 */
final class Ssh
{
    public function __construct(public readonly Runner $runner) {}

    /**
     * @return list<string>
     */
    public static function options(): array
    {
        return ['-o', 'ControlMaster=auto', '-o', 'ControlPath='.Home::path('.ssh/rh-%C'), '-o', 'ControlPersist=10m'];
    }

    public function exec(string $host, string $script, ?string $input = null): Result
    {
        return $this->runner->run(['ssh', ...self::options(), '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', $host, $script], $input);
    }

    public function interactive(string $host, string $script): int
    {
        return $this->runner->tty(['ssh', ...self::options(), '-o', 'LogLevel=ERROR', '-t', $host, $script]);
    }

    public function download(string $host, string $remote, string $local, bool $recursive = false): int
    {
        return $this->runner->tty(['scp', ...self::options(), '-p', ...($recursive ? ['-r'] : []), "{$host}:{$remote}", $local]);
    }

    public function upload(string $host, string $local, string $remote): int
    {
        return $this->runner->tty(['scp', ...self::options(), '-p', $local, "{$host}:{$remote}"]);
    }

    public static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
