# discogs_enrich

Companion tool of the Wax Store Catalog plugin.

Discogs publishes its whole database every month under the CC0 licence
(public domain) at https://data.discogs.com/. This tool reads the releases
file and keeps the releases of your catalog only, to complete their genres
and styles, year, country, barcode and tracklist. Images are not part of
this open data and are never retrieved.

## Requirements

Python 3, already installed on Linux and macOS. On Windows, install it from
https://www.python.org/downloads/ (tick "Add Python to PATH"). No library needed.

## Steps

1. In WordPress, Record shop > Tools > CSV import: "Download the Discogs IDs
   of the catalog". Your Discogs inventory export (release_id column) works too.
2. On https://data.discogs.com/, open the latest month and download
   `discogs_YYYYMMDD_releases.xml.gz` (several GB, no need to unzip it).
3. In a terminal, from the folder of the tool:

       python3 discogs_enrich.py discogs-ids-YYYY-MM-DD.csv discogs_YYYYMMDD_releases.xml.gz

   (on Windows: `py discogs_enrich.py …`). It writes `discogs-enrichment.csv`
   and stops as soon as every release is found.
4. Back in Record shop > Tools > CSV import, upload `discogs-enrichment.csv`.
   Only empty fields of existing releases are filled: no release or copy is
   created, nothing you entered is replaced.

On a server with WP-CLI, one command does it all:

    wp waxsc enrich /path/to/discogs_YYYYMMDD_releases.xml.gz

Licence: GPL-2.0-or-later.
