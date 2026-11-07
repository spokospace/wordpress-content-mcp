=== Spoko Content MCP ===
Contributors: spokospace
Tags: mcp, ai, gutenberg, blocks, abilities
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: mcp-adapter
Stable tag: 2.0.1
License: MIT
License URI: https://opensource.org/license/mit

Lets AI agents read and edit posts block by block over MCP, with a hash check, a dry run and a revision on every save.

== Description ==

Spoko Content MCP gives AI agents (Claude, Cursor or any other MCP client) safe, block-level tools for WordPress content. It runs on the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) and the Abilities API that ships with WordPress 6.9.

An agent that rewrites a whole post to change one paragraph can break blocks it never meant to touch, or overwrite an edit someone made a minute ago. This plugin gives it smaller, safer steps:

* **Edit one block, not the whole post.** Blocks are addressed by a path (for example `12.0.3`), so a change replaces, inserts, moves or deletes exactly that block and leaves the rest of the post byte for byte.
* **No lost edits.** Every read returns a content hash and every write needs it. If the post changed in the meantime, the write is refused.
* **Look before you save.** Every write takes `dry_run`.
* **Always a way back.** Every save is a normal post update with a revision.
* **Valid blocks only.** Before saving, the new content is checked for broken block structure; if the check fails, nothing is saved.

= Tools =

* `find-posts`: search posts and pages by title, slug or ID.
* `get-outline`: the block tree with paths, names and text previews, plus the content hash.
* `get-blocks`: the markup of blocks by path.
* `validate-post`: report broken block structure.
* `find-media`: search the media library.
* `replace-block`, `insert-blocks`, `delete-block`, `move-blocks`, `unwrap-block`: edit blocks by path.
* `link-images`: link every unlinked image of a post to its full-size file, so a lightbox can open it.

Every tool runs with the capabilities of the WordPress user the agent signs in as (`edit_post` for the post, `upload_files` for the media library). The plugin adds no settings screen and stores no data of its own.

= Headless sites =

If your frontend serves the uploads under its own address, `link-images` can link there instead of to WordPress. Set the address in `wp-config.php`:

`define( 'SPOKO_CONTENT_MCP_MEDIA_BASE', 'https://example.com/images' );`

or with the `spoko_content_mcp_media_base` filter. Older media hosts to rewrite as well can be added with the `spoko_content_mcp_media_aliases` filter.

Development happens on [GitHub](https://github.com/spokospace/wordpress-content-mcp). Made by [spoko.space](https://spoko.space).

== Installation ==

1. Install and activate the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin.
2. Install and activate Spoko Content MCP.
3. Create a WordPress user for the agent and an application password for it (Users > Profile > Application Passwords).
4. Point your MCP client at `https://your-site/wp-json/spoko/content`, for example with `@automattic/mcp-wordpress-remote`, using that user and application password.

== Frequently Asked Questions ==

= Which MCP clients work with it? =

Any client that can reach a WordPress MCP Adapter server, for example Claude (desktop and Claude Code) or Cursor through `@automattic/mcp-wordpress-remote`.

= Can the agent change posts it should not? =

No more than the WordPress user it signs in as. Give that user a role that fits, for example Editor or Author.

= How do I undo a change? =

Every save creates a normal revision. Restore it from the block editor (Revisions), or note the `revision_id` the tool returns.

= Does it work with the classic editor? =

The tools work on block content. Classic (freeform) content is reported as such and left alone.

== Changelog ==

= 2.0.1 =
* One endpoint: `/wp-json/spoko/content`.

= 2.0.0 =
* First public release.
