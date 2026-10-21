<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\Target;
use Phattarachai\RemotePrompt\UserError;

/**
 * `rh artisan [target] <command> [args…]` — php artisan in the deploy path, as the target's `run_as` user so new log
 * and cache files stay writable for the app. On production in a terminal only the read-only commands below run
 * without asking; anything else asks first, like any other write. Without a terminal every command runs as is.
 */
class Artisan extends Command
{
    public const array READ_ONLY = [
        'about', 'list', 'help', '--version', '-V',
        'route:list', 'event:list', 'schedule:list', 'migrate:status',
        'queue:failed', 'horizon:status', 'model:show', 'db:show', 'db:table',
    ];

    public function __invoke(array $args): int
    {
        $target = isset($args[0]) ? $this->context->binding()?->find($args[0]) : null;

        if ($target !== null) {
            array_shift($args);
        }

        if ($args === []) {
            throw new UserError('Usage: rh artisan [target] <command> [args…] — e.g. rh artisan uat queue:failed');
        }

        $target ??= $this->context->choose();

        if ($target->isProduction() && ! in_array($args[0], self::READ_ONLY, true) && ! $this->context->allowWrite($target, "run php artisan {$args[0]}")) {
            return 1;
        }

        $script = self::script($target, $args, Runner::interactive());

        if (Runner::interactive()) {
            $this->context->step("{$target->host}:{$target->path}  php artisan ".implode(' ', $args).($target->runAs !== null ? "  (as {$target->runAs})" : ''));

            return $this->context->remote($target)->interactive($script);
        }

        return $this->context->ssh->stream($target->host, $script);
    }

    /**
     * sudo -n without a terminal, so a missing sudoers rule fails at once instead of waiting for a password.
     *
     * @param  list<string>  $args
     */
    public static function script(Target $target, array $args, bool $interactive): string
    {
        $artisan = 'php artisan '.implode(' ', array_map(Ssh::quote(...), $args));
        $as = $target->runAs === null ? '' : 'sudo '.($interactive ? '' : '-n ').'-u '.Ssh::quote($target->runAs).' env HOME=/tmp ';

        return 'cd -- '.Ssh::quote($target->path).' && '.$as.$artisan;
    }
}
