<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\PhpStorm;
use Phattarachai\RemotePrompt\Prompts\Cancelled;
use Phattarachai\RemotePrompt\Prompts\Menu;
use Phattarachai\RemotePrompt\Prompts\TabbedSearch;
use Phattarachai\RemotePrompt\Target;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;

/**
 * `rh` with no arguments, one screen in three tabs: Targets (Enter opens the target's shell), Actions (asks which
 * target when there are several, the last one used first) and Setup. A shell or a log tail quits rh when it ends;
 * everything else comes back to the same tab.
 */
class Panel extends Command
{
    private const array ACTIONS = [
        'browse' => 'Browse files',
        'find' => 'Find a file…',
        'download' => 'Download a file…',
        'upload' => 'Upload a local file…',
        'compare' => 'Compare a local file with the server…',
        'env' => 'View .env',
        'edit-env' => 'Edit .env',
        'tail' => 'Tail the latest log',
    ];

    private int $tab = 0;

    private ?string $last = null;

    public function __invoke(array $args): int
    {
        while (true) {
            $targets = $this->context->binding()->targets ?? [];

            $this->context->say($targets === []
                ? '  <fg=yellow>No targets for '.Home::contract($this->context->cwd).' yet.</>'
                : '  <options=bold>'.$this->context->binding()?->key.'</>');

            $tabs = $this->tabs($targets);
            $active = min($this->tab, count($tabs) - 1);
            $search = new TabbedSearch(
                tabs: $tabs,
                active: $active,
                hint: $targets === [] ? 'Type to filter · Enter pick · Esc quit' : 'Enter on a target opens its shell · Tab switch tab · Esc quit',
                initial: $active === 0 && $targets !== [] ? $this->lastIndex($targets) : 0,
            );

            try {
                $choice = (string) $search->prompt();
            } catch (Cancelled) {
                return 0;
            } finally {
                $this->tab = $search->activeTab();
                unset($search);
            }

            [$kind, $value] = explode(':', $choice, 2);

            try {
                match ($kind) {
                    'target' => $this->shell($targets[(int) $value]),
                    'action' => $this->action($value, $this->pick($targets)),
                    default => $this->setup($value),
                };
            } catch (Cancelled) {
                continue;
            } catch (UserError|\RuntimeException $exception) {
                error($exception->getMessage());
            }

            if ($kind === 'target' || $choice === 'action:tail') {
                return 0;
            }
        }
    }

    /**
     * @param  list<Target>  $targets
     * @return list<array{title: string, entries: array<string, array{label: string, search: string}>}>
     */
    private function tabs(array $targets): array
    {
        $entries = fn (string $kind, array $options) => array_combine(
            array_map(fn (int|string $key) => "{$kind}:{$key}", array_keys($options)),
            array_map(fn (string $label) => ['label' => $label, 'search' => $label], $options),
        );

        $setup = ['doctor' => 'Test every connection', 'add' => 'Add a target'];

        if ($targets !== []) {
            $setup['remove'] = 'Remove a target';
        }

        if (PhpStorm::projects($this->context->binding()->root ?? $this->context->cwd) !== []) {
            $setup['import'] = 'Import from PHPStorm';
        }

        $setup += ['list' => 'List every project', 'config' => 'Edit the config file'];

        if ($targets === []) {
            return [['title' => 'Setup', 'entries' => $entries('setup', $setup)]];
        }

        return [
            ['title' => 'Targets', 'entries' => $entries('target', Target::labels($targets))],
            ['title' => 'Actions', 'entries' => $entries('action', self::ACTIONS)],
            ['title' => 'Setup', 'entries' => $entries('setup', $setup)],
        ];
    }

    private function shell(Target $target): void
    {
        $this->last = $target->name;

        (new Shell($this->context))([$target->name]);
    }

