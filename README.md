<p align="center">
  <a href="https://novamira.ai/">
    <img src="assets/banner.png" alt="Novamira" />
  </a>
</p>

<p align="center">
  <strong>Give your AI agents full access to your WordPress site. Nothing in between.</strong>
</p>

<p align="center">
  <a href="https://novamira.ai/">Website</a> ·
  <a href="https://novamira.ai/docs/">Documentation</a> ·
  <a href="https://novamira.ai/cli/">Novamira CLI</a> ·
  <a href="https://novamira.ai/video/">Videos</a> ·
  <a href="https://novamira.ai/download/">Download</a> ·
  <a href="https://discord.gg/novamira">Discord</a> ·
  <a href="https://www.facebook.com/groups/novamira">Facebook Community</a>
</p>

Novamira is an open-source WordPress plugin and MCP server that lets AI agents work directly inside WordPress. Agents can run PHP and WP-CLI commands, inspect the database, manage files, understand the active plugins and theme, and build working functionality against the real site.

The connection is direct between your AI client and your WordPress installation. Novamira is not a hosted proxy and your requests do not pass through Novamira servers.

> [!WARNING]
> For dev and staging environments. With backups. Always.

## Videos

See Novamira in real projects, tutorials, and walkthroughs from the WordPress community.

**[Watch Novamira videos →](https://novamira.ai/video/)**

## What Novamira provides

- Full WordPress access through PHP execution, including `$wpdb`, loaded plugins, themes, and WordPress APIs
- WP-CLI commands with foreground and background execution
- Filesystem inspection and editing, plus a recoverable sandbox for new PHP files
- Native Block Editor workflows, media uploads, reusable skills, agent context, and design guidance
- OAuth and WordPress Application Password authentication
- Compatibility with Claude, Codex, Cursor, Gemini CLI, Antigravity, VS Code with GitHub Copilot, and other MCP clients
- [Novamira CLI](https://novamira.ai/cli/) for connecting terminal-based coding agents such as Claude Code, Codex CLI, and Gemini CLI
- Novamira Visual, an experimental browser workspace where you can watch an agent work in WordPress

## Requirements

- WordPress 6.9 or later
- PHP 8.0 or later
- A WordPress administrator account
- HTTPS for remote connections; local development environments are supported without HTTPS
- An MCP-compatible AI client, or a terminal-based coding agent using Novamira CLI

## Get started

1. [Download the release ZIP](https://novamira.ai/download/).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the ZIP, and activate Novamira.
3. Open **Novamira → Configuration**, enable AI Abilities, and follow the instructions for your AI client and authentication method.

Use the release ZIP rather than GitHub's automatically generated source archive. The source archive does not include the bundled Composer dependencies required by the MCP server.

See the [quick start guide](https://novamira.ai/quickstart/) for the complete connection flow.

Using a terminal-based coding agent? [Novamira CLI](https://novamira.ai/cli/) provides a guided connection for Claude Code, Codex CLI, Gemini CLI, and other agents that can run shell commands.

## Read mode

Read mode lets an agent inspect a site through read abilities, with the abilities that change content, files and settings removed. It is production ready, and it is turned on with [Novamira Pro](https://novamira.ai/pro/). The **Novamira → Read Mode** page shows whether it is on, when it was last changed and by whom, and turns it off without needing Pro or a license; `wp novamira read-mode status` and `wp novamira read-mode disable` do the same from WP-CLI. Read mode stays on if Novamira Pro is deactivated or its license expires. While it is on, the Novamira admin-bar entry reads **Novamira · Read Mode** and links to the Read Mode page, and Novamira's admin pages, Chat included, show a notice. Ghost Mode hides all of these from administrators it hides Novamira from.

Read mode is set per site. The setting is read straight from the site's options table, so option filters and object or query caches cannot make it read as off, and a setting that cannot be read keeps read mode on.

While read mode is on:

- Only an allowlist of read abilities can be used, on every request and through every door (MCP, the Novamira REST runner, WordPress core's `wp-abilities/v1`, Novamira Chat, and Novamira Visual). Novamira ships `agent-context`, `discover-abilities`, `get-ability-info`, `execute-ability`, `read-file`, and `list-directory`; Novamira Pro adds its read abilities for posts, terms, users, comments, options, the database schema, and read-only SQL queries, which stay on. On the Read Mode page, an administrator can also allow the read abilities of the Novamira Pro specializations (on until turned off) and the read abilities other plugins declare (off until turned on). Everything else, including `execute-php`, WP-CLI, file writes, uploads, admin access links, skills, Novamira Design, and the Block Editor Queue abilities (including its block type reads), is unregistered or refused.
- The abilities read mode allows are the ones Novamira ships, the ones Novamira Pro adds, and the ones an administrator chooses on the Read Mode page. They are decided once, after all plugins have loaded, and an ability is allowed only when it is registered with exactly the implementation that is expected for it.
- A name alone is not enough: read mode checks that the ability is registered with exactly the callbacks expected for it, as the plain `WP_Ability` class, so an ability another plugin registers under an allowlisted name, re-registers, or changes with a `wp_register_ability_args` filter is refused.
- The abilities that run PHP or WP-CLI commands, `execute-php` and `run-wp-cli`, also refuse in read mode when they are called directly, without going through the registry. Every other ability relies on the checks all abilities share.
- An administrator can allow the abilities that other plugins declare `readonly`, a declaration any provider can make. The option is off by default, and Novamira Pro offers it on the Read Mode page. Turned on, every registered ability outside Novamira's own namespaces that is the plain `WP_Ability` class and carries `readonly` set to true is kept, without checking its callbacks. Novamira cannot verify those declarations, so allowing these abilities means trusting the plugin that registered them.
- The MCP tool list does not change: `discover-abilities`, `get-ability-info`, and `execute-ability` are listed whether read mode is on or off. `discover-abilities` and `get-ability-info` show only the allowed abilities, and `execute-ability` runs only those, refusing anything else with the read-mode message. Any other MCP tool, resource, or prompt on Novamira's MCP servers that is not backed by an allowed ability, including callable tools added through `novamira_mcp_adapter_default_server_config`, is removed before a request is handled.
- No skills are loaded and no skill prompts are registered: `agent-context` returns no skills.
- The agent's instructions contain only the read-mode guidance: the abilities it can use right now, built from the guidance line each allowed ability carries, what it cannot do, that an ability discovery does not list is unavailable because of read mode (not licensing) or, when only the abilities Novamira ships remain because Novamira Pro or its license is not active, that read mode cannot be used as intended and that an administrator can turn it off, and that it must not try to make changes by other means. The `novamira_discover_abilities_instructions` filter is not applied, so custom instructions and add-on additions are left out.
- Novamira Visual does not work: its workspace page is not served and says why, its backend tools refuse every request, every workspace method refuses, and its Gutenberg editor exposes no tool. A workspace that was open before read mode was turned on stops within a few seconds. After read mode is turned off, an editor that already had Novamira Visual open needs to be reloaded.
- Upload links and admin access links are refused, including links created before read mode was turned on.
- The Abilities Hub looks as usual but lists only the read abilities read mode allows: the ones it requires are marked and cannot be turned off, and each of the others can be turned off and on by itself, whatever feature owns it. The Features page shows a notice and cannot be changed: many of its features are not available while read mode is on. An MCP session that is already open over STDIO needs a restart to see a change in the Abilities Hub or in the groups chosen on the Read Mode page.
Read mode allows abilities that do not change a site's content, files or settings. Some of them may still change temporary data while they run, for example by clearing an expired transient, rebuilding a cache or a generated file, or updating a counter, the way WordPress and its plugins do on any request to the site, and other plugins can react to a read through their own hooks, for example by emptying a page cache. In Novamira, readonly means exactly this. The list of abilities allowed in read mode may change over time.

Read mode is not a security feature: it limits what Novamira offers an agent, not what an administrator can do. It does not stop a plugin written to defeat it: any plugin runs with the same privileges as Novamira and can remove its hooks. The agent still signs in as an administrator, and anyone with those credentials can do anything an administrator can, for example through wp-admin or the WordPress REST API. Read mode applies to Novamira: it does not turn off the other tools of the AI client, so a browser or another MCP server can still make changes if they are left enabled. Hooks other plugins attach to an allowed read, such as `wp_before_execute_ability`, still run and can have side effects. Sandbox plugins in `wp-content/novamira-sandbox/` keep running, and WordPress, Novamira, and other plugins still write their own bookkeeping (sessions, cron, transients, last-used timestamps) as usual. On a multisite network, read mode on one site does not stop an agent connected to another site, where read mode is off, from writing to the shared database.

## Documentation

- [Documentation](https://novamira.ai/docs/)
- [Getting started](https://novamira.ai/docs/getting-started/)
- [Novamira CLI](https://novamira.ai/cli/)
- [Videos](https://novamira.ai/video/)
- [Security and best practices](https://novamira.ai/docs/security/)
- [Connection troubleshooting](https://novamira.ai/docs/connection-troubleshooting/)
- [Changelog](CHANGELOG.txt)

## Community

- [Discord](https://discord.gg/novamira)
- [Facebook Community](https://www.facebook.com/groups/novamira)
- [GitHub Discussions](https://github.com/use-novamira/novamira/discussions)

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request. Every contribution must start with an approved issue.

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)
