<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\Prompts\Cancelled;
use Phattarachai\RemotePrompt\Prompts\Menu;
use Phattarachai\RemotePrompt\Remote;
use Phattarachai\RemotePrompt\Target;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

/**
 * Walk the server's folders in a type-to-filter list. Enter opens a folder or a file's actions,
 * `..` goes up, Esc leaves.
 */
class Browse extends Command
{
    public function __invoke(array $args): int
    {
        [$target, $path] = $this->context->target($args[0] ?? null);

        $this->browse($target, $target->resolve($path ?? '.'));

        return 0;
    }

    public function browse(Target $target, string $dir): void
    {
        $remote = $this->context->remote($target);

        while (true) {
            try {
                $entries = spin(fn () => $remote->list($dir), "Listing {$dir}");
            } catch (\RuntimeException $exception) {
                error($exception->getMessage());

                if ($dir === '/' || $dir === '.') {
                    return;
                }

                $dir = Target::normalize(dirname($dir));

                continue;
            }

            $options = ['find' => '⌕  Find a file under here…', 'shell' => '❯  Shell here', 'download' => '⤓  Download this folder'];

            if ($dir !== '/') {
                $options['up'] = '../';
            }

            foreach ($entries as $index => $entry) {
                $options["e{$index}"] = $entry->label();
            }

            try {
                $choice = Menu::choose("{$target->name}  {$dir}", $options, 'Type to filter · Enter open · Esc leave');
            } catch (Cancelled) {
                return;
            }

            match (true) {
                $choice === 'up' => $dir = Target::normalize(dirname($dir)),
                $choice === 'find' => $this->find($remote, $dir),
                $choice === 'shell' => $remote->shell($dir),
                $choice === 'download' => $this->download($remote, $dir, true),
                $entries[(int) substr($choice, 1)]->isDir => $dir = Target::normalize($dir.'/'.$entries[(int) substr($choice, 1)]->name),
                default => $this->file($remote, $dir.'/'.$entries[(int) substr($choice, 1)]->name),
            };
        }
    }

    private function find(Remote $remote, string $dir): void
    {
        $files = spin(fn () => $remote->find($dir), "Collecting files under {$dir}");

        if ($files === []) {
            info('No files.');

            return;
        }

        try {
            $choice = Menu::choose("Find under {$dir}  (".count($files).' files)', array_combine(array_map(fn (int $index) => "f{$index}", array_keys($files)), $files));
        } catch (Cancelled) {
            return;
        }

        $this->file($remote, Target::normalize($dir.'/'.$files[(int) substr($choice, 1)]));
    }

    public function file(Remote $remote, string $path, ?string $action = null): void
    {
        $target = $remote->target;
        $options = ['view' => 'View', 'download' => 'Download to '.Home::contract($this->context->cwd), 'edit' => 'Edit'];

        if ($this->localCopy($target, $path) !== null) {
            $options['compare'] = 'Compare with local';
        }

        $options['copy'] = 'Copy path';

        try {
            $choice = $action ?? Menu::choose($path, $options, 'Enter pick · Esc back');
        } catch (Cancelled) {
            return;
        }

        try {
            match ($choice) {
                'view' => (new Cat($this->context))(["{$target->name}:{$path}"]),
                'download' => $this->download($remote, $path, false),
                'edit' => (new Edit($this->context))(["{$target->name}:{$path}"]),
                'compare' => (new Compare($this->context))([(string) $this->localCopy($target, $path), "{$target->name}:{$path}"]),
                default => $this->copy($path),
            };
        } catch (UserError $exception) {
            error($exception->getMessage());
        }
    }

    private function download(Remote $remote, string $path, bool $recursive): void
    {
        $this->context->step('scp '.($recursive ? '-r ' : '')."{$remote->target->host}:{$path} → ".Home::contract($this->context->cwd));

        $remote->download($path, $this->context->cwd, $recursive)
            ? info('Downloaded to '.Home::contract($this->context->cwd.'/'.basename($path)))
            : error('Download failed.');
    }

    private function localCopy(Target $target, string $path): ?string
    {
        $binding = $this->context->binding();

        if ($binding === null || $binding->find($target->name) === null || ! str_starts_with($path, rtrim($target->path, '/').'/')) {
            return null;
        }

        $local = $binding->root.substr($path, strlen(rtrim($target->path, '/')));

        return is_file($local) ? $local : null;
    }

    private function copy(string $path): void
    {
        $this->context->runner()->run(['pbcopy'], $path)->successful()
            ? info("Copied {$path}")
            : $this->context->say($path);
    }
}
