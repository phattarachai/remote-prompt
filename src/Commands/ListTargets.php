<?php

namespace Phattarachai\RemotePrompt\Commands;

use Phattarachai\RemotePrompt\Home;
use Phattarachai\RemotePrompt\ProjectFile;
use Phattarachai\RemotePrompt\Target;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

/**
 * `rh list` — this project's targets; `--all` every project on this machine; `--json` for scripts and agents.
 * Each target says where it comes from: the project's .rh.json, or this machine's config.
 */
class ListTargets extends Command
{
    public function __invoke(array $args): int
    {
        $all = self::flag($args, '--all');
        $json = self::flag($args, '--json');
        $binding = $this->context->binding();

        $projects = $all ? $this->context->config->projects() : [];

        if ($binding !== null) {
            $projects[$binding->key] = $binding->targets;
        }

        $source = fn (string $key, Target $target) => $binding !== null && $key === $binding->key && $binding->shared($target) ? ProjectFile::NAME : 'machine';

        if ($json) {
            $list = [];

            foreach ($projects as $key => $targets) {
                $list[$key] = array_map(fn (Target $target) => [...$target->toArray(), 'source' => $source($key, $target)], $targets);
            }

            echo json_encode([
                'config' => Home::contract($this->context->config->file()),
                'project' => $binding?->key,
                'root' => $binding?->root,
                'file' => $binding?->file === null ? null : Home::contract($binding->file->path),
                'projects' => $list,
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
                $rows[] = [$index === 0 ? $key : '', $target->name, $target->host, $target->path, $target->env, $target->runAs ?? '', $source($key, $target)];
            }
        }

        table(['Project', 'Target', 'Host', 'Path', 'Env', 'Runs as', 'From'], $rows);

        return 0;
    }
}
