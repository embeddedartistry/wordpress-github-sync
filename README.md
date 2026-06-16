# Embedded Artistry: Wordpress-GitHub Sync Plugin

**License:** GPLv2  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.html  

## Description

This WordPress plugin allows you writing on GitHub (or Jekyll site). This was forked from [litefeel/writing-on-github](https://github.com/litefeel/writing-on-github) and contains modifications specific to the Embedded Artistry website.

### Plugin Purpose

This plugin provides the following services to our organization:

1. Posts of specified types are automatically exported to the associated GitHub repository upon creation
2. Edits to a post on the Wordpress front-end will be synchronized to GitHub
3. Changes made in the GitHub repository will be synchronized with the Wordpress front-end.

Specified types:

- post
- page
- courses
- lessons
- newsletters
- Field Atlas entries
- Glossary entries
- WooCommerce products and product variations

Caveats:

- Posts cannot be deleted on the front-end by removing files from the repository (but see [Deleting a post](#deleting-a-post) for the explicit, opt-in mechanism)
- Posts cannot be added to the front-end by adding new files with the appropriate metadata header to the repository (unlike the litefeel version)

These limitations are in place to prevent front-end problems, whether due to a bad commit or due to the need for complex 

## Deleting a post

Removing a file from the repository deliberately does **not** delete its WordPress post, so an accidental or bad commit cannot wipe production content. To delete a post, add a truthy `deleted` field to the file's frontmatter and let it sync:

```yaml
---
ID: 1234
post_title: Obsolete Article
deleted: true
---
```

On import the plugin resolves the target post -- by `ID`, then by the stored `_wghs_github_path`, then by the filename slug -- and removes it. The post is **trashed** (a reversible operation) by default. To permanently delete instead, hook the `wghs_force_delete` filter:

```php
add_filter( 'wghs_force_delete', '__return_true' );
```

If no matching post is found the marker is treated as a no-op, so re-running an import is safe. The file itself can remain in the repo as a tombstone, or be removed in the same commit.


## How the Plugin Works

The sync action is based on two web hooks:

1. A per-post sync fired in response to WordPress's `save_post` hook which pushes content to GitHub
2. A sync of all changed files triggered by GitHub's `push` web hook (outbound API call)

The plugin runs the import/export path as the configured user (set in the Wordpress front-end settings page for this plugin). This user must have the appropriate permissions to access the relevant content.

## WooCommerce Products

Products and product variations are synced bidirectionally alongside posts. Files land at:

```
products/<slug>.md                                       # simple, external, variable parent
products/<parent-slug>/variations/<variation-slug>.md    # variations of a variable product
```

The frontmatter captures product configuration: identity (`product_type`, `sku`, `featured`, `catalog_visibility`), pricing (`regular_price`, `sale_price`, sale dates, tax fields), inventory configuration (`manage_stock`, `stock_status`, `backorders`, `sold_individually`), shipping (`weight`, dimensions, `shipping_class`), type flags (`virtual`, `downloadable`), external-product fields (`product_url`, `button_text`), taxonomies (`product_categories`, `product_tags`), and an `attributes` block for both global (`pa_*`) and local attributes. Variable parents include a `variations:` index listing the slugs of their child files. Variation files include a `parent_slug` reference and an `attribute_values` map.

### What is intentionally not synced

- Order-driven runtime state: `_stock` / `_stock_quantity`, `total_sales`, and the derived `_price`. These mutate every time an order is placed; excluding them keeps the repo from churning. WooCommerce recomputes `_price` from `regular_price` / `sale_price` on import.
- Downloadable file metadata: `_download_files`, `_download_limit`, `_download_expiry`. File paths are environment-specific.
- Cross-product references: `_upsell_ids`, `_crosssell_ids`. Attachment IDs do not round-trip cleanly when used purely for navigation.
- Reviews: `_wc_average_rating`, `_wc_review_count`. Reviews are user-generated state, not configuration.

### Caveats

- WooCommerce code paths that bypass `save_post` (most of the REST API and some bulk-edit flows) will not trigger an export. Edits made through the standard product-editor UI sync as expected.
- Global attribute taxonomies (`pa_*`) are not auto-created on import. If a referenced `pa_*` taxonomy does not exist on the target site, the attribute is downgraded to a local attribute and a warning is logged.

## Media Library Index

Image references in product frontmatter use attachment IDs (`featured_image_id`, `gallery_image_ids`). To make those IDs human-discoverable when you are authoring a product file in git, the plugin maintains a `media-index.json` file at the root of the repo. Each entry maps an attachment ID to its source URL, file path, mime type, alt text, and title:

```json
{
  "4271": {
    "url": "https://embeddedartistry.com/wp-content/uploads/2024/03/foo.jpg",
    "file": "2024/03/foo.jpg",
    "mime": "image/jpeg",
    "alt": "Foo product photo",
    "title": "Foo"
  }
}
```

The index is rebuilt and pushed to GitHub at most once per request, on `shutdown`, after any of `add_attachment`, `attachment_updated`, or `delete_attachment` fires. Bulk uploads produce a single commit. Pushes are skipped when the rendered content matches what is already in the repo.

### Authoring workflow

1. Upload images in the WordPress media library.
2. `git pull` and open `media-index.json` to find the attachment IDs you want.
3. Reference them in your product file:

   ```yaml
   featured_image_id: 4271
   gallery_image_ids: [4272, 4273]
   ```

4. Push. On import, the plugin sets the featured image and `_product_image_gallery` directly. Any ID that does not exist in the local media library is logged as a warning and skipped.

### Seeding the index

The attachment hooks only fire on *changes* to the media library — they do not backfill existing attachments. After installing or upgrading the plugin, you must seed the index once with:

```
$ wp wghs media_index_rebuild
```

Until you run this, `media-index.json` will not exist in the repo. The "Export to GitHub" and "Force Export to GitHub" buttons in the WordPress settings page export posts and products only; they do not touch the media index.

After seeding, the per-request hooks keep the index current as you upload, edit, or delete attachments.

## Building the Plugin for Release

You cannot clone this repository directly to the server - it needs to be prepared for release with composer, or you need to run the command below inside the git repository on the server. 

Run this command from the plugin root:

```
$ composer install
```

Settings and dependencies are defined in [`composer.json`](composer.json).

Once composer has been installed, you can 

## Installing the Plugin

### Uploading in WordPress Dashboard ###

TODO: UPDATE THIS ONCE PROCESS IS IMPROVED

2. Navigate to the 'Add New' in the Wordpress Plugin dashboard
3. Navigate to the 'Upload' area
4. Select `wordpress-github-sync.zip` from your computer
5. Click 'Install Now'
6. Activate the plugin in the Plugin dashboard

### Cloning Git Repository

You can also SSH into the web server. Navigate to `public_html/wp_content/plugins` and clone the repository:

```
git clone https://github.com/embeddedartistry/wordpress-github-sync.git
```

Enter the directory and run `composer install`. 

Now you can navigate to the Plugin dashboard in the Wordpress front-end and activate the new plugin.

## Configuring the plugin ###

1. [Create a personal oauth token](https://github.com/settings/tokens/new) with the `public_repo` scope. If you'd prefer not to use your account, you can create another GitHub account for this.
2. Configure your GitHub host, repository, secret (defined in the next step),  and OAuth Token on the Wordpress-GitHub Sync settings page within WordPress's administrative interface. Make sure the repository has an initial commit or the export will fail.
3. Create a WebHook within your repository with the provided callback URL and callback secret, using `application/json` as the content type. To set up a web hook on GitHub, head over to the **Settings** page of your repository, and click on **Webhooks & services**. After that, click on **Add webhook**.
4. Click `Export to GitHub`

## Debugging

### Manual Export via CLI

You can export posts through the CLI using this command. Note that a user ID of `0` will use the default configured in the admin screen.

```
$ wp wghs export all <user id> --debug
```

### Manual Import via CLI

You can import existing posts through the CLI using this command.

```
 wp wghs import 2
```

### Rebuilding the Media Index

Pushes a fresh `media-index.json` to the repo root, dumping the entire WordPress media library. Use on first run, or any time you need to force a full rebuild.

```
$ wp wghs media_index_rebuild
```

## Running Tests

TODO

## Markdown Support ###

Wordpress-GitHub Sync exports all posts as `.md` files for better display on GitHub (and, importantly for us, for use with `pandoc` for generating e-book files) However, note that all content is exported and imported as its original HTML. To enable writing, importing, and exporting in Markdown, please install and enable [WP-Markdown](https://wordpress.org/plugins/wp-markdown/), and Wordpress-GitHub Sync will use it to convert your posts to and from Markdown.

You can also activate the Markdown module from [Jetpack](https://wordpress.org/plugins/jetpack/) or the standalone [JP Markdown](https://wordpress.org/plugins/jetpack-markdown/) to save in Markdown and export that version to GitHub. 

> **Note:** There is a limitation with at least the Jetpack Markdown plugin. It will export both the markdown and the HTML contents. changes are not reflected properly unless you edit both.
> 
## Website Dependencies

This plugin depends on the following plugins to be installed:

- WP Rocket (for clearing the cache when a post is updated)
- Sensei LMS suite (for accessing course and lesson content)
- WooCommerce (for product and product variation sync; the product code paths are guarded by `function_exists( 'wc_get_product' )` and inactive when WooCommerce is not loaded)

### Prior Art ###

* [litefeel/writing-on-github/](https://github.com/litefeel/writing-on-github/)
* [WordPress Post Forking](https://github.com/post-forking/post-forking)
* [WordPress to Jekyll exporter](https://github.com/benbalter/wordpress-to-jekyll-exporter)
* [Writing in public, syncing with GitHub](https://konklone.com/post/writing-in-public-syncing-with-github)
* [Wordpress GitHub Sync](https://github.com/mAAdhaTTah/wordpress-github-sync)
