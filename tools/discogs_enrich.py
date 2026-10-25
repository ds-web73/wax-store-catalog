#!/usr/bin/env python3
"""
discogs_enrich.py - Wax Store Catalog companion tool (DS-Web).

Reads the monthly Discogs releases dump (CC0 data, https://data.discogs.com/)
and writes, for the releases of your catalog only, a CSV that the
Wax Store Catalog importer understands: genres, styles, year, country,
barcode and tracklist. The dump is read as a stream: it is never
decompressed on disk and memory use stays low.

Usage:
    python3 discogs_enrich.py IDS_FILE DUMP_FILE [OUTPUT_CSV]

    IDS_FILE    CSV with a "release_id" column: the file downloaded from
                Record shop > Tools > CSV import, or your Discogs inventory export.
    DUMP_FILE   discogs_YYYYMMDD_releases.xml.gz from data.discogs.com
    OUTPUT_CSV  defaults to discogs-enrichment.csv

Licence: GPL-2.0-or-later.
"""

import csv
import gzip
import re
import sys
import time
import xml.etree.ElementTree as ET

VERSION = "1.0.0"
COLUMNS = ["release_id", "artist", "title", "year", "country", "genres", "styles", "barcode", "label", "catno", "tracklist"]


def read_ids(path):
    """Release IDs from a CSV having a release_id column (comma or semicolon)."""
    with open(path, newline="", encoding="utf-8-sig") as handle:
        sample = handle.read(4096)
        handle.seek(0)
        delimiter = ";" if sample.count(";") > sample.count(",") else ","
        reader = csv.DictReader(handle, delimiter=delimiter)
        field = next((f for f in (reader.fieldnames or []) if f and f.strip().lower().replace(" ", "_") in ("release_id", "discogs_id")), None)

        if not field:
            sys.exit("Error: no 'release_id' column in " + path)

        ids = set()
        for row in reader:
            value = (row.get(field) or "").strip()
            if value.isdigit() and int(value) > 0:
                ids.add(value)

    return ids


def clean_name(name):
    """'Nirvana (2)' -> 'Nirvana' (Discogs disambiguation suffix)."""
    return re.sub(r"\s*\(\d+\)$", "", (name or "").strip())


def text(element, path):
    found = element.find(path)
    return (found.text or "").strip() if found is not None and found.text else ""


def gtin_ok(digits):
    if len(digits) not in (8, 12, 13):
        return False
    total, weight = 0, 3
    for digit in reversed(digits[:-1]):
        total += int(digit) * weight
        weight = 1 if weight == 3 else 3
    return (10 - total % 10) % 10 == int(digits[-1])


def barcode(release):
    """First identifier of type Barcode with a valid check digit."""
    for identifier in release.findall("identifiers/identifier"):
        if (identifier.get("type") or "").lower() != "barcode":
            continue
        digits = re.sub(r"\D", "", identifier.get("value") or "")
        if gtin_ok(digits):
            return digits
    return ""


def tracklist(release):
    """One track per line: position <TAB> title <TAB> duration. Headings are skipped."""
    lines = []
    for track in release.findall("tracklist/track"):
        title = text(track, "title")
        position = text(track, "position")
        if not title or (not position and not text(track, "duration")):
            continue
        lines.append("\t".join((position, title.replace("\t", " "), text(track, "duration"))))
    return "\n".join(lines)


def row(release):
    released = text(release, "released")
    labels = release.findall("labels/label")
    artists = [clean_name(text(a, "name")) for a in release.findall("artists/artist")]

    return {
        "release_id": release.get("id"),
        "artist": ", ".join(a for a in artists if a),
        "title": text(release, "title"),
        "year": released[:4] if released[:4].isdigit() else "",
        "country": text(release, "country"),
        "genres": " | ".join(text(g, ".") for g in release.findall("genres/genre") if text(g, ".")),
        "styles": " | ".join(text(s, ".") for s in release.findall("styles/style") if text(s, ".")),
        "barcode": barcode(release),
        "label": clean_name(labels[0].get("name")) if labels else "",
        "catno": (labels[0].get("catno") or "").strip() if labels else "",
        "tracklist": tracklist(release),
    }


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(1)

    ids_file, dump_file = sys.argv[1], sys.argv[2]
    output = sys.argv[3] if len(sys.argv) > 3 else "discogs-enrichment.csv"
    wanted = read_ids(ids_file)

    if not wanted:
        sys.exit("Error: no release ID found in " + ids_file)

    print("discogs_enrich %s: %d releases to find in %s" % (VERSION, len(wanted), dump_file))

    opener = gzip.open if dump_file.endswith(".gz") else open
    found, scanned, start = 0, 0, time.time()

    with opener(dump_file, "rb") as dump, open(output, "w", newline="", encoding="utf-8") as out:
        writer = csv.DictWriter(out, fieldnames=COLUMNS)
        writer.writeheader()
        context = ET.iterparse(dump, events=("start", "end"))
        _, root = next(context)

        for event, element in context:
            if event != "end" or element.tag != "release":
                continue

            scanned += 1
            release_id = element.get("id")

            if release_id in wanted:
                writer.writerow(row(element))
                wanted.discard(release_id)
                found += 1

            element.clear()
            root.clear()

            if scanned % 250000 == 0:
                minutes = (time.time() - start) / 60
                print("  %d releases read, %d found, %.0f min" % (scanned, found, minutes), flush=True)

            if not wanted:
                break

    print("Done: %d releases written to %s (%d releases read, %.0f min)." % (found, output, scanned, (time.time() - start) / 60))

    if wanted:
        print("Not found in this dump (%d): %s" % (len(wanted), ", ".join(sorted(wanted, key=int)[:20]) + (" ..." if len(wanted) > 20 else "")))


if __name__ == "__main__":
    main()
