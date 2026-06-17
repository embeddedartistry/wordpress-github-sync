# ADR 0002: Reference product images by attachment ID, not URL

- **Status:** Accepted
- **Date:** 2026-05-02
- **Deciders:** Phillip Johnston

## Context

WooCommerce products are represented in the GitHub repository as markdown files
with a frontmatter `meta:` block. Product images (the featured image and gallery)
were originally exported as URLs:

- `featured_image_url` — the result of `wp_get_attachment_url( $thumb_id )`.
- `gallery_image_urls` — an array of attachment URLs.

This had a fundamental limitation, recorded directly in the export code's own
comment: images were **"export-only; ignored on import."** URLs round-tripped out
to git but were never read back in. The reason is that a URL is not a stable,
resolvable handle to a WordPress attachment:

- On **import** (GitHub → WordPress) there is no clean, reliable way to turn an
  arbitrary URL back into the correct attachment. A reverse lookup by URL is
  brittle: URLs vary by image size, CDN host, and site domain, and a match is not
  guaranteed. So the importer simply ignored image fields, and product images
  could only ever be set in WP admin — never restored from git.
- WordPress already keys the image association by **attachment ID** internally
  (`_thumbnail_id`, `_product_image_gallery`). The URL was a lossy projection of a
  value that the data model stores as an ID.

The remaining obstacle to using IDs was authoring ergonomics: a human editing a
product markdown file in git cannot tell what attachment ID `4127` refers to.

## Decision

Switch product image frontmatter from URL-based fields to ID-based fields, and add
a discovery aid so the IDs are usable when authoring offline.

1. **Store attachment IDs.** Export writes `featured_image_id` (int) and
   `gallery_image_ids` (array of ints) instead of the URL fields. Import resolves
   them directly: `set_post_thumbnail( $post_id, $thumb_id )` for the featured
   image and `_product_image_gallery` for the gallery. IDs missing from the local
   media library are logged and skipped rather than guessed at.

2. **Publish a `media-index.json`.** A single repo-root file maps every attachment
   ID to its source URL, file path, mime type, alt text, and title. It is rebuilt
   and pushed at most once per request (on `shutdown`, after `add_attachment`,
   `attachment_updated`, or `delete_attachment`), and only when the rendered
   content differs from what is already in the repo. A `wp wghs
   media_index_rebuild` CLI command seeds it initially. This lets an author pull
   the index, look up the right ID, and reference it in frontmatter.

## Consequences

### Positive

- Product images **round-trip**: a product imported from git now has its featured
  image and gallery restored, instead of image fields being silently dropped.
- IDs match WordPress's own internal representation, so resolution is direct and
  unambiguous — no fragile URL reverse-lookup.
- `media-index.json` keeps IDs human-discoverable, and auto-syncs on media changes
  so it stays current without manual maintenance.

### Negative / trade-offs

- IDs are **site-specific**. The frontmatter is no longer portable to a different
  WordPress install where the same images carry different attachment IDs. URLs
  shared this fragility in practice (domain/CDN coupling), but it is now explicit.
- An ID present in git but absent from the target media library produces no image
  (logged and skipped). The image must be uploaded to WP first; the index then
  surfaces its ID.
- Adds a per-request index rebuild + potential push on media changes. Bounded to at
  most one commit per request and skipped when unchanged.

## Affected code

- `lib/post.php` — export writes `featured_image_id` / `gallery_image_ids`.
- `lib/database.php` — import resolves IDs via `set_post_thumbnail` and
  `_product_image_gallery`.
- `lib/media-index.php` — builds and pushes `media-index.json`.
- `lib/cli.php` — `wp wghs media_index_rebuild` seed command.
- `wordpress-github-sync.php` — media hook wiring.
