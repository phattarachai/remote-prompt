<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\Target;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

/**
 * `rh list` — this project's targets; `--all` every project; `--json` for scripts and agents.
 */
class ListTargets extends Command
{
    public function __invoke(array $args): int
    {
        $all = self::flag($args, '--all');
        $json = self::flag($args, '--json');
        $binding = $this->context->binding();

        $projects = $all ? $this->context->config->projects() : ($binding === null ? [] : [$binding->key => $binding->targets]);

        if ($json) {
            echo json_encode([
                'config' => Home::contract($this->context->config->file()),
                'project' => $binding?->key,
                'root' => $binding?->root,
                'projects' => array_map(fn (array $targets) => array_map(fn (Target $target) => $target->toArray(), $targets), $projects),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

            return 0;
        }

        if ($projects === []) {
            info($all ? 'No projects yet — run `rh import` or `rh add`.' : 'No targets for this folder — `rh add`, `rh import`, or `rh list --all`.');

            return 0;
        }

        $rows = [];

        foreach ($projects as $key => $targets) {
            foreach ($targets as $index => $target) {
                $rows[] = [$index === 0 ? $key : '', $target->name, $target->host, $target->path, $target->env];
            }
        }

        table(['Project', 'Target', 'Host', 'Path', 'Env'], $rows);

        return 0;
    }
}
