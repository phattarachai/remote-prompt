<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Diff;
use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\Temp;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\info;

/**
 * Pull a remote file into a private temp file, open $VISUAL / $EDITOR, then diff, confirm and write it back
 * in place (keeping the file's owner and mode). Refuses to overwrite a file that changed on the server meanwhile.
 */
class Edit extends Command
{
    public function __invoke(array $args): int
    {
        [$target, $path] = $this->context->target($args[0] ?? null, pathOnly: true);

        if ($path === null) {
            throw new UserError('Usage: rh edit <target:path>');
        }

        if (! Runner::interactive()) {
            throw new UserError('rh edit needs a terminal.');
        }

        $file = $target->resolve($path);
        $remote = $this->context->remote($target);

        if (! $remote->exists($file)) {
            throw new UserError("{$file} does not exist on {$target->name}.");
        }

        $original = $remote->read($file);
        $checksum = $remote->checksum($file);
        $local = Temp::file(basename($file));
        file_put_contents($local, $original);

        $this->context->step("editing {$target->host}:{$file}");
        $this->context->runner()->tty([...self::editor(), $local]);

        $edited = (string) file_get_contents($local);

        if (! Diff::show($this->context->runner(), $original, $edited, "{$target->name}:{$file}", 'edited')) {
            info('No changes.');

            return 0;
        }

        if (! $this->context->allowWrite($target, 'Save '.basename($file))) {
            info('Not saved.');

            return 0;
        }

        if ($remote->checksum($file) !== $checksum) {
            throw new UserError("{$file} changed on {$target->name} while you were editing — not saved. Your copy: ".Home::contract(Temp::keep($local)));
        }

        $result = $this->context->ssh->exec($target->host, 'cat > '.Ssh::quote($file), $edited);

        if ($result->failed()) {
            throw new UserError('Save failed: '.$result->message().' — your copy: '.Home::contract(Temp::keep($local)));
        }

        info("Saved {$target->name}:{$file}");

        return 0;
    }

    /**
     * @return list<string>
     */
    public static function editor(): array
    {
        $editor = trim((string) (getenv('VISUAL') ?: getenv('EDITOR') ?: 'vi'));

        return preg_split('/\s+/', $editor) ?: ['vi'];
    }
}
