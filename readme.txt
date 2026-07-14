=== Marrison Video Thumbnail ===
Author: Marrisonlab
Tags: youtube, thumbnail, media library, extractor, wordpress admin
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Marrison Video Thumbnail lets you paste a YouTube URL, preview the available thumbnail variants, and import the selected image directly into the WordPress Media Library.

It is designed for fast media workflows inside the WordPress admin and supports the most common YouTube URL formats.

Features:

* Supports `youtube.com/watch`, `youtu.be`, `youtube.com/embed`, and `youtube.com/shorts` URLs.
* Detects the available thumbnail variants automatically.
* Imports the selected thumbnail into the Media Library via AJAX.
* Keeps the workflow simple for editors and site administrators.

== Installation ==
1. Upload the `marrison-video-thumb` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin from the WordPress Plugins screen.
3. Open Media > Marrison Video Thumbnail.
4. Paste a YouTube URL, fetch the thumbnails, and import the one you want.

== Frequently Asked Questions ==
= Which YouTube URLs are supported? =
The plugin supports standard watch URLs, shortened URLs, embed URLs, and Shorts URLs.

= Does the plugin download the video? =
No. It only fetches the thumbnail images that YouTube exposes publicly.

= Where does the imported image go? =
The selected thumbnail is added to the WordPress Media Library as a new attachment.

== Changelog ==
= 1.0.2 =
* Switched all user-facing labels and messages to English.
* Updated the plugin title shown in the WordPress admin.
* Removed the `YouTube Thumbnail - ` prefix from imported media titles.

= 1.0.1 =
* Updated plugin metadata to match the `marrisonlab` brand.
* Added a WordPress-compatible `readme.txt` for the plugin details screen.
* Added a structured changelog.

= 1.0.0 =
* Initial release.
