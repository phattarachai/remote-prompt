# Remote Prompt

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/remote-prompt.svg?style=flat-square)](https://packagist.org/packages/phattarachai/remote-prompt)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/remote-prompt/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/remote-prompt/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/remote-prompt/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/remote-prompt/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/remote-prompt/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/remote-prompt)
![Built on Laravel Prompts](https://img.shields.io/badge/built%20on-Laravel%20Prompts-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/remote-prompt.svg?style=flat-square)](https://packagist.org/packages/phattarachai/remote-prompt)

A PHPStorm-style remote host panel for the terminal. Type `rh` inside a project, pick one of its servers, and open
a shell already in the deploy path, browse files, download a backup or upload a file. Connections come from your own
`~/.ssh/config`, so keys, ssh-agent, jump hosts and `ProxyCommand` tunnels work as they do for plain `ssh`. Built on
[Laravel Prompts](https://github.com/laravel/prompts), but it is a standalone CLI that works in any project, Laravel
or not, from one global install.

```
~/www/shop-api ❯ rh
  ~/www/shop-api
 ┌  Targets   Actions   Setup  ─────────────────────────────┐
 │ › qas    qas:/var/www/shop           qas                 │
 │   uat    shop-uat:/var/www/uat-shop  uat                 │
 │   prod   prod:/var/www/shop          ● production        │
 └──────────────────────────────────────────────────────────┘
  Enter on a target opens its shell · Tab switch tab · Esc quit
```

- **Targets per project, committed.** A project's targets live in `.rh.json` at its root, so every checkout,
  worktree, machine and AI agent knows where it is deployed. A server can serve any number of projects; targets only
  you need stay in your own config.
- **Your ssh config is the source of truth.** A target only names an ssh alias. Hosts rh creates go to
  `~/.ssh/config.d/rh.conf`, so plain `ssh`, `scp` and `git` know them too. No passwords are stored anywhere.
- **One connection per host.** rh opens an ssh control master that later calls reuse for ten minutes, so browsing
  through a slow tunnel (GCP IAP, a jump host) pays the handshake once.
- **Production asks first.** In a terminal, uploads, edits and artisan commands on a `production` target wait for a
  yes. Without a terminal (a script, an AI agent) they run as on any other env — the agent asks its person first.

## Installation

```bash
composer global require phattarachai/remote-prompt
```

Make sure Composer's global `vendor/bin` is on your `PATH`, then run `rh --help`.

## Getting started

Coming from PHPStorm? Import its Deployment servers — one project, or every project under a folder:

```bash
cd ~/www && rh import
```

Each server's path becomes its root path joined with the mapping's deployment path. Its host is matched to an
existing `~/.ssh/config` alias by host name, so tunnels and keys you already set up keep working; unknown hosts get
a new alias in `rh.conf`. Then check every connection:

```bash
rh doctor --all
```

Or add a target by hand inside a project with `rh add`: pick an ssh alias, then walk the server's folders from
`/var/www` — folders named like the project are starred on top. When the app's files belong to another user
(`www-data`), rh offers it as the target's `run_as`. Inside a repository the target goes to `.rh.json` — commit it.

A server you have never connected to: pick *New host…*. rh writes the alias to `~/.ssh/config.d/rh.conf`, tries
your key, and when the server refuses it offers `ssh-copy-id` — you type the server's password once, into ssh.

Scripts and agents add targets without questions:

```bash
rh add --host=shop-prod --path=/var/www/shop-uat [--name=shop-uat] [--env=uat] [--run-as=www-data] \
       [--save=project|machine] [--project=~/www/shop] [--force]
```

The name and environment default to what the interactive flow would pick; a folder that doesn't exist on the
host is refused unless `--force`.

## Commands

| Command | |
|---|---|
| `rh` | One screen, three tabs: *Targets* (Enter opens that target's shell), *Actions* (browse, find, download, upload, compare, `.env`, log — then which target), *Setup* (test, add, remove, import, config) |
| `rh ssh [target[:path]]` | Shell on the server, already in the deploy path |
| `rh browse [target[:path]]` | Walk folders; view, download, edit or compare a file; find any file below |
| `rh get <target:path> [local]` | Download a file or folder (default: the current folder) |
| `rh put <file> [target[:path]]` | Upload a file to the path the project maps it to — diffed first |
| `rh cat <target:path>` | Read a file in the server's pager — `.env` never touches your disk |
| `rh edit <target:path>` | Edit in `$EDITOR`, see the diff, save back in place |
| `rh diff <file> [target]` | Compare a local file with its deployed copy |
| `rh tail [target[:file]]` | `tail -F` the newest `storage/logs/*.log` |
| `rh artisan [target] <command…>` | `php artisan` in the deploy path, as the target's `run_as` user |
| `rh list [--all] [--json]` | This project's targets (and where each comes from), or every project |
| `rh add` / `rh remove` / `rh config` | Manage targets, or edit the machine config file |
| `rh doctor [--all]` | Connect to every target and check its folder; IAP hosts check `gcloud` login first; prints the ssh config block for an alias this machine lacks |

A relative path is relative to the target's deploy path: `rh get prod:storage/logs/laravel.log`. Any ssh alias works
ad hoc with an absolute path: `rh get backup-box:/backups/db.sql.gz`. An alias that isn't one of the project's
targets counts as `production`, so writes to it ask first.

On production, `rh artisan` runs read-only commands (`about`, `route:list`, `migrate:status`, `schedule:list`,
`queue:failed`, `horizon:status`, `model:show`, `db:show`, …) as is; anything else asks first in a terminal, and runs
as is without one. With `run_as` set it runs `sudo -u <user> env HOME=/tmp php artisan …`, so new log and cache files stay
writable for the app (without a terminal `sudo -n`, which fails at once rather than waiting for a password). Without a
terminal the output passes through as it arrives, so a script or an agent can follow a long import or migration.

## Configuration

### `.rh.json` — the project's targets, committed

At the root of the repository, so every checkout, worktree and machine — and any AI agent working in the project —
knows where it is deployed:

```json
{
    "targets": [
        { "name": "qas",  "host": "shop-qas",  "path": "/var/www/shop", "env": "qas",        "run_as": "www-data" },
        { "name": "prod", "host": "shop-prod", "path": "/var/www/shop", "env": "production", "run_as": "www-data" }
    ],
    "hosts": {
        "shop-qas":  { "gcp_iap": { "instance": "shop-qas-vm", "project": "acme", "zone": "asia-southeast1-b" } },
        "shop-prod": { "hostname": "203.0.113.10", "user": "deploy" }
    }
}
```

`host` is an alias in `~/.ssh/config`, which keeps the connection itself (keys, users, proxies) per machine. Use the
same alias names on every machine. `hosts` is optional: it tells `rh doctor` how to write the block for an alias a
machine doesn't have yet (`gcp_iap` rides Identity-Aware Proxy with gcloud's key). It holds no secrets.

Inside a repository, `rh add` writes here (`--save=machine` keeps a target to this machine instead).

### `~/.config/rh/config.json` — this machine's projects

For folders without a `.rh.json`, and for targets only you need. A project's `.rh.json` targets come first; this
file's targets for the same folder are added after them, unless the names clash:

```json
{
    "projects": {
        "~/www/shop-api": {
            "targets": [
                { "name": "qas",  "host": "qas",  "path": "/var/www/shop", "env": "qas" },
                { "name": "prod", "host": "prod", "path": "/var/www/shop", "env": "production" }
            ]
        },
        "~/www/shop-*": {
            "targets": [{ "name": "qas", "host": "qas", "path": "/var/www/shop-web", "env": "qas" }]
        }
    }
}
```

`env` is one of `production`, `uat`, `qas`, `staging`, `dev`; a target without one is `production`. In a terminal,
`production` asks before writes with a default-no prompt. `run_as` (optional) is the user `rh artisan` switches to.

Background calls (listing, reading, checks) run ssh in batch mode, so a host that only takes a password works for
them only while a `rh ssh` session's shared connection is open — `ssh-copy-id <alias>` once fixes that for good.

## ผู้พัฒนา

พัฒนาและดูแลโดย [บริษัท ภัทรชัย อาร์ทิซาน จำกัด](https://phattarachai.dev) (Phattarachai Artisan)
บริษัทที่ปรึกษาและพัฒนาเว็บ ที่เรียนรู้และแบ่งปันกับชุมชน Laravel
แพ็กเกจนี้แบ่งปันให้ชุมชนนำไปใช้และต่อยอดได้อย่างอิสระ

ดูแพ็กเกจอื่นของเราได้ที่ [phattarachai.dev/open-source](https://phattarachai.dev/open-source)
และติดต่อเราได้ที่ [phattarachai.dev](https://phattarachai.dev/#contact)
