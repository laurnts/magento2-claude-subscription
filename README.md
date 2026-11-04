# Claude Subscription for MageOS AiBase

## What it is

This module is a MageOS AiBase provider that uses a Claude subscription, so an AiBase consumer such as Mago can chat through it. A small Node host runs `claude --print` on a machine where `claude auth login` was done and serves it over HTTP. Magento sends each turn to that host as an OpenAI-style chat completion.

The host speaks text chat only. The module turns tool calls into `<mago_tool_call>` tags and turns tool results into user messages. Claude's own tools and MCP servers are off for that run.

## Host setup

The host is a Node script. It runs wherever `node` and `claude` are, never in the `php` container, and `bin/magento` has no command for it. From this module's directory:

```bash
claude auth login
bin/claude-subscription start
bin/claude-subscription stop
```

`start` leaves the listener running after the terminal closes. The script finds the Magento root above it and writes the pid to `var/claude-subscription.pid` and the log to `var/log/claude-subscription.log` there. `stop` ends that process. Without `start` or `stop` the script runs the listener in the foreground. `claude` must be on `PATH` in the shell that starts it (`~/.local/bin` is the usual place). Docker Compose does not start it. A reboot stops it. Log in again only when that login is gone.

`--host` and `--port`, or the `HOST` and `PORT` variables, change the bind address and port. The default is `127.0.0.1` and `3456`. `CLAUDE_BIN` overrides the `claude` executable. A request is stopped after 10 minutes.

Check it on the host with `curl http://127.0.0.1:3456/v1/models`. It returns the four model aliases as JSON.

## Where Node and Claude run

The script starts `claude` on the same machine it is running on. A Node process in one place cannot use a `claude` login that lives in another.

**Claude on the Docker host.** This is the layout above. Node and `claude` both run on the host machine. The listener stays on `127.0.0.1:3456`. Magento, in the `php` container, uses Base URL `http://host.docker.internal:3456/v1`. Docker Desktop forwards that name to the host's `127.0.0.1`, so the port does not need to be published in Compose and it is not open on the LAN. On Linux the `php` container needs `extra_hosts: ["host.docker.internal:host-gateway"]`.

**Node inside a container.** Run the script in that container, and run `claude auth login` in that same container; a login on the host is not visible there. Start the listener with `HOST=0.0.0.0` so other containers can open the port, since `127.0.0.1` inside a container is only that container. Put the container on the same Compose network as `php`. Do not publish `3456` on `0.0.0.0` of the machine unless you want it reachable from the LAN. Set the Claude Subscription Base URL to `http://<that-service-name>:3456/v1`, for example `http://claude-subscription:3456/v1`. `host.docker.internal` is the wrong address for this layout, because the listener is no longer on the host.

Either way, the Base URL must answer from the `php` container: `curl http://host.docker.internal:3456/v1/models` or `curl http://<that-service-name>:3456/v1/models` run inside it returns JSON. `localhost` inside the `php` container is that container, not the host.

## Install

Clone or copy the module into `app/code/MagoAssistant/ClaudeSubscription` of a shop that has [MageOS AiBase](https://github.com/mage-os/mageos-ai-base) installed, then from the Magento root:

```bash
bin/magento module:enable MagoAssistant_ClaudeSubscription
bin/magento setup:upgrade
bin/magento cache:flush
```

There is no schema and no data patch. The package is not on Packagist yet; `composer require` needs this repository added as a VCS repository first.

## Admin

1. **Stores > Configuration > Mage-OS > AI Configuration.** Add a service and choose **Claude Subscription**. Base URL `http://host.docker.internal:3456/v1` when Claude is on the Docker host, or `http://<that-service-name>:3456/v1` when Node runs in a container. Use the `/v1` root, not `/v1/chat/completions`. Choose a model. Save.
2. **Stores > Configuration > Mago Assistant > General > API Settings.** Set **AI Service** to that Claude Subscription row. Save.

The model list is **Opus**, **Fable**, **Sonnet**, and **Haiku**. The saved value is the alias passed to `claude --model`. The default is `sonnet`. Refresh Models asks the host for the same four names. Test Connection uses the same client.

A row saved earlier with a versioned id still runs: an id containing `opus`, `fable`, or `haiku` uses that family, and any other id uses `sonnet`. Choose one of the four aliases and save when the stored value should match the menu.

The Claude Subscription row is offered because this module registers a bridge marker with AiBase. There is no Symfony AI package to install: a plugin on AiBase's client factory builds the client for this provider and leaves every other provider alone.

## What a chat does

Each message starts a new `claude --print` process and sends the whole conversation again. Claude does not keep a session between turns. Every Magento admin who can open Mago shares this one Claude account. Who may chat stays Mago's ACL.

Mago's max-tokens setting is not passed through to `claude`. Token counts on a reply are the counts Claude Code reports for that run, which include its own session overhead.

A personal `~/.claude` setup on the host can still affect a reply. The Magento project's `CLAUDE.md` does not: each run starts in an empty temporary directory.

## Terms and data

Store text leaves the container for the configured URL and then goes to Anthropic through the host's Claude session. The host script runs `claude --print` and does not extract OAuth tokens. Anthropic's terms and fair-use rules apply. Claude's built-in tools are turned off (`--tools ""`), so a reply is text. Mago still asks an admin to confirm writes.

## Develop

PHPUnit and phpcs come from the Magento install the module sits in. From the Magento root:

```bash
php vendor/bin/phpunit -c app/code/MagoAssistant/ClaudeSubscription/phpunit.xml.dist
php vendor/bin/phpcs --standard=app/code/MagoAssistant/ClaudeSubscription/phpcs.xml app/code/MagoAssistant/ClaudeSubscription
```

The unit tests need nothing generated: the two factories Magento would generate are stubbed under `Test/Unit/Fakes` when `generated/code` does not hold them.

Written against MageOS AI Base `b3a07623faf86665a915243a731f68c6da582607` and Mago `6b0893addf3538d2a60da15a33257cc1df481099`. The module depends on AI Base only; Mago is the consumer it was built for. The listener is `bin/claude-subscription`; `claude` was 2.1.285 when it was written.
