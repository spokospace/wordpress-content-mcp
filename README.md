# spoko-blocks-mcp

WordPress plugin **Polo Blocks MCP** (`polo-blocks-mcp.php`). Lets AI agents read and edit the
Gutenberg blocks of posts and pages through the [MCP Adapter](https://github.com/WordPress/mcp-adapter)
(WordPress Abilities API).

## Where it runs

- **Site:** blog.polo.blue (Plugins screen: "Polo Blocks MCP").
- **The plugin is not in the `polo-blue/blog.polo.blue` repo.** That repo has no copy of
  this plugin; this repo is the only source.
- **Endpoint:** `/wp-json/polo/blocks` (MCP server `polo-blocks`). MCP clients show the tools as
  `polo-blocks-<name>`, e.g. `polo-blocks-get-outline`.
- **Requires:** WordPress 6.9+, PHP 7.4+, the `mcp-adapter` plugin.
- **Permissions:** the logged-in user's own WordPress capabilities (`edit_post` for the post,
  `upload_files` for find-media).

## How it works

- A block is addressed by its **path**: indexes into the parsed block tree joined with dots.
  `12` is the 13th top-level block, `12.0.3` the 4th inner block of its 1st inner block. Blank
  separators between blocks take up an index too, so top-level blocks are usually 0, 2, 4…
  Get paths from `get-outline`.
- **Every write needs `expected_hash`**, the `content_hash` from the latest read. If the post
  changed in the meantime, the write is refused (`stale_hash`), so a stale edit never
  overwrites newer changes.
- **Every write takes `dry_run`**: validate and return the result without saving.
- **Every write is a normal post update**, so WordPress keeps a revision (returned as
  `revision_id`) that can be restored from the editor. This is the backup.
- Before saving, the new content is checked for broken block structure (unbalanced block
  comments, stray HTML between blocks). If the check fails, nothing is saved.

## Tools

| Tool | Writes | What it does |
|---|---|---|
| `find-posts` | no | Search posts/pages by title text, slug or ID. |
| `get-outline` | no | Block tree with paths, names and text previews, plus `content_hash`. |
| `get-blocks` | no | Serialized markup of blocks by path, plus `content_hash`. |
| `validate-post` | no | Report broken block structure. |
| `find-media` | no | Search the media library; IDs, URLs, image sizes. |
| `replace-block` | yes | Replace the block at a path with new markup. |
| `insert-blocks` | yes | Insert blocks before/after a path. |
| `delete-block` | yes | Delete the block at a path. |
| `move-blocks` | yes | Move consecutive sibling blocks next to another block, markup unchanged. |
| `unwrap-block` | yes | Replace a container (e.g. group) with its inner blocks. |
| `link-images` | yes | Link every unlinked image of a post to its full-size file (since 1.2.0). |

### link-images

Written for the lightbox on polo.blue, which only opens images that have a link
(`.wp-block-image a[href]`, `.wp-block-gallery a[href]`). Images added with "Link to: None"
have no `<a>`, so the lightbox skips them.

For every `core/image` block in the post, galleries and columns included:

- **Links it:** sets `linkDestination: "media"` and wraps the `<img>` in `<a href="…">`, the same
  markup the editor saves for "Link to: Media file". The file is `wp_get_attachment_url()` of
  the block's `id`, which for large uploads is the `-scaled` file. If there is no attachment, it
  uses the `src` without its `-WxH` size suffix.
- **Public address, no subdomain:** the `href` is always `https://polo.blue/images/YYYY/MM/<file>`
  (`PUBLIC_MEDIA_BASE`). URLs under the uploads base URL (`https://blog.polo.blue/images`) and the
  old `https://img.polo.blue` host are rewritten to it; other hosts are left as they are.
- **Leaves it alone** when it already has a link outside the caption (never a double `<a>`).
- **Skips it** with a reason in `skipped`: `no-lightbox` (the figure has that class; this is the
  opt-out), `full file unknown` (no attachment and no size suffix), `unexpected markup`, `no img`.
- Changes nothing else in the post. Running it again on a post that is already done saves
  nothing.

Typical use:

1. `get-outline` → `content_hash`.
2. `link-images` with `dry_run: true` → check `linked` (path and href) and `skipped`.
3. `link-images` with the same `expected_hash` → saved, `revision_id` is the backup.

Out of scope: images that are not `core/image` blocks, such as images in tables, in classic
(freeform) content or in `core/html`.

After content changes, polo.blue (static) has to be rebuilt before they show there.

## Tests

`tests/link-images.php` runs `link-images` against WordPress's real block parser and serializer
with stubs for the rest (no database needed). Pass a WordPress checkout and the plugin file:

```bash
php tests/link-images.php /path/to/wordpress polo-blocks-mcp.php
```

It ends with `ALL PASS` (exit 0) or `FAILURES` (exit 1).

## Changelog

- **1.2.1**: `link-images` writes links on the public address `https://polo.blue/images/…` instead of
  `blog.polo.blue` / `img.polo.blue`.
- **1.2.0**: `link-images` tool. Write flow split into a shared `save()` step.
- **1.1.0**: version running on blog.polo.blue when this repo was created.
