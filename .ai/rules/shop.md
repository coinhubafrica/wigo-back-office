---
paths:
  - 'app/Livewire/Shop/**'
---

# Shop

## Boutique : périmètre retenu
Hors périmètre volontairement : `carts`/`cart_items` (le mobile poste ses lignes directement, cf. `openapi.yaml`) et la facturation (`invoice_number`/`invoice_url` du MCD). Ne pas les ajouter sans demande.

**Aucune gestion de stock.** La boutique est un catalogue de références et de prix : ni quantité, ni seuil d'alerte, ni mouvement. Une pièce porte `is_active`, que l'agent coche ou décoche depuis la fiche produit pour l'ouvrir ou la fermer à la commande — rien n'est recalculé. `stock_quantity`, `low_stock_threshold`, `products.status` (`active`/`out_of_stock`/`backorder`) et la table `stock_movements` ont été retirés ; ne pas les réintroduire, ni sous forme de colonne, ni d'accesseur.

Conséquence sur la commande : `ShopOrderService::place()` refuse une ligne dont la référence est inconnue ou fermée, et rien d'autre — aucune quantité n'est plafonnée et le catalogue n'a pas besoin de verrou. Annuler une commande ne rend rien au catalogue.

Droits : `module.shop` ouvre le catalogue en lecture à `gestionnaire`, `stock` et `direction`. Écrire — créer/modifier/supprimer une pièce, la fermer à la commande, déplacer une commande, gérer le référentiel — passe en plus par le Gate `manageCatalogue` (`stock`|`direction`). La permission de module seule est trop large. La clé du rôle reste `stock` (libellé « Gestionnaire catalogue ») : la renommer changerait un identifiant semé et les lignes de rôle existantes.

Pas de `wire:confirm` sur ces écrans (dialogue natif : bloque l'automatisation navigateur) — modale `$confirmingId` comme dans Annonces.
