# discogs_enrich 1.0.0

Outil compagnon de l'extension Wax Store Catalog (DS-Web).

Discogs publie chaque mois l'intégralité de sa base de données sous licence
CC0 (domaine public) sur https://data.discogs.com/. Cet outil lit le fichier
des parutions et n'en garde que les références de votre catalogue, pour
compléter : genres et styles, année, pays, code-barre et liste des titres.

Les images ne font pas partie de ces données libres et ne sont jamais récupérées.

## Prérequis

Python 3, déjà installé sous Linux et macOS. Sous Windows, installez-le depuis
https://www.python.org/downloads/ (cochez « Add Python to PATH ») et lancez
l'outil avec `py` au lieu de `python3`. Aucune bibliothèque à installer.

## Étapes

1. **Identifiants de votre catalogue** : dans WordPress, Disquaire > Outils >
   Import CSV, bouton « Télécharger les identifiants Discogs du catalogue ».
   Vous obtenez `discogs-ids-AAAA-MM-JJ.csv`. L'export d'inventaire Discogs
   (colonne `release_id`) fonctionne aussi.

2. **Fichier Discogs** : sur https://data.discogs.com/, ouvrez le mois le plus
   récent et téléchargez `discogs_AAAAMMJJ_releases.xml.gz`. Le fichier pèse
   plusieurs gigaoctets : inutile de le décompresser, l'outil le lit tel quel.

3. **Extraction** : dans un terminal, depuis le dossier de l'outil :

       python3 discogs_enrich.py discogs-ids-AAAA-MM-JJ.csv discogs_AAAAMMJJ_releases.xml.gz

   La progression s'affiche régulièrement. Selon l'ordinateur, comptez de
   quelques dizaines de minutes à environ une heure ; l'outil s'arrête dès
   qu'il a trouvé toutes vos références. Il produit `discogs-enrichment.csv`.

4. **Import** : Disquaire > Outils > Import CSV, envoyez `discogs-enrichment.csv`.
   Les colonnes sont reconnues automatiquement. L'import ne remplit que les
   champs vides des références existantes : il ne crée ni référence ni
   exemplaire, et ne remplace rien de ce que vous avez saisi.

## Sur un serveur avec WP-CLI

Si votre hébergement donne accès à WP-CLI (serveur dédié, VPS), une seule
commande fait tout, extraction et import :

    wp waxsc enrich /chemin/vers/discogs_AAAAMMJJ_releases.xml.gz

## Détails

- Les styles Discogs deviennent des sous-genres, rangés sous le premier genre
  de la parution (Rock > Prog Rock).
- Seuls les codes-barres dont la clé de contrôle est valide sont repris.
- Les intertitres de tracklist (« Side One ») sont ignorés.
- Les suffixes d'homonymie de Discogs sont retirés (« Nirvana (2) » devient « Nirvana »).

Licence : GPL-2.0 ou ultérieure.
