---
paths:
  - 'app/Livewire/Announcements/**'
---

# Announcements

## Schema is deliberately smaller than the MCD: no placement, no target_link, no tracking counters
The MCD lists `placement`, `target_link`, `impressions_count`, `clicks_count` on `announcement`. All four were dropped per explicit user instruction — there is only one placement (the mobile home screen), no tap-through link is used, and no impression/click tracking exists. Don't re-add any of them without being asked; the README's "3 image placements + 1 video" describes prototype seed content, not a real requirement.

The `display_order` column was renamed to `order` (also per explicit instruction — "simpler"). `order` is a SQL reserved word; Laravel's query builder quotes identifiers so `orderBy('order')` / `->max('order')` work fine, but never interpolate it into raw SQL unquoted.

`starts_at`/`ends_at` still exist on the table (MCD-driven) but the BO form only exposes a "Publier immédiatement" checkbox (`is_active`) — matches the prototype UI exactly. The scheduling window is enforced by `Announcement::isCurrentlyPublished()` and the API's `AnnouncementController@index` query, but nothing in the BO currently sets those two columns; they stay null until a form field is added for them.

Media storage uses the **default** filesystem disk (`Storage::url()` / `->store(path: 'announcements')`, no disk hardcoded) — switching `FILESYSTEM_DISK` in `.env` (public locally, s3 in staging/prod) changes behavior with zero code changes. Local `.env` was set to `public` (with `storage:link` run) so uploads work in dev without AWS credentials; `.env.example` was deliberately left as Laravel's stock `local` default — it was never wired to WiGO's actual per-environment config, don't repoint it here without being asked. **Laravel Cloud's S3 bucket/credentials still need to be provisioned for staging/prod** — that's an external console action, not something committed in this repo.

`AnnouncementController@index`'s collection response is wrapped in `data` (Laravel's `AnonymousResourceCollection` default) — unlike `DriverResource`'s single-item responses (`$wrap = null`), collection wrap isn't overridden here. Both are intentional per-endpoint choices, not an inconsistency to "fix."

## Le type de média se déduit du fichier ; la durée de diapositive est saisie
Le formulaire ne demande plus « image ou vidéo » : `AnnouncementMediaType::fromMimeType()` lit le type MIME du fichier téléversé (`video/*` → Video, tout le reste → Image). Demander le type en plus du média laissait les deux se contredire. Le MIME est reniflé sur le contenu, pas sur l'extension : une vidéo renommée `.jpg` reste une vidéo — ne pas repasser sur `getClientOriginalExtension()`, qui ne lit qu'un bout du nom. À la modification sans nouveau fichier, `media_type` n'est pas réécrit — le type enregistré reste le bon.

`announcements.duration` (secondes, défaut 5, borné 1..60) est la durée d'affichage de la diapositive sur le carrousel de l'accueil mobile. Exigée pour les **deux** types de média, y compris la vidéo : le client fait défiler au bout de ce délai sans lire les métadonnées ni distinguer les cas, donc `duration` n'est jamais nul au contrat. Une vidéo plus longue que sa durée est coupée — c'est le choix assumé.
