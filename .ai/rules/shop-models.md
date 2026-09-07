---
paths:
  - 'app/Http/Controllers/Api/V1/ShopController.php,app/Services/Shop/**,app/Models/ShopOrderDocument.php,app/Http/Middleware/EnsureIdempotentRequest.php'
---

# Shop Models

## La carte grise part avec la commande, en un seul appel
`POST /shop/orders` est un envoi `multipart/form-data` : les lignes, le mode de réception, et `documents[]` — exactement deux photos de la carte grise. Il n'existe **pas** d'endpoint de téléversement séparé, et **aucun champ ne déclare « recto » ou « verso »** : la face se lit sur l'image, pas sur un champ que le conducteur pourrait renseigner à l'envers. Ne pas réintroduire l'un ou l'autre.

Conséquences : `shop_order_documents.shop_order_id` est **non nul** (aucune photo n'attend de rattachement, donc rien à purger — il n'y a pas de tâche planifiée ici) ; `ShopOrderService::storeDocuments()` écrit les fichiers dans la transaction de la commande, si bien qu'une référence fermée n'en laisse aucune trace en base.

Fichier privé (disque `local`), servi par URL signée côté mobile (`api.v1.shop.orders.documents.show`, le déposant seul) et par `bo.shop-orders.document` côté back-office (permission du module Commandes) — jamais par son chemin. Images seules (jpg/png/webp, 5 Mo) : pas de PDF, aucun antivirus dans la chaîne. Inconnu comme interdit répondent 403, jamais 404, pour ne pas dire quels identifiants existent.

## L'empreinte d'idempotence n'est pas le corps brut dès qu'il y a un fichier
`EnsureIdempotentRequest` empreinte un corps JSON sur `getContent()` (cas courant, inchangé), mais un envoi porteur de fichiers sur **ses champs normalisés + le hash du contenu de chaque fichier** (`fingerprintMultipart()`).

Raison, vérifiée : un corps `multipart/form-data` embarque une frontière tirée au hasard par le client, différente à chaque envoi. Empreinte prise sur le corps brut, deux rejeux identiques donnaient deux empreintes et le second répondait **409** — précisément ce que l'idempotence existe pour éviter. Ne pas « simplifier » ce calcul en revenant au corps brut.

**Piège majeur : la suite de tests ne peut pas attraper cette régression par un test HTTP ordinaire.** Le client de test de Laravel laisse `getContent()` **vide** pour un envoi de fichiers, donc un `->post(..., ['documents' => [UploadedFile::fake()]])` passe au vert même avec le calcul cassé. C'est pourquoi `tests/Feature/Http/IdempotencyFingerprintTest.php` construit le corps multipart **à la main**, frontière comprise, et appelle `fingerprint()` par réflexion. Vérifié : ce fichier échoue (2 cas) si l'on rétablit le corps brut.
