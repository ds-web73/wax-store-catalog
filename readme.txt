=== Wax Store Catalog ===
Contributors: dsweb73
Tags: record store, vinyl, music, catalog, ecommerce
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.30.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Record shop catalog for vinyl, CD, cassettes and merchandise, with formats, labels, conditions and structured data.

== Description ==

Wax Store Catalog turns WordPress into a record shop catalog. It is the companion plugin of the Wax Store theme, and works with any theme.

* Releases with formats (vinyl LP, single, maxi, 10", box set, CD, DVD, Blu-ray, cassette, merchandise)
* Edition features: colored vinyl, splatter, picture disc, limited, numbered, promo, Record Store Day, test pressing, white label, 180 g, gatefold
* Genres, artists, record labels, countries, new or used condition, original pressing or reissue
* Release details: year, catalog number, barcode with check digit validation, tracklist
* Copies: each physical item has its own Goldmine grading (media and sleeve), price, quantity and status
* CSV import: Discogs inventory export or any spreadsheet, automatic column matching, batched processing for large catalogs, safe re-imports
* Shop settings (Record shop > Settings): identity (individual or professional), contact and opening hours, legal information, hosting, local SEO, social and marketplace profiles
* Shop opening status block: "Open today until 7 pm", "Opens tomorrow at 11 am", exceptional closures, weekly hours; stays right on cached pages
* Legal notice generated from the settings with the [waxsc_legal_notice] shortcode
* Schema.org structured data: MusicStore on the front page; Product + MusicRelease (offers, condition, barcode, label, catalog number, format, tracks) and breadcrumbs on release pages
* Keyword search on artists, album titles and catalog numbers, combined with the filters
* Blocks: price, release summary, details, copies, tracklist, catalog filters (format, genre) and A to Z artist index
* Default templates for releases, the catalog and its archives (block themes)
* "Sell us your records" form for collection offers, with photos and lists stored in a protected folder
* Google Merchant Center product feed for free listings in Google Shopping
* "Notify me" alerts: back in stock, new arrivals of an artist or a label; double opt-in, hourly digest, one-click unsubscribe
* Location in the shop for each copy (internal), shown on reservations, orders and exports
* Printable price labels on A4 sticker sheets, with a QR code to the release page
* Discogs sync without API: delete file for the listings sold on the site, and copies sold on Discogs marked sold when a full inventory export is imported
* Photo import: covers and gallery photos matched to releases by file name (catalog number, Discogs ID, barcode), uploaded one by one
* Automatic covers (optional, started by hand): illustration covers from the Cover Art Archive for releases without photo
* Showcase mode: visitors reserve a given copy with the Reservation form block, no online checkout required. Requests are e-mailed and kept in the admin, with spam protection without captcha and automatic deletion after 12 months
* WooCommerce mode: releases stay the catalog pages, WooCommerce handles cart, checkout and orders. One hidden variable product per release, one variation per grading with its stock; orders mark copies sold, cancellations put them back
* Commerce providers API: selling engines plug in without touching the theme

== Frequently Asked Questions ==

= Is there a theme made for it? =

Yes: the Wax Store theme was designed together with this plugin (price stickers, bin dividers, vinyl-style release pages, dark "Vinyl" variation). The plugin suggests it with a dismissible notice on its own screens; it works with any other theme too.

= Does it work with my theme? =

Yes. With block themes, the plugin provides templates for releases, the catalog and its archives. With classic themes (Astra, OceanWP, GeneratePress…), the same pages are displayed between the header and footer of your theme. Price stickers and record placeholders are drawn by the plugin; adjust their colors with the CSS variables --waxsc-sticker, --waxsc-sticker-text, --waxsc-sold, --waxsc-cover-bg, --waxsc-cover-disc and --waxsc-cover-label.

= Where are the tools? =

The Catalog menu holds releases and their taxonomies. Everything else is in the Record shop menu: Tools (CSV import, photos, automatic covers, Discogs export, labels), Customer requests (reservations, alerts, collection offers) and Settings.

= How do I show the catalog in a page built with a page builder? =

Add the shortcode [waxsc_catalog] (optionally [waxsc_catalog count="8"]): artist index, filters and the latest releases.

= How do I fill genres, tracklists and barcodes from Discogs? =

Discogs publishes its database every month under the CC0 licence. The discogs_enrich tool, in the tools folder of the plugin, reads this file on your computer (Python 3) and produces a CSV to import in Record shop > Tools > CSV import; the steps are on that screen. With WP-CLI: wp waxsc enrich /path/to/discogs_YYYYMMDD_releases.xml.gz. Images are not part of this open data and are never retrieved.

= Do I need WooCommerce? =

No. The showcase mode lets visitors reserve copies without online payment. Install WooCommerce, then choose it in Record shop > Settings > Catalog to sell online.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it from the Plugins screen.
2. Activate it. Default formats, conditions and editions are created in your site language.

== Third-party libraries ==

* qrcode-generator 2.0.4 by Kazuhiko Arase, MIT licence, https://github.com/kazuhikoarase/qrcode-generator (assets/lib/qrcode.js). QR codes are generated in the browser; no external service is called.

== External services ==

This plugin connects to external services only when you start the Automatic covers tool (Record shop > Tools > Automatic covers). Nothing is sent otherwise.

* MusicBrainz API (musicbrainz.org), run by the MetaBrainz Foundation: for each release without cover, its Discogs release ID, barcode, catalog number and artist name are sent to find the matching MusicBrainz release. The request also carries your site address in its User-Agent, as MusicBrainz requires. Terms: https://musicbrainz.org/doc/About/Data_License, privacy policy: https://metabrainz.org/privacy
* Cover Art Archive (coverartarchive.org), hosted by the Internet Archive: the front cover of the matched release is downloaded and added to your media library. Terms of use: https://archive.org/about/terms, privacy policy: https://archive.org/about/terms#privacy. Covers remain the property of their respective rights holders.

== Screenshots ==

1. Home page with the latest arrivals: price stickers, sold sticker, artist index, search and filters.
2. The catalog, filtered by artist initial, with the bin divider of the Wax Store theme.
3. A release page: copies for sale with their grading, reserve button, details and tracklist.
4. "Notify me": visitors get an e-mail when a record is back in stock or when an artist or a label has new arrivals.
5. "Sell us your records": collection offers with size, formats, photos and a list file.
6. Editing a release: year, catalog number, barcode, pressing details and copies (grading, price, quantity, status, location in the shop).
7. Record shop > Tools: CSV import (Discogs inventory export), photos, automatic covers, Discogs export and labels.
8. Printable price labels with a QR code to the release page.
9. Record shop > Customer requests: reservations, alerts and collection offers.
10. A reservation request, with a button to mark the copy as reserved.
11. WooCommerce mode: add to cart from the release page, one choice per grading, cart and checkout by WooCommerce.

== Changelog ==

= 0.30.2 =
* Screenshot caption for the WooCommerce mode.

= 0.30.1 =
* Screenshot captions.

= 0.30.0 =
* Plugin review: scripts and styles of the label sheet and the WooCommerce notice are enqueued; structured data printed with wp_print_inline_script_tag().
* Public forms (reservation, alerts, collection offers) carry a nonce, refreshed by the browser when the visitor starts typing, so cached pages keep working.
* Settings sanitized in every case, also when saved without a tab.
* Translations come from translate.wordpress.org; bundled translation files and load_plugin_textdomain() removed.

= 0.29.1 =
* Screenshot captions.

= 0.29.0 =
* Dismissible suggestion of the companion Wax Store theme, on the Record shop screens only, when another theme is active.

= 0.28.3 =
* WordPress.org contributor.

= 0.28.2 =
* QR code library moved to assets/lib (folders named vendor are often ignored by Git and some deployment tools); the label sheet says so if the library is missing.

= 0.28.1 =
* Plugin and author addresses.

= 0.28.0 =
* The discogs_enrich tool now ships with the plugin (tools folder), with instructions in English and French and step-by-step guidance on the CSV import screen.
* WP-CLI command wp waxsc enrich: reads the Discogs dump on the server and completes releases in one go.

= 0.27.0 =
* Import: tracklist column (one track per line, tab-separated position, title and duration).
* Import: rows without copy data (enrichment files) only complete existing releases; they never create releases or empty copies.
* Download the Discogs IDs of the catalog, for the discogs_enrich tool that extracts CC0 data from the monthly Discogs dump.

= 0.26.0 =
* Admin menus reorganized: Catalog only holds releases and taxonomies; a new Record shop menu groups Tools (tabs: CSV import, photos, automatic covers, Discogs export, labels), Customer requests (tabs: reservations, alerts, collection offers) and Settings. The old Settings > Record shop address redirects to the new one.

= 0.25.1 =
* Plugin Check: price format without untranslatable string ("waxsc_price_format" filter), translator comments, QR library loaded through the WordPress script system, input sanitization, nonce checks in each handler, seems_utf8() replaced.
* Tested up to WordPress 7.1.

= 0.25.0 =
* "Sell us your records" block: contact details, city, collection size, formats, genres, description, up to 5 photos and a list file.
* Files stored under random names in a closed folder, shown in the admin to authorized users only, deleted with the offer.
* Offers in Catalog > Collection offers, e-mails to the shop and the seller, deletion after 12 months, personal data export and erasure.

= 0.24.0 =
* Google Merchant Center feed (Settings > Record shop > Catalog): one product per release in stock at its lowest price, condition, GTIN or label and catalog number, Google category (records, CDs, cassettes…), gallery images. Releases without photo are left out, as Google requires.

= 0.23.0 =
* "Notify me" alerts on release pages and artist or label archives (Notify me block): back in stock (one-shot) or new arrivals (until unsubscribed).
* Double opt-in by e-mail, one digest per subscriber at most every hour, one-click unsubscribe and List-Unsubscribe header.
* Alerts listed in Catalog > Alerts; unconfirmed alerts deleted after 7 days; WordPress personal data export and erasure supported.

= 0.22.0 =
* Works with any theme: the plugin draws its price and sold stickers, record placeholders and card layout, with colors set by CSS variables.
* Classic themes: release pages, the catalog and archives use plugin templates between the theme header and footer.
* [waxsc_catalog] shortcode for page builders.
* The catalog stylesheet loads on every page, so placeholders also show in custom query loops.

= 0.21.0 =
* Location in the shop for each copy: edited with the copy, imported from the Discogs "location" column, shown on reservation requests and e-mails, WooCommerce orders (admin only) and the Discogs export.
* Labels (Catalog > Labels, or the "Print labels" bulk action): A4 sheets in three common formats, with artist, title, format, grading, price and QR code; filter by date or location, skip used labels.

= 0.20.0 =
* Discogs export (Catalog > Discogs export): CSV delete file for the Discogs listings of copies sold on the site, exported once, last ten files available again.
* Import option: copies whose Discogs listing is missing from a full inventory export are marked sold.

= 0.19.0 =
* Keyword search in the catalog filters: artists, album titles and catalog numbers (with or without spaces), every word must match, results update while typing.
* Search result pages are marked noindex.

= 0.18.1 =
* Fix: removing many duplicate WooCommerce products could exceed the server time limit. The cleanup now runs in slices of a few seconds (admin pages) or fifteen seconds ("Convert now" and background tasks).

= 0.18.0 =
* Automatic covers: illustration covers from the Cover Art Archive, matched by Discogs ID, barcode or catalog number and artist, with an option to use the cover of another edition of the album. One request per second, as MusicBrainz requires; stop and resume at will.
* "Illustration" mention under these covers on release pages; imported photos replace them.

= 0.17.3 =
* When WooCommerce or its setup wizard picks the home page as shop page, a dedicated Shop page is used instead (existing or created), with a notice. A choice made by the owner in the WooCommerce settings is kept.

= 0.17.2 =
* Fix: overlapping conversions could create duplicate WooCommerce products; a lock prevents it and existing duplicates are removed automatically.
* Warning when the home page is also the WooCommerce shop page.

= 0.17.1 =
* Fix: switching to WooCommerce no longer slows the admin down. Products are created in the background, ten releases at a time, with a progress notice and a "Convert now" button.
* Admin repair checks run at most every ten minutes and never during background requests.

= 0.17.0 =
* WooCommerce selling mode: add-to-cart form on release pages, copies grouped by grading and price, stock synchronized both ways, product URLs redirected to releases.
* New features: poster included, goodies included, special packaging.

= 0.16.0 =
* Shop opening status block, recomputed in the browser every minute (page cache safe), with address and weekly hours options.
* Exceptional closure dates and reason in the Contact settings.
* Block settings: on/off options for the price and status blocks.

= 0.15.0 =
* Photo import (Catalog > Import photos): matched by catalog number, Discogs listing or release ID, barcode or slug; suffixes -2, _verso, -label… feed the gallery; already imported files are skipped.
* Release gallery: meta box in the editor, Release gallery block under the cover, photos in the JSON-LD.
* Alt text set on imported photos.

= 0.14.0 =
* Reservation form block: record and copy recap, consent, honeypot and rate limit spam protection.
* One Reserve button per copy on release pages.
* Requests kept in Catalog > Reservations, with a button to mark the copy as reserved.
* E-mails to the shop (reply goes to the customer) and acknowledgment to the customer.
* Settings: recipient address, automatic reservation, one-click creation of the reservation page.
* Requests deleted after 12 months; suggested privacy policy text.

= 0.13.0 =
* Sub-genre filter: appears when the chosen genre has sub-genres (Rock > Hard rock, Metal, Punk…).
* Import: "Style" or "Sub-genre" column creates sub-genres under their genre.
* JSON-LD: a release filed under a sub-genre also declares its parent genre.

= 0.12.0 =
* Release pages: Product + MusicRelease JSON-LD with offers, condition, GTIN, label, catalog number, format, year, genres, country and tracklist.
* Breadcrumbs JSON-LD: home, catalog, artist, release.
* Sold or reserved releases keep a valid offer (SoldOut or Reserved) with their last price.

= 0.11.4 =
* Artist index and catalog filters blocks can use the wide and full widths.
* Latest arrivals pattern shows 12 releases (two rows of six on wide screens).

= 0.11.3 =
* Fix: quotes encoded without their final semicolon (&quotPop&quot) or twice (&amp;quot;) are shown as real quotes.

= 0.11.2 =
* Price stickers: "From" on its own line above the amount; whole amounts without decimals (25 €).
* Fix: quotes encoded as &quot; in Discogs exports are shown as real quotes.

= 0.11.1 =
* Fix: fatal error on admin pages (missing repair routine).
* Fix: sort keys and sold flags of existing releases were never computed after updates; they are now repaired automatically.
* Fix: default terms are translated even when the previous update was interrupted.

= 0.11.0 =
* French translation included (admin, blocks, catalog, legal notice).
* Default terms created in English are renamed in the site language, unless you renamed them.
* French URLs when the site is in French: /catalogue/, /artiste/, /pays/…

= 0.10.1 =
* Fix: releases missing their sold flag or sort keys (after a reinstall) are repaired automatically.
* Prices follow the site language (12,50 € in French) until a translation is installed.
* The Latest arrivals pattern uses the wide width.

= 0.10.0 =
* Sold releases are hidden from the catalog and query loops by default.
* Setting to show them instead, with a "Hide sold items" filter for visitors.
* Release pages of sold items stay online.

= 0.9.0 =
* Releases without a photo show a stylized record in any query loop or template.
* "Latest arrivals" pattern: artist index, filters and the 8 latest releases.
* Filters placed outside the catalog open the catalog with the chosen filter.

= 0.8.0 =
* Catalog sort: artist A to Z (default), latest arrivals, price low to high or high to low.
* Artists are filed without their leading definite article (The, Le, La, Les, L', Los, El, Il, Die…), in the index and the sort.
* Default catalog order setting.

= 0.7.0 =
* Seven dynamic blocks for releases and the catalog.
* Catalog filters by format and genre, updated without page reload, shareable URLs.
* A to Z artist index, filed like record bins (The Beatles under B).
* Default single, catalog and taxonomy templates for block themes.

= 0.6.0 =
* Tabbed shop settings: Identity, Contact, Legal information, Hosting, Local SEO, Network, Catalog.
* [waxsc_legal_notice] shortcode.
* MusicStore / Organization JSON-LD on the front page, with opening hours, coordinates and profiles.

= 0.5.0 =
* Features taxonomy (multiple choice) with 11 default edition features.
* Pressing details field on releases.
* Import detects Discogs feature abbreviations (Ltd, Num, Promo, Pic, TP, W/Lbl, Gat, 180 Gram, Record Store Day, colors).
* Grade labels start with the code (VG+ · Very Good Plus) to stay readable in narrow fields.

= 0.4.2 =
* Import: formats with typographic inch marks (″, ”) and the × multiplier are recognized (2 × Vinyl, 10″).

= 0.4.1 =
* Import: condition column (new / used) with a default value shown in the mapping table.
* Import: reference terms deleted or recreated by hand are restored instead of silently skipped.
* Import report lists unrecognized formats.

= 0.4.0 =
* CSV import (Catalog > Import): Discogs inventory export or custom spreadsheet, column mapping, batched import with progress.
* Re-imports update copies by their unique ID and only fill empty release fields.
* Discogs grades, formats (LP, 7", 12", CD, cassette…) and reissue flags are recognized.

= 0.3.0 =
* Format, condition and edition accept a single term (select in the block editor, radio buttons in the classic editor).
* Server-side safeguard: when several terms are assigned, the most specific one is kept.

= 0.2.0 =
* Release details meta box: year, catalog number, barcode.
* Tracklist and copies repeaters, Goldmine or free grading.
* Prices and availability aggregated from copies, Offer or AggregateOffer schema.

= 0.1.0 =
* Initial development version.
