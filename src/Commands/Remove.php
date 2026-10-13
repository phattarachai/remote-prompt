<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\UserError;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class Remove extends Command
{
    public function __invoke(array $args): int
    {
        $binding = $this->context->binding() ?? throw new UserError('No targets for this folder.');
        $target = isset($args[0]) ? ($binding->find($args[0]) ?? throw new UserError("No target named {$args[0]} here.")) : $this->context->choose();

        if (! confirm("Remove {$target->name} from {$binding->key}?", default: false, hint: 'Only rh forgets it — nothing changes on the server or in ~/.ssh/config.')) {
            return 0;
        }

        $this->context->config->removeTarget($binding->key, $target->name);
        $this->context->config->save();
        $this->context->rebind();

        info("Removed {$target->name}.");

        return 0;
    }
}
