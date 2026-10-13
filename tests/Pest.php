<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Phattarachai\RemotePrompt\Application;
use Phattarachai\RemotePrompt\Commands\Add;
use Phattarachai\RemotePrompt\Runner;
use Phattarachai\RemotePrompt\Tests\FakeRunner;
use Symfony\Component\Console\Output\BufferedOutput;

Runner::$interactive = false;
Add::$startDirs = [];

uses()->beforeEach(function () {
    $this->cwd = (string) getcwd();
    $this->home = tempDir('home');
    $this->server = tempDir('server');
    $this->runner = new FakeRunner;

    putenv("HOME={$this->home}");
    putenv('XDG_CONFIG_HOME');
    putenv('VISUAL');
    putenv('EDITOR');
    mkdir($this->home.'/.ssh', 0700);
})->afterEach(function () {
    Runner::$interactive = false;
    Add::$startDirs = [];
    chdir($this->cwd);

    foreach (tempDir() as $dir) {
        exec('rm -rf '.escapeshellarg($dir));
    }
})->in(__DIR__);

/**
 * A fresh folder under the system temp dir (resolved, so /private/var on macOS); with no name, every folder made so far.
 *
 * @return ($name is null ? list<string> : string)
 */
function tempDir(?string $name = null): string|array
{
    static $made = [];

    if ($name === null) {
        [$all, $made] = [$made, []];

        return $all;
    }

    $dir = sys_get_temp_dir().'/rh-test-'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $made[] = (string) realpath($dir);
}

/**
 * Run `rh` with the fake runner; returns the exit code and everything printed.
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function rh(array $arguments = [], ?string $cwd = null, ?Runner $runner = null): array
{
    $output = new BufferedOutput;
    Prompt::setOutput($output);

    ob_start();
    $code = (new Application($runner ?? test()->runner))->run($arguments, $cwd ?? sys_get_temp_dir());
    $echoed = (string) ob_get_clean();

    return [$code, $echoed.$output->fetch()];
}

/**
 * @param  array<string, list<array{name: string, host: string, path: string, env?: string}>>  $projects
 */
function writeConfig(array $projects): void
{
    $dir = getenv('HOME').'/.config/rh';
    is_dir($dir) || mkdir($dir, 0700, true);

    file_put_contents($dir.'/config.json', json_encode(['projects' => array_map(fn (array $targets) => ['targets' => $targets], $projects)]));
}

function file_in(string $dir, string $path, string $content = ''): string
{
    $file = $dir.'/'.$path;
    is_dir(dirname($file)) || mkdir(dirname($file), 0700, true);
    file_put_contents($file, $content);

    return $file;
}

/**
 * Fake the terminal; each string is typed character by character, Key constants are pressed as-is.
 *
 * @param  list<string>  $inputs
 */
function answer(array $inputs): void
{
    $keys = [];

    foreach ($inputs as $input) {
        array_push($keys, ...(str_starts_with($input, "\e") || in_array($input, [Key::ENTER, Key::SPACE, Key::BACKSPACE], true) ? [$input] : mb_str_split($input)));
    }

    Prompt::fake($keys);
    Runner::$interactive = true;
}
