=== Free A/B Testing ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: a/b testing, split testing, conversion, headline testing, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A/B test headlines, buttons, blocks or whole pages. Cache-safe and flicker-free, with goal tracking, statistical confidence and one-click winners.

== Description ==

Pick any published post, page or WooCommerce product and make one or more variants of it. Each variant is a copy of the page that you change in the normal WordPress editor: a new headline, different button text, a swapped block, or a whole new page. Visitors are split evenly between the versions, and each visitor always sees the same one.

Choose what counts as a conversion:

* Clicks on a button, link or any element. Pick it right on the page, or type a CSS selector.
* Form submissions: any form on the page, or one you pick.
* Phone and text taps on tel: and sms: links.
* WooCommerce purchases, counted only once the order is paid, with revenue per variant.

The results screen shows visitors, conversions, conversion rate, the change against the original and statistical confidence for every variant, with a day-by-day chart. A winner is only declared once a variant reaches 95% confidence. Then one click applies it: the winner's headline and content are copied onto the page, and the old version stays in the page's revisions.

= Built for cached, fast sites =

* Works with page caching and CDNs. Every visitor gets the same cached page; a tiny inline script shows each visitor their version.
* No flicker. The version is chosen before the page paints, so visitors never see the original swap to the variant.
* Light. About 1.2 KB of inline code (gzipped) on tested pages only, no external scripts, no extra requests, nothing on other pages. Only the blocks that differ are repeated in the page, not the whole page.
* Pages are purged from WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache, SiteGround Optimizer and Cache Enabler when a test starts, pauses, ends or changes.

= Private and hard to game =

* The "fabt" cookie only holds a random ID and the variant number. IP addresses are never stored.
* Your logged-in team isn't counted, and you can honor Global Privacy Control and Do Not Track.
* Bots are ignored, each visitor counts once, and one connection can only add so many visits an hour. Purchases can't be faked from the browser.
* Variants stay private until a test runs: only admins can see or edit them.

Find it under Content Powered > Free A/B Testing in the admin menu, or use the "A/B test" link on any row of the Posts and Pages screens.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `free-a-b-testing` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Free A/B Testing and create a test.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= What can I test? =

The page's headline and anything in its content: button text and links, images, single blocks, whole sections, or the entire page. Each variant opens in the normal block editor (or the classic editor). The site's header, footer and sidebar are the same for every version.

= Does it work with page caching? =

Yes. That's what it's built for. The cached page holds every version of whatever changed, and the inline script picks one per visitor before the page paints.

= How is the winner decided? =

Each variant is compared with the original using a two-sided two-proportion z-test. With several variants, confidence is adjusted for testing them all at once (Bonferroni). A winner needs 95% confidence and at least 30 visitors per version, with enough conversions for the test to be valid. If every variant converts worse than the original with 95% confidence, the original wins.

= Why aren't my own visits counted? =

Logged-in users who can write posts aren't counted, so your checks don't skew the numbers. Open the page in a private window to see it as a visitor. You can turn this off in Settings.

= Does it work with page builders? =

Headline tests and every goal work with any theme. Content variants work with the block editor and the classic editor; builders that store their layout outside the post content (Elementor, Divi and similar) show the original content.

== Changelog ==

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/free-a-b-testing/releases for the full release history.
