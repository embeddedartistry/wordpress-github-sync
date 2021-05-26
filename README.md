# Embedded Artistry: Writing On GitHub Wordpress Plugin

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

Caveats:

- Posts cannot be deleted on the front-end by removing files from the repository
- Posts cannot be added to the front-end by adding new files with the appropriate metadata header to the repository (unlike the litefeel version)

These limitations are in place to prevent front-end problems, whether due to a bad commit or due to the need for complex 

## How the Plugin Works

The sync action is based on two webhooks:

1. A per-post sync fired in response to WordPress's `save_post` hook which pushes content to GitHub
2. A sync of all changed files triggered by GitHub's `push` webhook (outbound API call)

The plugin runs the import/export path as the configured user (set in the Wordpress front-end settings page for this plugin). This user must have the appropriate permissions to access the relevant content.

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
4. Select `writing-on-github.zip` from your computer
5. Click 'Install Now'
6. Activate the plugin in the Plugin dashboard

### Cloning Git Repository

You can also SSH into the web server. Navigate to `public_html/wp_content/plugins` and clone the repository:

```
git clone https://github.com/embeddedartistry/writing-on-github.git
```

Enter the directory and run `composer install`. 

Now you can navigate to the Plugin dashboard in the Wordpress front-end and activate the new plugin.

## Configuring the plugin ###

1. [Create a personal oauth token](https://github.com/settings/tokens/new) with the `public_repo` scope. If you'd prefer not to use your account, you can create another GitHub account for this.
2. Configure your GitHub host, repository, secret (defined in the next step),  and OAuth Token on the Writing On GitHub settings page within WordPress's administrative interface. Make sure the repository has an initial commit or the export will fail.
3. Create a WebHook within your repository with the provided callback URL and callback secret, using `application/json` as the content type. To set up a webhook on GitHub, head over to the **Settings** page of your repository, and click on **Webhooks & services**. After that, click on **Add webhook**.
4. Click `Export to GitHub`

## Debugging

### Manual Export via CLI

You can export posts through the CLI using this command. Note that a user ID of `0` will use the default configured in the admin screen.

```
$ wp wogh export all <user id> --debug
```

### Manual Import via CLI

You can import existing posts through the CLI using this command.

```
 wp wogh import 2
```

## Running Tests

TODO

## Markdown Support ###

Writing On GitHub exports all posts as `.md` files for better display on GitHub (and, importantly for us, for use with pandoc for generating e-book files) However, note that all content is exported and imported as its original HTML. To enable writing, importing, and exporting in Markdown, please install and enable [WP-Markdown](https://wordpress.org/plugins/wp-markdown/), and Writing On GitHub will use it to convert your posts to and from Markdown.

You can also activate the Markdown module from [Jetpack](https://wordpress.org/plugins/jetpack/) or the standalone [JP Markdown](https://wordpress.org/plugins/jetpack-markdown/) to save in Markdown and export that version to GitHub. 

> **Note:** There is a limitation with at least the Jetpack Markdown plugin. It will export both the markdown and the HTML contents. changes are not reflected properly unless you edit both.
> 
## Website Dependencies

This plugin depends on the following plugins to be installed:

- WP Rocket (for clearing the cache when a post is updated)
- Sensei LMS suite (for accessing course and lesson content)

### Prior Art ###

* [litefeel/writing-on-github](https://github.com/litefeel/writing-on-github)
* [WordPress Post Forking](https://github.com/post-forking/post-forking)
* [WordPress to Jekyll exporter](https://github.com/benbalter/wordpress-to-jekyll-exporter)
* [Writing in public, syncing with GitHub](https://konklone.com/post/writing-in-public-syncing-with-github)
* [Wordpress GitHub Sync](https://github.com/mAAdhaTTah/wordpress-github-sync)
