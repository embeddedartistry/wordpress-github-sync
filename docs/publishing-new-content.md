# Publishing New Content from GitHub

You can create new WordPress posts by pushing markdown files to the synced GitHub repository. This guide covers file format, directory structure, and the full workflow.

## File Format

Each file must be a markdown file (`.md`) with YAML frontmatter at the top:

```markdown
---
post_title: "My New Blog Post"
published: true
layout: post
post_date: "2024-01-15 10:00:00"
author: "Phillip Johnston"
post_name: my-new-blog-post
tags:
  - embedded-systems
  - tutorial
categories:
  - Engineering
---

Your post content goes here in markdown format.
```

The file **must** start with `---` (the YAML frontmatter delimiter) to be recognized as a post. Files without frontmatter are treated as raw file uploads rather than WordPress posts.

## Frontmatter Fields

### Required

- **`post_title`** - The title of the post.
- **`published`** - Set to `true` to publish immediately, or `false` to save as a draft.
- **`layout`** - The WordPress post type. Must be one of the whitelisted types: `post`, `page`, `glossary`, `newsletters`, `course`, `lesson`, `fieldatlas`.

### Optional

- **`post_date`** - Publication date in `YYYY-MM-DD HH:MM:SS` format. If present but empty, defaults to the current time.
- **`post_name`** - The URL slug. If omitted, WordPress generates one from the title.
- **`author`** - Display name of the WordPress user to assign as author. The plugin searches by display name, nicename, and login. If no match is found, the configured default user is used.
- **`tags`** - A YAML list of tag names.
- **`categories`** - A YAML list of category names. Categories that don't exist in WordPress are created automatically.
- **`ID`** - A WordPress post ID. If set and the post exists, the file updates the existing post rather than creating a new one. **Do not set this for new posts** -- it is added automatically after the first sync.

## Directory Structure

Files must be placed in specific directories corresponding to their post type. The plugin only imports files from these prefixes:

| Directory        | Post Type      | Notes                                |
|------------------|----------------|--------------------------------------|
| `posts/`         | `post`         | Organized by year (e.g., `posts/2024/`) on export |
| `pages/`         | `page`         |                                      |
| `glossary/`      | `glossary`     |                                      |
| `newsletters/`   | `newsletters`  | Organized by year on export          |
| `courses/`       | `course`       | Subdirectory per course name on export |
| `fieldatlas/`    | `fieldatlas`   |                                      |

When creating a new post, place the file in the appropriate top-level directory. For example, a new blog post can go in `posts/my-new-post.md`. After import, the plugin re-exports the file to its canonical path (e.g., `posts/2024/my-new-post.md`).

**Note:** The `layout` frontmatter field determines the WordPress post type, not the directory. The directory only controls whether the file is eligible for import.

## What Happens After Import

When a new post is successfully imported:

1. The post is created in WordPress via `wp_insert_post`.
2. The author is resolved from the `author` frontmatter field (falling back to the default user).
3. Tags and categories from the frontmatter are applied. Missing categories are created.
4. The plugin **re-exports** the post back to GitHub. This re-export:
   - Adds the WordPress `ID` field to the frontmatter so future edits update the same post.
   - Moves the file to its canonical directory path (e.g., `posts/2024/`).
   - Adds other metadata like `link`, `post_excerpt`, etc.

This re-export is what connects the GitHub file to the WordPress post for all future syncs.

## Loop Prevention

The plugin prevents infinite sync loops between WordPress and GitHub using two mechanisms:

1. **Commit message tagging** - Every commit the plugin pushes to GitHub ends with the tag `wghs`. When a webhook fires, the plugin checks if the commit message ends with this tag. If it does, the import is skipped. This prevents a push-from-WordPress triggering an import back into WordPress.

2. **Semaphore locking** - During import, the `save_post` and `delete_post` export hooks are removed. This prevents the act of saving an imported post from triggering an export back to GitHub (beyond the intentional re-export for new posts).

3. **SHA comparison** - Each post stores the blob SHA from GitHub. If an incoming file has the same SHA and path as what's already stored, the import is skipped.

## Setup Requirements

Before publishing from GitHub will work:

1. **Configure the plugin** in WordPress (Settings > GitHub Sync):
   - Set the GitHub repository (e.g., `username/repo`).
   - Set a webhook secret.
   - Set a default user (used as the post author when no author match is found).

2. **Configure a GitHub webhook** on the repository:
   - **Payload URL:** `https://your-site.com/wp-json/wordpress-github-sync/v1/push` (or the equivalent webhook endpoint for your installation).
   - **Content type:** `application/json`
   - **Secret:** Must match the secret configured in the WordPress plugin settings.
   - **Events:** Select "Just the push event."

3. **Ensure the GitHub token** configured in the plugin has write access to the repository (needed for the re-export step after new post creation).

## Example: Creating a Blog Post

1. In your GitHub repository, create a new file at `posts/getting-started-with-rtos.md`:

    ```markdown
    ---
    post_title: "Getting Started with RTOS"
    published: true
    layout: post
    post_date: "2024-06-15 09:00:00"
    author: "Phillip Johnston"
    tags:
      - rtos
      - embedded-systems
    categories:
      - Embedded Development
    ---

    Real-time operating systems (RTOS) are essential for many embedded applications.

    ## What is an RTOS?

    An RTOS is an operating system designed to serve real-time applications
    that process data as it comes in, typically without buffer delays.
    ```

2. Commit and push to the configured branch.

3. GitHub sends a webhook to your WordPress site.

4. The plugin:
   - Validates the webhook secret and checks the commit message tag.
   - Fetches the file content from GitHub.
   - Parses the frontmatter and creates a new WordPress post.
   - Looks up the author "Phillip Johnston" in WordPress users.
   - Re-exports the post, adding the `ID` field and moving the file to `posts/2024/getting-started-with-rtos.md`.

5. The post is now live on your WordPress site and linked to the GitHub file for future edits.
