# Paquets cartographiques

MapLibre GL 0.27.1 utilise `pmtiles://file:///…` pour les archives locales. Le style est un chemin absolu de fichier ; il ne doit pas recevoir un préfixe `file://` lui-même. Sources : [PMTiles Flutter](https://maplibre.org/flutter-maplibre-gl/advanced/pmtiles/), [ressources locales](https://maplibre.org/flutter-maplibre-gl/concepts/styles/#local-sprites-and-glyphs).

## Construire un paquet

Préparer une archive **PMTiles v3 vectorielle au schéma Protomaps** couvrant la région, par extraction d’un jeu dont la licence autorise cette distribution ou par génération OSM avec Planetiler. `.map` Mapsforge n’est pas compatible. Ne jamais précharger massivement `tile.openstreetmap.org`.

Python 3.11+ (bibliothèque standard), depuis `backend/` (ou `gpix-server/` après extraction) :

```sh
python3 tools/package_region.py \
  --pmtiles /srv/source/region.pmtiles \
  --id pays-basque --name 'Pays basque' --version 2026-09 \
  --base-url https://rando.votre-domaine.fr --output data/maps
```

Le script crée un ZIP non recompressé (PMTiles contient déjà des tuiles compressées), le manifeste des fichiers SHA-256, puis publie atomiquement le catalogue avec taille, empreinte, version et enveloppe régionale. Vérifier que l’enveloppe PMTiles représente une zone intégralement préparée aux zooms utiles : une enveloppe ne prouve pas l’absence de trous dans le jeu amont. Le catalogue personnel est la source de vérité pour cette couverture. Les longs parcours traversant plusieurs enveloppes sont évalués par union, y compris les arêtes entre points GPX éloignés.

Le style par défaut est autonome : eau, bâtiments, routes et sentiers, sans texte/sprites. Pour un style plus complet, `--resources /srv/style` copie un arbre comprenant `style.json`, glyphes et sprites. Toutes les références doivent être `bundle://…`, par exemple `pmtiles://bundle://map.pmtiles` et `bundle://glyphs/{fontstack}/{range}.pbf`. Chaque police déclarée doit contenir les 256 plages BMP `0-255.pbf` à `65280-65535.pbf`. Sprites : `.json`, `.png`, `@2x.json`, `@2x.png`. Les régions à combiner doivent utiliser le même jeu de polices/sprites (la carte composée utilise ceux de la première région).

Le ZIP contient `bundle.json` avec `files` (chemin relatif → SHA-256) et `fonts` (noms des répertoires de police). Aucun chemin parent, lien symbolique ou source réseau autorisé. Le téléphone télécharge vers `.part`, vérifie taille et hash, décompresse dans `.staging`, vérifie toutes les ressources et l’en-tête PMTiles, puis publie la version complète avant d’enregistrer sa disponibilité. Une interruption conserve l’ancienne version. Un téléchargement interrompu recommence depuis zéro à la prochaine tentative ; pas de reprise HTTP Range encore implémentée.

Les versions précédentes non référencées peuvent actuellement rester sur disque après une mise à jour : le nettoyage automatique de ces versions est à compléter. La suppression d’une carte active est disponible et signale les parcours concernés. Prévoir de la place pour téléchargement + extraction + ancienne version. L’application affiche les erreurs d’espace disque mais n’estime pas encore l’espace libre avant transfert.

## Démonstration

`mobile/assets/demo` fournit une archive réelle de Florence (~6,6 Mo), issue du dépôt officiel PMTiles/Protomaps, sous ODbL pour les données OSM. Son GPX et ses altitudes sont synthétiques, nommés « Démonstration ». Ce jeu ne représente pas Compostelle et ne doit pas servir de randonnée. Aucun fond de carte de production n’est présenté comme déjà préparé.

## Altitudes

Le profil GPX est conservé tel qu’importé, avec segments et lacunes. L’adaptateur de complément demande uniquement les altitudes manquantes au serveur configuré puis stocke le résultat localement, marqué estimé. Une lacune du DEM reste nulle. Aucun dénivelé cumulé potentiellement gonflé n’est affiché. Le lissage avancé et les courbes de niveau cartographiques ne sont pas livrés dans cette première version.
