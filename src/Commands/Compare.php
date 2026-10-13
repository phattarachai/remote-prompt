<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Diff;
use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\info;

/**
 * Local file against its deployed copy — PHPStorm's "Compare with Deployed Version".
 */
class Compare extends Command
{
    public function __invoke(array $args): int
    {
        if (! isset($args[0])) {
            throw new UserError('Usage: rh diff <local-file> [target[:remote-path]]');
        }

        $local = self::localPath($this->context->cwd, $args[0]);
        [$target, $path] = $this->context->target($args[1] ?? null);
        $remotePath = $path !== null ? $target->resolve($path) : $this->context->binding()?->remotePath($target, $local);

        if ($remotePath === null) {
            throw new UserError(Home::contract($local).' is outside the project — give the remote path.');
        }

        $remote = $this->context->remote($target);
        $deployed = $remote->exists($remotePath) ? $remote->read($remotePath) : '';

        if (! Diff::show($this->context->runner(), $deployed, (string) file_get_contents($local), "{$target->name}:{$remotePath}", Home::contract($local))) {
            info("Identical to {$target->name}.");
        }

        return 0;
    }
}
