<?php

namespace Phattarachai\RemotePrompt;

final readonly class SshHost
{
    /**
     * @param  list<string>  $aliases
     */
    public function __construct(
        public array $aliases,
        public ?string $hostName = null,
        public ?string $user = null,
        public ?string $port = null,
        public ?string $identityFile = null,
        public ?string $proxyCommand = null,
    ) {}

    public function alias(): string
    {
        return $this->aliases[0];
    }

    public function answersTo(string $host): bool
    {
        return in_array($host, $this->aliases, true) || $this->hostName === $host;
    }

    public function viaGcloud(): bool
    {
        return str_contains((string) $this->proxyCommand, 'gcloud');
    }

    public function describe(): string
    {
        $where = ($this->user !== null ? $this->user.'@' : '').($this->hostName ?? $this->alias()).($this->port !== null && $this->port !== '22' ? ':'.$this->port : '');

        return $this->viaGcloud() ? "{$where} via IAP" : $where;
    }
}
