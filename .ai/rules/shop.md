---
paths:
  - 'app/Livewire/Shop/**'
---

# Shop

## Boutique : périmètre retenu et droit d'écrire sur le stock
Hors périmètre volontairement : `carts`/`cart_items` (le mobile poste ses lignes directement, cf. `openapi.yaml`) et la facturation (`invoice_number`/`invoice_url` du MCD). Ne pas les ajouter sans demande.

Approvisionnement : le prototype code un `+10` en dur sans fenêtre. L'écran demande une quantité **et un motif** (README : « +n → stock_movements, audité »), et écrit un `StockMovement`.

Droits : `module.shop` ouvre le catalogue en lecture à `gestionnaire`, `stock` et `direction`. Écrire — approvisionner, créer/modifier/supprimer une pièce, déplacer une commande, gérer le référentiel — passe en plus par le Gate `manageStock` (`stock`|`direction`). La permission de module seule est trop large.

Pas de `wire:confirm` sur ces écrans (dialogue natif : bloque l'automatisation navigateur) — modale `$confirmingId` comme dans Annonces.
