<?php

use Phattarachai\RemotePrompt\Remote;
use Phattarachai\RemotePrompt\Ssh;
use Phattarachai\RemotePrompt\Target;

beforeEach(function () {
    file_in($this->server, 'app/User.php', '<?php');
    file_in($this->server, '.env', "APP_KEY=secret\n");
    file_in($this->server, 'vendor/x/y.php');
    file_in($this->server, 'storage/logs/laravel-2026-10-01.log', 'old');
    file_in($this->server, "name with 'quote'.txt", 'q');
    touch($this->server.'/storage/logs/laravel-2026-10-01.log', time() - 60);
    file_in($this->server, 'storage/logs/laravel-2026-10-02.log', 'new');

    $this->remote = new Remote(new Ssh($this->runner), new Target('qas', 'qas', $this->server, 'qas'));
});

it('lists folders first, with sizes for files', function () {
    $entries = $this->remote->list($this->server);

    expect(array_map(fn ($entry) => $entry->label(), $entries))->sequence(
        fn ($label) => $label->toBe('app/'),
        fn ($label) => $label->toBe('storage/'),
        fn ($label) => $label->toBe('vendor/'),
        fn ($label) => $label->toStartWith('.env   15 B'),
        fn ($label) => $label->toStartWith("name with 'quote'.txt   1 B"),
    );
});

it('finds files without descending into vendor', function () {
    expect($this->remote->find($this->server))->toContain('app/User.php', '.env')
        ->not->toContain('vendor/x/y.php');
});

it('reads files, notices changes and finds the newest log', function () {
    $file = $this->server."/name with 'quote'.txt";
    $before = $this->remote->checksum($file);
    file_put_contents($file, 'changed');

    expect($this->remote->read($this->server.'/.env'))->toBe("APP_KEY=secret\n")
        ->and($this->remote->checksum($file))->not->toBe($before)
        ->and($this->remote->latestLog())->toBe($this->server.'/storage/logs/laravel-2026-10-02.log')
        ->and(fn () => $this->remote->list($this->server.'/missing'))->toThrow(RuntimeException::class);
});