    /**
     * @param  list<Target>  $targets
     */
    private function pick(array $targets): Target
    {
        if (count($targets) === 1) {
            return $targets[0];
        }

        $options = [];

        foreach (Target::labels($targets) as $index => $label) {
            $options["t{$index}"] = $label;
        }

        $target = $targets[(int) substr(Menu::choose('Which target?', $options, default: 't'.$this->lastIndex($targets)), 1)];
        $this->last = $target->name;

        return $target;
    }

    /**
     * @param  list<Target>  $targets
     */
    private function lastIndex(array $targets): int
    {
        foreach ($targets as $index => $target) {
            if ($target->name === $this->last) {
                return $index;
            }
        }

        return 0;
    }

    private function action(string $action, Target $target): void
    {
        $this->context->say("  {$target->label()}");

        $spec = "{$target->name}:";
        $browse = new Browse($this->context);

        match ($action) {
            'browse' => $browse->browse($target, $target->path),
            'find' => $this->find($browse, $target, null),
            'download' => $this->find($browse, $target, 'download'),
            'upload' => (new Put($this->context))([$this->localFile('Upload which file?'), $target->name]),
            'compare' => (new Compare($this->context))([$this->localFile('Compare which file?'), $target->name]),
            'env' => (new Cat($this->context))([$spec.'.env']),
            'edit-env' => (new Edit($this->context))([$spec.'.env']),
            default => (new Tail($this->context))([$target->name]),
        };
    }

    private function setup(string $item): void
    {
        $command = match ($item) {
            'doctor' => new Doctor($this->context),
            'add' => new Add($this->context),
            'remove' => new Remove($this->context),
            'import' => new Import($this->context),
            'list' => new ListTargets($this->context),
            default => new ConfigFile($this->context),
        };

        try {
            $command($item === 'list' ? ['--all'] : []);
        } finally {
            $this->context->config->reload();
            $this->context->rebind();
        }
    }

    private function find(Browse $browse, Target $target, ?string $action): void
    {
        $remote = $this->context->remote($target);
        $files = spin(fn () => $remote->find($target->path), "Collecting files on {$target->name}");
        $choice = Menu::choose("Find on {$target->name}  (".count($files).' files)', array_combine(array_map(fn (int $index) => "f{$index}", array_keys($files)), $files));

        $browse->file($remote, Target::normalize($target->path.'/'.$files[(int) substr($choice, 1)]), $action);
    }

    /**
     * Tracked and untracked files plus ignored ones like .env — `--directory` folds vendor/ and node_modules/
     * into one entry each, which is dropped, as are framework caches. Outside git, find with the same skips.
     *
     * @return list<string>
     */
    public function localFiles(string $root): array
    {
        $git = ['git', '-C', $root, 'ls-files', '--exclude-standard'];
        $listed = $this->context->runner()->run([...$git, '--cached', '--others']);

        $lines = $listed->successful()
            ? [...explode("\n", $listed->output), ...explode("\n", $this->context->runner()->run([...$git, '--others', '--ignored', '--directory'])->output)]
            : explode("\n", $this->context->runner()->run(['find', $root, '(', '-name', '.git', '-o', '-name', 'vendor', '-o', '-name', 'node_modules', ')', '-prune', '-o', '-type', 'f', '-print'])->output);

        $files = array_filter(
            array_map(fn (string $line) => str_starts_with($line, $root.'/') ? substr($line, strlen($root) + 1) : $line, $lines),
            fn (string $file) => $file !== ''
                && ! str_ends_with($file, '/')
                && basename($file) !== '.DS_Store'
                && ! str_starts_with($file, 'storage/framework/')
                && ! str_starts_with($file, 'bootstrap/cache/'),
        );

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function localFile(string $label): string
    {
        $root = $this->context->binding()->root ?? $this->context->cwd;
        $files = $this->localFiles($root);

        if ($files === []) {
            throw new UserError('No files found in '.Home::contract($root).'.');
        }

        $choice = Menu::choose($label, array_combine(array_map(fn (int $index) => "f{$index}", array_keys($files)), $files));

        return $root.'/'.$files[(int) substr($choice, 1)];
    }
}
