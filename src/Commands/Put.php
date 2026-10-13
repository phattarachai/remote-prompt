<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Diff;
use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\Target;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Upload one local file. Without a remote path it lands where the project's mapping puts it,
 * like PHPStorm's "Upload to…". An existing remote file is diffed first.
 */
class Put extends Command
{
    public function __invoke(array $args): int
    {
        if (! isset($args[0])) {
            throw new UserError('Usage: rh put <local-file> [target[:remote-path]]');
        }

        $local = self::localPath($this->context->cwd, $args[0]);

        if (! is_file($local)) {
            throw new UserError(Home::contract($local).' is not a file — rh put uploads one file at a time.');
        }

        [$target, $path] = $this->context->target($args[1] ?? null);
        $remotePath = $path !== null ? $target->resolve($path) : $this->context->binding()?->remotePath($target, $local);

        if ($remotePath === null) {
            throw new UserError(Home::contract($local).' is outside the project — give the remote path: rh put <file> '.$target->name.':<path>');
        }

        return $this->upload($target, $local, $remotePath) ? 0 : 1;
    }

    public function upload(Target $target, string $local, string $remotePath): bool
    {
        $remote = $this->context->remote($target);

        $this->context->step(Home::contract($local)." → {$target->host}:{$remotePath}");

        if ($remote->exists($remotePath)) {
            $changed = Diff::show($this->context->runner(), $remote->read($remotePath), (string) file_get_contents($local), "{$target->name}:{$remotePath}", Home::contract($local));

            if (! $changed) {
                info('Already identical on '.$target->name.' — nothing to upload.');

                return true;
            }
        } else {
            warning("{$remotePath} does not exist on {$target->name} yet — it will be created.");
        }

        if (! $this->context->allowWrite($target, 'Upload '.basename($local))) {
            return false;
        }

        if (! $remote->upload($local, $remotePath)) {
            throw new UserError("Upload to {$remotePath} failed.");
        }

        info("Uploaded to {$target->name}:{$remotePath}");

        return true;
    }
}
