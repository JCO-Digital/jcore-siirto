=== JCORE Siirto ===
Contributors: jcodigital
Tags: jcore
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate a WordPress site to another installation: themes, plugins, media, files and database, using only administrator accounts.

== Description ==

Move a WordPress site to another installation using only administrator accounts on both sites: no FTP, SSH, hosting panel or database access needed.

Install the plugin on both sites. On the site you are moving from, create a migration key under **Tools → Site migration → Send this site**. On the site you are moving to, paste it under **Receive a site here**, choose what to copy, and confirm.

= Features =

* **Themes, plugins, must-use plugins, media and other wp-content files**, plus static files in the site root such as robots.txt.
* **The database**, with addresses and server paths rewritten for the new site, including inside serialized and JSON data. Different table prefixes are handled.
* **Nothing changes until you confirm.** Everything is copied aside first; replacing the site takes a moment.
* **Roll back** until you clean up: replaced files and tables are kept.
* **Works on hosts with short time limits**: the copy runs in small steps and pauses if you close the page.
* **Secure by design**: short-lived, single-use keys that need your password to create; signed and encrypted transfers; HTTPS required; executable files in the media library skipped by default; an activity log and an email when a key is used.
* **Automatic updates** – through the J&Co Digital update service.

Multisite networks are not supported.

= External services =

The plugin checks for updates against `https://update.jcore.fi`, operated by J&Co Digital Oy. It sends the plugin slug and the installed version, and the site URL as the request's origin. No visitor or content data is involved.

The migration itself connects only the two sites you choose: the target site sends signed requests to the source site's address taken from the migration key. No data passes through J&Co Digital or any other third party.

= Source code =

The readable source of the built assets is published at https://github.com/JCO-Digital/jcore-siirto.

== Installation ==

1. Upload the `jcore-siirto` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin on both the source and the target site.
3. Go to Tools → Site migration.

== Changelog ==

= 0.2.0 =

* Feature: migrate a site between installations with admin accounts only
* Fix: plugin-check - allow writing staging deny rules in wp-content
* CI: github - remove unused distribution repository configuration
