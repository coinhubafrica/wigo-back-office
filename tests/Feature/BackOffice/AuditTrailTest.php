<?php

/**
 * Ce qui entre au journal, et ce qui n'y entre pas.
 *
 * Un geste mérite une ligne quand une personne raisonnable pourrait le
 * contester plus tard : il a déplacé de l'argent, coupé un revenu, changé qui
 * peut quoi, atteint tout le parc, ou il est irréversible. Un simple
 * enregistrement, un fait que l'état de l'objet consigne déjà, ou un geste
 * répété des dizaines de fois par jour, non — un journal trop plein ne se lit
 * pas, et un journal illisible ne prouve rien.
 *
 * Les cas négatifs de ce fichier sont donc aussi importants que les positifs :
 * ils écrivent la décision de *ne pas* journaliser, pour qu'un prochain
 * passage ne la défasse pas par zèle.
 */

use App\Enums\AuditAction;
use App\Enums\CampaignRecipientStatus;
use App\Enums\ShopOrderStatus;
use App\Livewire\Announcements\Index as AnnouncementsIndex;
use App\Livewire\Campaigns\Index as CampaignsIndex;
use App\Livewire\Campaigns\Show;
use App\Livewire\Challenges\Prizes as ChallengesPrizes;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Shop\Catalogue as ShopCatalogue;
use App\Livewire\Shop\Orders as ShopOrders;
use App\Livewire\SupportRequests\Templates as SupportTemplates;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Driver;
use App\Models\MessageTemplate;
use App\Models\Prize;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\User;
use App\Services\Support\CampaignDispatcher;
use App\Settings\FleetSettings;
use App\Settings\RechargeSettings;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// ------------------------------------------------------- secrets : l'essentiel

it('never writes a secret value to the journal', function (): void {
    /*
     * Le journal dit *qu'une* clé a changé et laquelle, jamais ce qu'elle vaut.
     * Une clé recopiée dans `context` serait lisible par quiconque atteint
     * l'écran d'audit, et l'export l'emporterait dans un fichier : l'outil de
     * surveillance deviendrait le point de fuite qu'il est censé surveiller.
     */
    Livewire::actingAs(trailUser('direction'))
        ->test(SettingsIndex::class)
        ->set('waveTopupApiKey', 'wave-sk-ultra-secrete')
        ->set('waveTopupWebhookSecret', 'whsec-ultra-secrete')
        ->call('saveWaveTopup');

    $line = AuditLog::query()->where('action', AuditAction::SettingsWaveTopupUpdated->value)->sole();

    expect($line->summary)->not->toContain('ultra-secrete')
        ->and(json_encode($line->context, JSON_UNESCAPED_UNICODE))->not->toContain('ultra-secrete')
        // Seulement des noms de champs.
        ->and($line->context['fields'])->toBe(['api_key', 'webhook_secret']);

    // Et nulle part ailleurs dans la table.
    expect(AuditLog::query()->where('summary', 'like', '%ultra-secrete%')->exists())->toBeFalse();
});

it('records only the names of the secret fields actually replaced', function (): void {
    Livewire::actingAs(trailUser('direction'))
        ->test(SettingsIndex::class)
        ->set('waveShopApiKey', 'seulement-la-cle')
        ->call('saveWaveShop');

    $line = AuditLog::query()->where('action', AuditAction::SettingsWaveShopUpdated->value)->sole();

    expect($line->context['fields'])->toBe(['api_key']);
});

it('records nothing when the secret fields are left blank', function (): void {
    // Enregistrer avec le champ vide conserve la clé en place : il n'y a rien
    // à annoncer.
    Livewire::actingAs(trailUser('direction'))
        ->test(SettingsIndex::class)
        ->call('saveWaveShop');

    expect(AuditLog::query()->where('action', AuditAction::SettingsWaveShopUpdated->value)->exists())
        ->toBeFalse();
});

// ------------------------------------------------------- réglages non secrets

it('journalises a change to the recharge caps with its before and after', function (): void {
    // Ces bornes décident de l'argent qu'un conducteur peut engager ; rien
    // n'étant secret, c'est le diff qui a du sens.
    app(RechargeSettings::class)->fill(['min_amount' => 500, 'max_amount' => 50000])->save();

    Livewire::actingAs(trailUser('direction'))
        ->test(SettingsIndex::class)
        ->set('rechargeMinAmount', 1000)
        ->call('saveRecharge');

    $line = AuditLog::query()->where('action', AuditAction::SettingsRechargeUpdated->value)->sole();

    expect($line->context['min_amount_before'])->toBe(500)
        ->and($line->context['min_amount_after'])->toBe(1000)
        // Ce qui n'a pas bougé n'encombre pas la ligne.
        ->and($line->context)->not->toHaveKey('max_amount_before');
});

