# ADR 0001: Guarantee parent products are imported before their variations

- **Status:** Accepted
- **Date:** 2026-06-15
- **Deciders:** Phillip Johnston

## Context

WooCommerce variable products are represented in the GitHub repository as three
coordinated pieces:

1. A **parent product** file (`products/<slug>.md`) with `product_type: variable`,
   an `attributes:` block, and a `variations:` list.
2. One **variation** file per child under
   `products/<slug>/variations/<child>.md`, each with `layout: product_variation`,
   `parent_slug: <slug>`, and `attribute_values:`.
3. A parent ↔ child relationship stored in WordPress as `post_parent` on the
   variation post.

On import (GitHub → WordPress), a variation's parent is resolved at save time by
looking up `parent_slug` against the database
(`Database::post_args()` → `get_page_by_path( $parent_slug, OBJECT, 'product' )`).

The importer (`Import::import_files()`) iterated the GitHub tree in the order
returned by `tree_recursive()`, with **no guarantee that the parent product was
imported before its variations**. When a variation was processed first:

- `get_page_by_path()` returned nothing,
- the variation was saved with `post_parent = 0` (parentless),
- WooCommerce treated the parentless variation as detached, effectively
  downgrading the product to a simple product, and
- on the next export, `Post::github_directory()` could not build a
  `products/<parent>/variations/` path for a parentless variation and wrote the
  file to the **repository root** as an orphan.

Those root-level orphan files (`plum-vinegar-12oz.md`, `plum-vinegar-32oz.md`,
`fruit-in-syrup-plums-spiced-brandy.md`) are not importable
(`importable_file()` only accepts known content prefixes) and are regenerated on
every export, so deleting them from git does not break the cycle. The underlying
cause is the missing import ordering guarantee, compounded by the export step
silently writing orphans.

Today the alphabetical tree order happens to place `products/<slug>.md` before
`products/<slug>/variations/...`, so the bug is latent for full-tree imports but
not structurally prevented. Single-file webhook pushes and forced re-imports can
still hit a variation before its parent exists in the same batch.

## Decision

Make parent-before-child ordering explicit rather than incidental, with a
defense-in-depth, two-layer approach in `Import::import_files()`:

1. **Sort the batch parents-first.** `sort_files_parents_first()` orders files by
   path depth (shallower first, stable otherwise), so `products/<slug>.md`
   precedes `products/<slug>/variations/<child>.md`.

2. **Defer and retry unresolved variations.** During the first pass, any blob that
   is a `product_variation` with a `parent_slug` whose parent does not yet exist
   in WordPress is collected into a deferred list. After the first pass completes
   (all parents imported), the deferred variations are retried. If a parent still
   cannot be resolved, the variation is **skipped and logged** instead of being
   saved parentless.

Separately, the export side (`Database::is_post_supported()`) now refuses to
export a `product_variation` with no parent, so a detached variation can never be
written to the repository root again.

## Consequences

### Positive

- Variable products survive a full GitHub → WordPress import regardless of the
  order the tree returns files in.
- A variation whose parent is genuinely absent is skipped (and logged) rather than
  silently orphaned, breaking the orphan-regeneration cycle.
- The export guard prevents new root-level orphan files even if a detached
  variation appears in WordPress by some other path.

### Negative / trade-offs

- `variation_parent_exists()` issues an extra `get_page_by_path()` lookup per
  variation blob. Negligible for this catalog size.
- A variation pushed **alone** (webhook) whose parent does not yet exist in
  WordPress is skipped on that push; it links on the next sync once the parent
  exists. This is the intended safe behavior, not a regression.
- Ordering relies on the established layout convention
  (`products/<parent>/variations/<child>.md`). A variation stored outside that
  convention would not be guaranteed to sort after its parent, though the deferred
  retry pass still covers it as long as both are in the batch.

## Notes

- Pre-existing parentless `product_variation` rows must be deleted **at the source
  in WordPress** (e.g. `wp post delete <id> --force` for posts returned by
  `wp post list --post_type=product_variation --post_parent=0`). The plugin
  intentionally does not delete posts in response to GitHub file deletions
  (`Import::import_files()`), so removing the `.md` files alone does not remove the
  underlying rows.

## Affected code

- `lib/import.php` — `import_files()`, `sort_files_parents_first()`,
  `is_variation_blob()`, `variation_parent_slug()`, `variation_parent_exists()`.
- `lib/database.php` — `is_post_supported()` (export guard).
- `lib/post.php` — `get_post_parent_id()` accessor.
