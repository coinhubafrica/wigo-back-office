<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use App\Support\Docs\ApiReference;
use App\Support\Docs\DocsGuide;
use App\Support\Docs\OpenApiSpec;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Publie le contrat de l'API mobile et ses guides.
 *
 * Rien n'est généré ici : le contrat est écrit à la main sous `docs/api/` et
 * les guides sont les fichiers Markdown de `docs/`. Les verrous d'accès sont
 * portés par les middlewares du groupe (voir `routes/docs.php`).
 *
 * La navigation est à trois niveaux : une vue d'ensemble, un index par tag,
 * puis une page par opération. Le regroupement vient de `ApiReference`, source
 * unique partagée avec la barre latérale et les tests.
 */
class DocsController extends Controller
{
    /**
     * Vue d'ensemble : métadonnées, description du contrat, table des tags.
     */
    public function reference(OpenApiSpec $spec): View
    {
        $reference = ApiReference::of($spec);

        return view('docs.reference', [
            'reference' => $reference,
            'spec' => $reference->document(),
            'guides' => DocsGuide::all(),
        ]);
    }

    /**
     * Index d'un tag : ses opérations en cartes, sans leur contenu.
     */
    public function tag(OpenApiSpec $spec, string $tag): View
    {
        $reference = ApiReference::of($spec);
        $current = $reference->tag($tag) ?? abort(HttpResponse::HTTP_NOT_FOUND);

        return view('docs.tag', [
            'reference' => $reference,
            'spec' => $reference->document(),
            'tag' => $current,
            'operations' => $reference->operations($tag),
            'guides' => DocsGuide::all(),
        ]);
    }

    /**
     * La page d'une opération : son contrat complet, puis le playground.
     */
    public function operation(OpenApiSpec $spec, string $tag, string $operation): View
    {
        $reference = ApiReference::of($spec);
        $entry = $reference->operation($operation) ?? abort(HttpResponse::HTTP_NOT_FOUND);

        // Une opération n'a qu'une URL : demandée sous un autre tag que le
        // sien, elle n'existe pas.
        abort_unless($entry['tagSlug'] === $tag, HttpResponse::HTTP_NOT_FOUND);

        return view('docs.operation', [
            'reference' => $reference,
            'spec' => $reference->document(),
            'tag' => $reference->tag($tag),
            'entry' => $entry,
            'guides' => DocsGuide::all(),
        ]);
    }

    /**
     * Le document assemblé, tel que le consomme l'application mobile.
     */
    public function spec(OpenApiSpec $spec): Response
    {
        return response($spec->toJson(), HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function guide(OpenApiSpec $spec, string $slug): View
    {
        $guide = DocsGuide::find($slug) ?? abort(HttpResponse::HTTP_NOT_FOUND);

        return view('docs.guide', [
            // La barre latérale liste les tags sur toutes les pages, guides
            // compris : la navigation est la même partout.
            'reference' => ApiReference::of($spec),
            'guide' => $guide,
            'guides' => DocsGuide::all(),
        ]);
    }
}
