<?php

namespace Phattarachai\RemotePrompt;

use Phattarachai\RemotePrompt\Prompts\Menu;
use Phattarachai\RemotePrompt\Prompts\Output;

use function Laravel\Prompts\confirm;

/**
 * What every command needs: where it runs, the config, ssh, and how to turn "prod:storage/logs" into a target and a path.
 */
final class Context
{
    private ?Binding $binding = null;

    private bool $bound = false;

    public function __construct(
        public readonly string $cwd,
        public readonly Config $config,
        public readonly SshConfig $sshConfig,
        public readonly Ssh $ssh,
    ) {}

    public static function make(string $cwd, ?Runner $runner = null): self
    {
        return new self($cwd, Config::load(), SshConfig::load(), new Ssh($runner ?? new Runner));
    }

    public function runner(): Runner
    {
        return $this->ssh->runner;
    }

    public function binding(): ?Binding
    {
        if (! $this->bound) {
            $this->binding = $this->config->bind($this->cwd);
            $this->bound = true;
        }

        return $this->binding;
    }

    public function rebind(): void
    {
        $this->bound = false;
    }

    public function remote(Target $target): Remote
    {
        return new Remote($this->ssh, $target);
    }

    /**
     * "name:path", "name", "path", or nothing (pick one). A prefix that is no target here but an
     * ssh alias reaches any host ad hoc; such a target counts as production.
     *
     * @return array{0: Target, 1: ?string}
     */
    public function target(?string $spec, bool $pathOnly = false): array
    {
        $spec = (string) $spec;
        $binding = $this->binding();

        if (str_contains($spec, ':')) {
            [$name, $path] = explode(':', $spec, 2);

            $target = $binding?->find($name) ?? ($this->sshConfig->find($name) !== null || $binding === null ? new Target($name, $name, '.') : null);

            if ($target !== null) {
                return [$target, $path === '' ? null : $path];
            }
        }

        if ($spec !== '' && ! $pathOnly && ($target = $binding?->find($spec)) !== null) {
            return [$target, null];
        }

        return [$this->choose(), $spec === '' ? null : $spec];
    }

    public function choose(): Target
    {
        $targets = $this->binding()->targets ?? [];

        if ($targets === []) {
            throw new UserError('No targets for '.Home::contract($this->cwd).' — run `rh add` or `rh import`.');
        }

        if (count($targets) === 1) {
            return $targets[0];
        }

        if (! Runner::interactive()) {
            throw new UserError('Several targets here — name one: '.implode(', ', array_map(fn (Target $target) => $target->name, $targets)).'.');
        }

        $options = [];

        foreach (Target::labels($targets) as $index => $label) {
            $options["t{$index}"] = $label;
        }

        return $targets[(int) substr(Menu::choose('Target', $options), 1)];
    }

    /**
     * Writes to production need a person at the keyboard: confirmed in a terminal, refused from scripts and agents.
     */
    public function allowWrite(Target $target, string $action): bool
    {
        if (! $target->isProduction()) {
            return ! Runner::interactive() || confirm("{$action} on {$target->name}?", default: true);
        }

        if (! Runner::interactive()) {
            throw new UserError("Refusing to {$action} on production target {$target->name} without a terminal — run it yourself.");
        }

        return confirm("{$action} on PRODUCTION ({$target->name})?", default: false, hint: "{$target->host}:{$target->path}");
    }

    public function say(string $line = ''): void
    {
        Output::writeln($line);
    }

    public function step(string $line): void
    {
        Output::writeln('<fg=gray>  → '.$line.'</>');
    }
}
