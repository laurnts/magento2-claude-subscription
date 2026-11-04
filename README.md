# MagoAssistant Claude Subscription

## What it is

This module lets Mago use a Claude subscription. `claude` runs on the Mac, using the login from `claude auth login`. This module's host script listens on `127.0.0.1:3456`. Magento, inside the container, sends each turn to `http://host.docker.internal:3456/v1`.

The host speaks text chat only. The module turns Mago tool calls into `<mago_tool_call>` tags and turns tool results into user messages. Claude's own tools and MCP servers are off for that run.

## Host setup

From the Magento root:

```bash
claude auth login
php bin/magento claude:proxy:start
php bin/magento claude:proxy:stop
```

`--bind` and `--port` on start change the listen address and port. The default is `127.0.0.1` and `3456`.

In this shop `php bin/magento` runs inside the php container. That container cannot see the Mac login, so start and stop refuse there and print the host script. Run the printed line in a Mac terminal:

```bash
app/code/MagoAssistant/ClaudeSubscription/bin/claude-subscription start
app/code/MagoAssistant/ClaudeSubscription/bin/claude-subscription stop
```

`start` leaves the listener running after the terminal closes. The pid is `var/claude-subscription.pid` and the log is `var/log/claude-subscription.log`. `stop` ends that process. `claude` must be on `PATH` in the terminal that starts it (`~/.local/bin` is the usual place). The script does not run inside the `php` container, and Docker Compose does not start it. A reboot stops it. Log in again only when that Mac session is gone.

`HOST` and `PORT` change the bind address and port. The default is `127.0.0.1` and `3456`. `CLAUDE_BIN` overrides the `claude` executable. A request is stopped after 10 minutes.

Check it from the Mac with `curl http://127.0.0.1:3456/v1/models`. `host.docker.internal` does not resolve in a Mac terminal.

## Where Node and Claude run

The script starts `claude` on the same machine it is running on. A Node process in one place cannot use a `claude` login that lives in another.

**Claude on the Docker host.** This is the layout above. Node and `claude` both run on the Mac. The listener stays on `127.0.0.1:3456`. Magento, in the `php` container, uses Base URL `http://host.docker.internal:3456/v1`. Docker Desktop on a Mac forwards that name to the Mac's `127.0.0.1`, so the port does not need to be published in Compose and it is not open on the LAN. Log in with `claude auth login` in a Mac terminal.

**Node inside a container too.** Run the script in that container, and run `claude auth login` in that same container. The Mac login is not visible there: a Linux container cannot read the Mac Keychain, and the Mac `claude` binary does not run in it. Start the listener with `HOST=0.0.0.0` so other containers can open the port. `127.0.0.1` inside that container is only that container. Put the container on the same Compose network as `php` (`revproxy` in this project). Do not publish `3456` on `0.0.0.0` of the machine unless you want it reachable from the LAN. Set the Claude Subscription Base URL to `http://<that-service-name>:3456/v1`, for example `http://claude-subscription:3456/v1`. `host.docker.internal` is the wrong address for this layout, because the listener is no longer on the Mac.

## From the container

When Claude is on the Docker host, `http://host.docker.internal:3456/v1/models` must return JSON. On this Docker Desktop setup that hostname reaches a process bound to the Mac's `127.0.0.1`. On Linux the `php` container needs `extra_hosts: ["host.docker.internal:host-gateway"]`. `localhost` inside the `php` container is that container, not the host. The `php` service in this Compose file does not publish port 3456. When Node is in its own container, check `http://<that-service-name>:3456/v1/models` from the `php` container instead.

## Admin

```bash
bin/magento module:enable MagoAssistant_ClaudeSubscription
bin/magento setup:upgrade
```

There is no schema.

1. **Stores > Configuration > Mage-OS > AI Configuration.** Add a service and choose **Claude Subscription**. Base URL `http://host.docker.internal:3456/v1` when Claude is on the Docker host, or `http://<that-service-name>:3456/v1` when Node runs in a container on the `revproxy` network. Use the `/v1` root, not `/v1/chat/completions`. Choose a model. Save.
2. **Stores > Configuration > Mago Assistant > General > API Settings.** Set **AI Service** to that Claude Subscription row. Save.

The model list is **Opus**, **Fable**, **Sonnet**, and **Haiku**. The saved value is the alias passed to `claude --model`. The default is `sonnet`. Refresh Models asks the host for the same four names. Test Connection uses the same client.

A row saved earlier with a versioned id still runs: an id containing `opus`, `fable`, or `haiku` uses that family, and any other id uses `sonnet`. Choose one of the four aliases and save when the stored value should match the menu.

The Claude Subscription button is shown because this module registers a bridge marker. There is no Symfony package to install.

## What a chat does

Each message starts a new `claude --print` process and sends the whole conversation again. Claude does not keep a session between turns. Every Magento admin who can open Mago shares this one Claude account. Who may chat stays Mago's ACL.

Mago's max-tokens setting is not passed through to `claude`. Token counts on a reply are the counts Claude Code reports for that run, which include its own session overhead.

A personal `~/.claude` setup can still affect a reply. The Magento project's `CLAUDE.md` does not: each run starts in an empty temporary directory.

## Terms and data

Store text leaves the container for the configured URL and then goes to Anthropic through the host's Claude session. The host script runs `claude --print` and does not extract OAuth tokens. Anthropic's terms and fair-use rules apply. Claude's built-in tools are turned off (`--tools ""`), so a reply is text. Mago still asks an admin to confirm writes.

## Develop

From the Magento root:

```bash
php vendor/bin/phpunit -c app/code/MagoAssistant/ClaudeSubscription/phpunit.xml.dist
./vendor/bin/phpcs --standard=app/code/MagoAssistant/ClaudeSubscription/phpcs.xml app/code/MagoAssistant/ClaudeSubscription
```

Pinned references: Mago `6b0893addf3538d2a60da15a33257cc1df481099`, MageOS AI Base `b3a07623faf86665a915243a731f68c6da582607`. The listener is `bin/claude-subscription`. `claude` on this Mac was 2.1.285 when it was written.
