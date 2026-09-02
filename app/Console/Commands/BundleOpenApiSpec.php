<?php

namespace App\Console\Commands;

use App\Support\Docs\OpenApiSpec;
use Illuminate\Console\Command;

/**
 * Assemble la spec découpée de `docs/api/` en un seul `openapi.json`.
 *
 * Ce fichier est l'artefact publié : il est committé pour qu'un changement de
 * contrat se lise comme un diff en revue, et `--check` (branché sur `composer
 * test`) échoue s'il est périmé.
 */
class BundleOpenApiSpec extends Command
{
    protected $signature = 'docs:bundle
                            {--check : Ne rien écrire ; échouer si le fichier committé est périmé.}';

    protected $description = 'Assembler docs/api/ en openapi.json';

    public function handle(OpenApiSpec $spec): int
    {
        $path = base_path('openapi.json');
        $bundled = $spec->toJson();

        if ($this->option('check')) {
            $committed = is_file($path) ? (string) file_get_contents($path) : '';

            if ($committed === $bundled) {
                $this->components->info('openapi.json est à jour.');

                return self::SUCCESS;
            }

            $this->components->error(
                'openapi.json est périmé. Lancer `composer docs` et committer le résultat.',
            );

            return self::FAILURE;
        }

        file_put_contents($path, $bundled);

        $this->components->info(sprintf(
            'openapi.json écrit (%d chemins).',
            count($spec->toArray()['paths'] ?? []),
        ));

        return self::SUCCESS;
    }
}
