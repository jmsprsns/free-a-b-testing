# Free A/B Testing

A/B test headlines, buttons, blocks or whole pages. Cache-safe and flicker-free, with goal tracking, statistical confidence and one-click winners.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

Pick any published post, page or WooCommerce product and make one or more variants of it. Each variant is a copy of the page that you change in the normal WordPress editor: a new headline, different button text, a swapped block, or a whole new page. Visitors are split evenly between the versions and always see the same one when they come back.

Choose what counts as a conversion:

- **Clicks** on a button, link or any element (pick it right on the page, or type a CSS selector)
- **Form submissions** (any form, or one you pick)
- **Phone and text taps** on `tel:` and `sms:` links
- **WooCommerce purchases**, counted only when the order is paid, with revenue per variant

The results screen shows visitors, conversions, conversion rate, the change against the original and statistical confidence for each variant, with a day-by-day chart. A winner is only declared once a variant reaches 95% confidence (two-sided two-proportion z-test, Bonferroni-corrected when there are several variants, and at least 30 visitors per version). **Apply winner** copies the variant's headline and content onto the page in one click; the old version stays in the page's revisions.

Find it under **Content Powered > Free A/B Testing**, or use the **A/B test** link on any row of the Posts and Pages screens.

### Fast, cached and flicker-free

Page caches give every visitor the same HTML, so the server can't pick a variant. Instead the page holds every version of whatever changed (only the blocks that differ, not the whole page twice), each marked with classes. A small inline script at the top of `<head>` (about 1.2 KB gzipped) picks the visitor's variant, remembers it in the `fabt` cookie and adds one class to `<html>` before anything paints, and CSS hides the other versions. No flicker, no extra request, no external script, and nothing at all on pages without a running test. Visitors without JavaScript see the original.

When a test starts, pauses, ends or changes, its page is purged from WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache, SiteGround Optimizer and Cache Enabler. The inline code opts out of "delay JavaScript", defer and Remove Unused CSS in the popular optimizers. Hook `free_a_b_testing_purge_page` to clear another cache or CDN.

### Privacy and security

- The `fabt` cookie holds a random ID and the variant number per test. The database only stores a keyed hash of the ID. IPs are never stored: rate limits use a hash of the IP with a salt that changes daily.
- Logged-in team members (anyone who can write posts) are not counted by default. Global Privacy Control and Do Not Track can be honored (Settings).
- The tracking endpoint (`admin-ajax.php?action=fabt_hit`) only takes real same-site POSTs, checks every field against the running test, counts each visitor once, ignores bots, and limits how many visitors and conversions one connection can add per hour. Purchase goals can't be converted from the browser at all.
- Variants are private: only admins (`manage_options`) can see or edit them, including through the REST API the block editor uses.
- A suggested paragraph for your privacy policy is added under Settings > Privacy.

### Hooks

| Hook | Use |
|---|---|
| `free_a_b_testing_purge_page` (action) | A tested page changed; purge it from another cache. Gets the post ID. |
| `free_a_b_testing_title_callers` (filter) | Function names that print the page heading. Add your theme's if its headline doesn't change. |
| `free_a_b_testing_script_config` (filter) | The inline script's settings for the current page. |
| `free_a_b_testing_client_ip` (filter) | The visitor IP used for rate limits, for unusual proxy setups. |

Rebuild `assets/tracker.min.js` with `bin/build.sh` after editing `assets/tracker.js`.

## Automatic updates

Updates come from https://github.com/jmsprsns/free-a-b-testing through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `free-a-b-testing.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `free-a-b-testing.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'FREE_A_B_TESTING_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'FREE_A_B_TESTING_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
free-a-b-testing.php                          Plugin header, constants, boot, WooCommerce compatibility
uninstall.php                                 Deletes tests, variants, results and settings
readme.txt                                    WordPress readme; feeds the "View details" popup
includes/
  class-free-a-b-testing-plugin.php           Main singleton and settings
  class-free-a-b-testing-install.php          The visitors table (wp_fabt_visitors)
  class-free-a-b-testing-tests.php            Tests and variants: storage, lifecycle, apply, cache purging
  class-free-a-b-testing-variants-controller.php  REST controller that keeps variants private
  class-free-a-b-testing-merge.php            Builds the page with every version of what changed
  class-free-a-b-testing-frontend.php         Content and headline hooks, inline CSS/script, preview, picker
  class-free-a-b-testing-collector.php        The public view/conversion endpoint
  class-free-a-b-testing-request.php          Cookie, origin, IP and bot checks
  class-free-a-b-testing-stats.php            Results, z-test and daily chart data
  class-free-a-b-testing-woo.php              WooCommerce purchase attribution
  class-free-a-b-testing-admin.php            Content Powered > Free A/B Testing screens
  class-free-a-b-testing-menu.php             Shared top-level "Content Powered" menu
  class-free-a-b-testing-feedback.php         Shared Submit Feedback and Report a Bug pages
  class-free-a-b-testing-updater.php          GitHub update checks (PUC wiring)
  class-free-a-b-testing-icons.php            Inline Lucide icon helper
assets/
  tracker.js, tracker.min.js                  The inline head script (minified copy is what pages get)
  picker.js                                   Element picker for click and form goals (admins only)
  admin.css, admin.js                         Loaded only on the plugin's page
  icon.svg                                    Icon on the update screens
  content-powered.svg                         Sumo logo for the Content Powered menu
bin/build.sh                                  Rebuilds tracker.min.js (not shipped in releases)
icons/                                        Lucide icons used by the plugin (plus LICENSE)
plugin-update-checker/                        Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-free-a-b-testing-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-free-a-b-testing-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/`. Print one inline with:

```php
echo Free_A_B_Testing_Icons::get( 'split', 20 );             // name, size in px
echo Free_A_B_Testing_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
