<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejeu des écritures du mobile.
 *
 * Le réseau coupe, l'application renvoie la même requête sans savoir si la
 * première est passée. Trois cas :
 *
 * - clé inconnue : la requête s'exécute, et la réponse est enregistrée si elle
 *   aboutit (2xx) ;
 * - clé connue, même corps : la réponse enregistrée est rendue telle quelle,
 *   le contrôleur n'est pas appelé — une seule commande, un seul décrément de
 *   stock, le même code de retrait ;
 * - clé connue, corps différent : 409, rien n'est créé. Mieux vaut un échec
 *   visible qu'une commande silencieusement fausse.
 *
 * Une clé expirée se comporte comme absente. Générique : les recharges Wave
 * réutiliseront ce middleware sans modification.
 *
 * L'empreinte ne peut pas être celle du corps brut dès qu'une écriture porte
 * un fichier : un corps `multipart/form-data` embarque une frontière tirée au
 * hasard par le client, différente à chaque envoi, si bien que deux rejeux
 * identiques donneraient deux empreintes et que le second répondrait 409 —
 * exactement le cas que ce middleware existe pour couvrir. Un envoi multipart
 * est donc empreint sur ses champs normalisés et le contenu de ses fichiers,
 * ce qui est stable d'un rejeu à l'autre.
 *
 * Piège à connaître : le client de test de Laravel laisse `getContent()` vide
 * pour un multipart, donc un test qui poste un fichier ne reproduit *pas* le
 * corps réel et ne verrait jamais ce 409. Ne pas conclure de la suite verte
 * que le corps brut aurait suffi.
 */
class EnsureIdempotentRequest
{
    private const HEADER = 'Idempotency-Key';

    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || ! Str::isUuid($key)) {
            return new JsonResponse([
                'message' => __('api.invalid_data'),
                'errors' => ['Idempotency-Key' => [__('api.idempotency.key_required')]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $hash = $this->fingerprint($request);
        $existing = IdempotencyKey::query()->live()->where('key', $key)->first();

        if ($existing !== null) {
            if (! $existing->matches($hash)) {
                return new JsonResponse([
                    'message' => __('api.idempotency.key_reused'),
                    'errors' => ['Idempotency-Key' => [__('api.idempotency.key_reused')]],
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse($existing->response_body, $existing->response_status);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $driver = $request->user();

            // `updateOrCreate` plutôt que `create` : une clé périmée a laissé
            // sa ligne, et `key` est unique. On réécrit la trace, on n'en
            // ajoute pas une seconde.
            IdempotencyKey::query()->updateOrCreate(['key' => $key], [
                'driver_id' => $driver instanceof Driver ? $driver->getKey() : null,
                'request_hash' => $hash,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(assoc: true),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        }

        return $response;
    }

    /**
     * Empreinte de la requête, stable d'un rejeu à l'autre.
     *
     * Un corps JSON s'empreint tel quel — c'est le cas courant, et le plus
     * fidèle : deux corps qui ne diffèrent que par l'ordre des clés sont deux
     * requêtes différentes, et le rejeu d'un même envoi est octet pour octet
     * le même. Un envoi porteur de fichiers ne peut pas suivre cette voie (la
     * frontière multipart change à chaque envoi), il passe par ses champs.
     */
    private function fingerprint(Request $request): string
    {
        if ($request->files->count() === 0) {
            return hash('sha256', $request->getContent());
        }

        return $this->fingerprintMultipart($request);
    }

    /**
     * Empreinte d'un envoi multipart : les champs, triés par clé, et le
     * contenu de chaque fichier.
     *
     * Le contenu plutôt que le nom ou la taille : deux photos différentes du
     * même poids ne sont pas la même commande, et un client qui renomme son
     * fichier entre deux tentatives rejoue bien la même.
     */
    private function fingerprintMultipart(Request $request): string
    {
        $fields = $request->except(array_keys($request->allFiles()));
        $this->sortByKeyRecursive($fields);

        $files = [];

        foreach ($this->flattenFiles($request->allFiles()) as $name => $file) {
            $files[$name] = hash_file('sha256', $file->getPathname());
        }

        ksort($files);

        return hash('sha256', json_encode([
            'fields' => $fields,
            'files' => $files,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Aplatit `documents[0]`, `documents[1]` en clés lisibles, pour que
     * l'empreinte ne dépende pas de la forme du tableau imbriqué.
     *
     * @param  array<string, mixed>  $files
     * @return array<string, UploadedFile>
     */
    private function flattenFiles(array $files, string $prefix = ''): array
    {
        $flat = [];

        foreach ($files as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat = [...$flat, ...$this->flattenFiles($value, $name)];

                continue;
            }

            if ($value instanceof UploadedFile) {
                $flat[$name] = $value;
            }
        }

        return $flat;
    }

    /**
     * Trie un tableau par clés, en profondeur : l'empreinte ne doit pas
     * dépendre de l'ordre dans lequel le client a sérialisé ses champs.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function sortByKeyRecursive(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortByKeyRecursive($value);
            }
        }

        unset($value);

        ksort($data);
    }
}
