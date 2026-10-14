<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class Remove extends Command
{
    public function __invoke(array $args): int
    {
        $binding = $this->context->binding() ?? throw new UserError('No targets for this folder.');
        $target = isset($args[0]) ? ($binding->find($args[0]) ?? throw new UserError("No target named {$args[0]} here.")) : $this->context->choose();

        $from = $binding->shared($target) ? Home::contract((string) $binding->file?->path) : $binding->key;

        if (! confirm("Remove {$target->name} from {$from}?", default: false, hint: 'Only rh forgets it — nothing changes on the server or in ~/.ssh/config.')) {
            return 0;
        }

        if ($binding->file !== null && $binding->shared($target)) {
            $binding->file->remove($target->name);
            $binding->file->save();
            info("Removed {$target->name} from {$from} — commit it.");
        } else {
            $this->context->config->removeTarget($binding->key, $target->name);
            $this->context->config->save();
            info("Removed {$target->name}.");
        }

        $this->context->rebind();

        return 0;
    }
}
