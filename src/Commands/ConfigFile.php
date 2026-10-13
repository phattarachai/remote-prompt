<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;

class ConfigFile extends Command
{
    public function __invoke(array $args): int
    {
        $file = $this->context->config->file();

        if (! is_file($file)) {
            $this->context->config->save();
        }

        $this->context->step('editing '.Home::contract($file));

        return $this->context->runner()->tty([...Edit::editor(), $file]);
    }
}