it('journalises a change to the otp scale', function (): void {
    Livewire::actingAs(trailUser('direction'))
        ->test(SettingsIndex::class)
        ->set('otpMaxAttempts', 9)
        ->call('saveOtp');

    expect(AuditLog::query()->where('action', AuditAction::SettingsOtpUpdated->value)->exists())->toBeTrue();
});

it('records nothing when a scale is saved unchanged', function (): void {
    // Le journal dit ce qui a changé, pas qu'on a cliqué sur « Enregistrer ».
    Livewire::actingAs(trailUser('direction'))
        ->test(SettingsIndex::class)
        ->call('saveOtp');

    expect(AuditLog::query()->where('action', AuditAction::SettingsOtpUpdated->value)->exists())->toBeFalse();
});

it('journalises a change to the yango access without its key', function (): void {
    app(FleetSettings::class)->fill(['base_url' => 'https://ancien.example', 'park_id' => 'PARK-1'])->save();

    Livewire::actingAs(trailUser('direction'))
        ->test(SettingsIndex::class)
        ->set('fleetBaseUrl', 'https://nouveau.example')
        ->set('fleetApiKey', 'cle-yango-secrete')
        ->call('saveFleet');

    $line = AuditLog::query()->where('action', AuditAction::SettingsFleetUpdated->value)->sole();

    // L'adresse identifie *quel* parc on crédite : la détourner est une voie
    // d'exfiltration, elle est donc journalisée en clair. La clé, non.
    expect($line->context['base_url'])->toBe('https://nouveau.example')
        ->and($line->context['fields'])->toBe(['api_key'])
        ->and(json_encode($line->context))->not->toContain('cle-yango-secrete');
});

it('does not journalise a fleet connection test', function (): void {
    // Sonde sortante en lecture seule, cliquée en boucle pendant qu'on débogue
    // une clé : la journaliser enterrerait les lignes qui comptent.
    Livewire::actingAs(trailUser('direction'))
        ->test(SettingsIndex::class)
        ->call('testFleet');

    expect(AuditLog::query()->count())->toBe(0);
});

// ------------------------------------------------------- boutique

it('journalises a price change with its before and after', function (): void {
    $product = Product::factory()->create(['unit_price' => 10000, 'name' => 'Plaquette de frein']);

    Livewire::actingAs(trailUser('direction'))
        ->test(ShopCatalogue::class)
        ->call('edit', $product->id)
        ->set('unitPrice', 12500)
        ->call('save');

    $line = AuditLog::query()->where('action', AuditAction::ShopPriceChanged->value)->sole();

    expect($line->summary)->toContain('Plaquette de frein')
        ->and($line->context)->toMatchArray(['price_before' => 10000, 'price_after' => 12500]);
});

it('does not journalise a product save that leaves the price alone', function (): void {
    // Un prix est ce qu'un conducteur paie ; corriger un libellé laisse la
    // ligne elle-même comme preuve.
    $product = Product::factory()->create(['unit_price' => 10000, 'name' => 'Ancien nom']);

    Livewire::actingAs(trailUser('direction'))
        ->test(ShopCatalogue::class)
        ->call('edit', $product->id)
        ->set('name', 'Nouveau nom')
        ->call('save');

    expect(AuditLog::query()->where('action', AuditAction::ShopPriceChanged->value)->exists())->toBeFalse();
});

it('journalises deleting a product', function (): void {
    $product = Product::factory()->create(['name' => 'Filtre à huile', 'reference' => 'FIL-001']);

    Livewire::actingAs(trailUser('direction'))
        ->test(ShopCatalogue::class)
        ->call('confirmDelete', $product->id)
        ->call('delete');

    $line = AuditLog::query()->where('action', AuditAction::ShopProductDeleted->value)->sole();

    expect($line->summary)->toContain('Filtre à huile')
        ->and($line->context['reference'])->toBe('FIL-001');
});

it('journalises cancelling an order with its reason', function (): void {
    $driver = Driver::factory()->create();
    $order = ShopOrder::factory()->for($driver)->status(ShopOrderStatus::Ordered)->create();

    Livewire::actingAs(trailUser('direction'))
        ->test(ShopOrders::class)
        ->call('select', $order->id)
        ->set('cancelReason', 'Pièce indisponible chez le fournisseur')
        ->call('cancelOrder');

    $line = AuditLog::query()->where('action', AuditAction::ShopOrderCancelled->value)->sole();

    // Une annulation peut rembourser : le motif libre doit survivre.
    expect($line->context['reason'])->toBe('Pièce indisponible chez le fournisseur')
        ->and($line->driver_id)->toBe($driver->getKey());
});

