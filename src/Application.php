<?php

namespace Phattarachai\RemotePrompt;

use Composer\InstalledVersions;
use Phattarachai\RemotePrompt\Commands\Add;
use Phattarachai\RemotePrompt\Commands\Browse;
use Phattarachai\RemotePrompt\Commands\Cat;
use Phattarachai\RemotePrompt\Commands\Command;
use Phattarachai\RemotePrompt\Commands\Compare;
use Phattarachai\RemotePrompt\Commands\ConfigFile;
use Phattarachai\RemotePrompt\Commands\Doctor;
use Phattarachai\RemotePrompt\Commands\Edit;
use Phattarachai\RemotePrompt\Commands\Get;
use Phattarachai\RemotePrompt\Commands\Import;
use Phattarachai\RemotePrompt\Commands\ListTargets;
use Phattarachai\RemotePrompt\Commands\Panel;
use Phattarachai\RemotePrompt\Commands\Put;
use Phattarachai\RemotePrompt\Commands\Remove;
use Phattarachai\RemotePrompt\Commands\Shell;
use Phattarachai\RemotePrompt\Commands\Tail;
use Phattarachai\RemotePrompt\Prompts\Cancelled;
use Phattarachai\RemotePrompt\Prompts\Escape;
use RuntimeException;

use function Laravel\Prompts\error;

class Application
{
    /**
     * @var array<string, array{0: class-string<Command>, 1: string, 2: string}>
     */
    private const array COMMANDS = [
        'ssh' => [Shell::class, '[target[:path]]', 'Shell on the server, already in the deploy path'],
        'browse' => [Browse::class, '[target[:path]]', 'Browse folders; view, download, edit or compare a file'],
        'get' => [Get::class, '<target:path> [local]', 'Download a file or folder (default: here)'],
        'put' => [Put::class, '<file> [target[:path]]', 'Upload a file to its mapped path — diffed first'],
        'cat' => [Cat::class, '<target:path>', 'Read a remote file in the server\'s pager'],
        'edit' => [Edit::class, '<target:path>', 'Edit a remote file in $EDITOR, diff, then save back'],
        'diff' => [Compare::class, '<file> [target[:path]]', 'Compare a local file with its deployed copy'],
        'tail' => [Tail::class, '[target[:file]]', 'tail -F the latest storage/logs/*.log'],
        'list' => [ListTargets::class, '[--all] [--json]', 'This project\'s targets (or every project)'],
        'add' => [Add::class, '', 'Add a target to this project'],
        'remove' => [Remove::class, '[target]', 'Remove a target from this project'],
        'import' => [Import::class, '[folder] [--yes]', 'Import PHPStorm Deployment servers'],
        'doctor' => [Doctor::class, '[--all]', 'Connect to every target and check its folder'],
        'config' => [ConfigFile::class, '', 'Open the config file in $EDITOR'],
    ];

    public function __construct(private readonly ?Runner $runner = null) {}

    /**
     * @param  list<string>  $arguments  argv without the script name
     */
    public function run(array $arguments, string $cwd): int
    {
        $command = $arguments[0] ?? '';

        if (in_array($command, ['-V', '--version'], true)) {
            echo 'remote-prompt '.self::version().PHP_EOL;

            return 0;
        }

        if (in_array($command, ['-h', '--help', 'help'], true) || ($command === '' && ! Runner::interactive()) || ($command !== '' && ! isset(self::COMMANDS[$command]))) {
            $this->help();

            return isset(self::COMMANDS[$command]) || in_array($command, ['', '-h', '--help', 'help'], true) ? 0 : 1;
        }

        Escape::install();

        try {
            $context = Context::make($cwd, $this->runner);
            $class = $command === '' ? Panel::class : self::COMMANDS[$command][0];

            return (new $class($context))(array_slice($arguments, 1));
        } catch (Cancelled) {
            return 0;
        } catch (RuntimeException $exception) {
            error($exception->getMessage());

            return 1;
        }
    }

    private function help(): void
    {
        $lines = [
            'remote-prompt '.self::version().' — your servers through a search box.',
            '',
            'Usage:',
            '  rh                              Pick a target of this project, then an action',
        ];

        foreach (self::COMMANDS as $name => [, $arguments, $description]) {
            $lines[] = sprintf('  rh %-28s %s', trim("{$name} {$arguments}"), $description);
        }

        array_push(
            $lines,
            '',
            'A target is a name from `rh list`; a relative path is relative to its deploy path:',
            '  rh get prod:storage/logs/laravel.log    rh put app/Models/User.php uat    rh cat prod:.env',
            'Any ssh alias works too, with an absolute path: rh get my-box:/etc/nginx/nginx.conf',
            '',
            'Connections come from ~/.ssh/config. Targets live in '.Home::contract(Home::configFile()).'.',
            'Uploads and edits on a production target ask first, and are refused without a terminal.',
            'Keys: type to filter every list · Enter pick · Esc back · Ctrl+C quit',
        );

        echo implode(PHP_EOL, $lines).PHP_EOL;
    }

    private static function version(): string
    {
        $package = 'phattarachai/remote-prompt';

        if (! InstalledVersions::isInstalled($package) || InstalledVersions::getRootPackage()['name'] === $package) {
            return 'dev';
        }

        return (string) InstalledVersions::getPrettyVersion($package);
    }
}
