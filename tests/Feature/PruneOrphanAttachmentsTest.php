<?php

/**
 * La purge quotidienne des pièces jointes jamais rattachées.
 *
 * Le piège qu'elle doit éviter : le fichier d'une campagne est partagé par
 * tous ses messages, donc une seule ligne orpheline emporterait l'image de
 * l'envoi entier.
 */

use App\Models\Campaign;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Rejoue la tâche planifiée telle qu'elle est définie dans `routes/console.php`.
 */
function runOrphanPrune(): void
{
    $events = app(Schedule::class)->events();

    $prune = collect($events)->firstOrFail(
        fn ($event): bool => $event->description === 'support:prune-orphan-attachments'
    );

    $prune->run(app());
}

it('deletes an orphan attachment and its file', function (): void {
    Storage::disk('local')->put('support-attachments/perdue.jpg', 'binaire');

    MessageAttachment::factory()->create([
        'message_id' => null,
        'disk' => 'local',
        'path' => 'support-attachments/perdue.jpg',
        'created_at' => now()->subDays(2),
    ]);

    runOrphanPrune();

    expect(MessageAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('support-attachments/perdue.jpg');
});

it('keeps a campaign image even when one of its attachment rows is orphaned', function (): void {
    // Une remise interrompue entre la création de la ligne et son rattachement
    // laisse une orpheline. Supprimer son fichier casserait tous les messages
    // déjà déposés par cette campagne, et son écran de détail.
    Storage::disk('local')->put('campaigns/visuel.jpg', 'binaire');

    $campaign = Campaign::factory()->create([
        'image_disk' => 'local',
        'image_path' => 'campaigns/visuel.jpg',
        'image_name' => 'visuel.jpg',
        'image_mime' => 'image/jpeg',
        'image_size_bytes' => 2048,
    ]);

    MessageAttachment::factory()->create([
        'message_id' => null,
        'disk' => 'local',
        'path' => $campaign->image_path,
        'created_at' => now()->subDays(2),
    ]);

    runOrphanPrune();

    // La ligne part — elle ne sert plus à rien — mais le fichier reste.
    expect(MessageAttachment::query()->whereNull('message_id')->count())->toBe(0);
    Storage::disk('local')->assertExists('campaigns/visuel.jpg');
});

it('leaves an attached piece alone', function (): void {
    Storage::disk('local')->put('support-attachments/gardee.jpg', 'binaire');

    MessageAttachment::factory()->create([
        'message_id' => Message::factory(),
        'disk' => 'local',
        'path' => 'support-attachments/gardee.jpg',
        'created_at' => now()->subDays(2),
    ]);

    runOrphanPrune();

    expect(MessageAttachment::query()->count())->toBe(1);
    Storage::disk('local')->assertExists('support-attachments/gardee.jpg');
});
