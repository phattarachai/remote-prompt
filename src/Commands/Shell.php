<?php

namespace Phattarachai\RemotePrompt\Commands;

class Shell extends Command
{
    public function __invoke(array $args): int
    {
        [$target, $path] = $this->context->target($args[0] ?? null);
        $dir = $target->resolve($path ?? '.');

        $this->context->step("ssh {$target->host}, cd {$dir}");

        return $this->context->remote($target)->shell($dir);
    }
}