it('does not journalise an order moving forward', function (): void {
    // Les transitions sont contraintes et horodatées sur la commande : la
    // ligne de commande *est* la piste d'audit.
    $order = ShopOrder::factory()->status(ShopOrderStatus::Ordered)->create();

    Livewire::actingAs(trailUser('direction'))
        ->test(ShopOrders::class)
        ->call('select', $order->id)
        ->call('markReady');

    expect(AuditLog::query()->count())->toBe(0);
});

// ------------------------------------------------------- requêtes

it('journalises deleting a reply template', function (): void {
    $template = MessageTemplate::factory()->create(['title' => 'Retard de paiement']);

    Livewire::actingAs(trailUser('direction'))
        ->test(SupportTemplates::class)
        ->call('confirmDelete', $template->id)
        ->call('delete');

    $line = AuditLog::query()->where('action', AuditAction::SupportTemplateDeleted->value)->sole();

    expect($line->summary)->toContain('Retard de paiement');
});

it('does not journalise saving or toggling a reply template', function (): void {
    // Outil interne de l'agent : aucun effet sur un conducteur tant qu'un
    // message n'est pas envoyé.
    $template = MessageTemplate::factory()->create();

    Livewire::actingAs(trailUser('direction'))
        ->test(SupportTemplates::class)
        ->call('toggle', $template->id);

    expect(AuditLog::query()->count())->toBe(0);
});

// ------------------------------------------------------- annonces, campagnes

it('does not journalise reordering the announcements', function (): void {
    /*
     * Une ligne par glisser-déposer, pour l'ordre cosmétique de bannières déjà
     * publiées : le candidat le plus bruyant de tout l'inventaire. La
     * publication, elle, est journalisée.
     */
    $first = Announcement::factory()->create(['order' => 1]);
    Announcement::factory()->create(['order' => 2]);

    Livewire::actingAs(trailUser('direction'))
        ->test(AnnouncementsIndex::class)
        ->call('reorder', $first->id, 1);

    expect(AuditLog::query()->count())->toBe(0);
});

it('does not journalise saving a campaign draft', function (): void {
    // Un brouillon n'atteint personne ; `campaign.sent` est le moment
    // irréversible, et il est déjà journalisé.
    Livewire::actingAs(trailUser('direction'))
        ->test(CampaignsIndex::class)
        ->set('title', 'Brouillon de test')
        ->set('body', 'Un corps de message suffisamment long pour valider.')
        ->call('saveDraft');

    expect(AuditLog::query()->where('action', AuditAction::CampaignSent->value)->exists())->toBeFalse();
});

it('does not journalise duplicating a campaign', function (): void {
    // Une copie est un brouillon : elle n'atteint personne, exactement comme
    // l'enregistrement d'un brouillon ou la duplication d'une annonce.
    $campaign = Campaign::factory()->create();

    Livewire::actingAs(trailUser('direction'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('duplicate');

    expect(AuditLog::query()->count())->toBe(0);
});

it('journalises replaying failed campaign deliveries', function (): void {
    // Un rejeu dépose un message chez un conducteur réel et le notifie : il
    // doit rester possible de dire qui l'a relancé.
    Driver::factory()->create();
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->materialise($campaign);
    $campaign->recipients()->sole()->forceFill([
        'status' => CampaignRecipientStatus::Failed,
    ])->save();

    Livewire::actingAs(trailUser('direction'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmReplayAll')
        ->call('replayAllFailures');

    $line = AuditLog::query()->where('action', AuditAction::CampaignRecipientsReplayed->value)->sole();

    expect($line->context['recipients'])->toBe(1);
});

// ------------------------------------------------------- challenges

it('journalises deleting a prize', function (): void {
    $prize = Prize::factory()->create(['name' => 'Bidon d\'huile']);

    Livewire::actingAs(trailUser('direction'))
        ->test(ChallengesPrizes::class)
        ->call('confirmDelete', $prize->id)
        ->call('delete');

    $line = AuditLog::query()->where('action', AuditAction::ChallengePrizeDeleted->value)->sole();

    expect($line->summary)->toContain("Bidon d'huile");
});

it('does not journalise renaming a prize', function (): void {
    // Le lot ne porte pas de valeur — elle vit sur le challenge — et renommer
    // laisse la ligne comme preuve.
    $prize = Prize::factory()->create(['name' => 'Ancien lot']);

    Livewire::actingAs(trailUser('direction'))
        ->test(ChallengesPrizes::class)
        ->call('edit', $prize->id)
        ->set('name', 'Nouveau lot')
        ->call('save');

    expect(AuditLog::query()->count())->toBe(0);
});

function trailUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
