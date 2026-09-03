<?php

/**
 * La file de traitement : ce qui entre en tri, ce qui devient un ticket, et ce
 * que le conducteur n'en voit jamais.
 */

use App\Enums\BackOfficeModule;
use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestPriority;
use App\Enums\SupportRequestStatus;
use App\Livewire\SupportRequests\Index;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\MessageTemplate;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Support\MessageService;
use App\Services\Support\SupportRequestService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('lets an authorised user reach the queue', function (): void {
    $this->actingAs(supportUser('gestionnaire'))
        ->get(route(BackOfficeModule::SupportRequests->route()))
        ->assertOk();
});

it('refuses a user without the permission', function (): void {
    // `admin` n'a pas `module.support-requests` : l'URL directe est refusée.
    $this->actingAs(supportUser('admin'))
        ->get(route(BackOfficeModule::SupportRequests->route()))
        ->assertForbidden();
});

it('counts the conversations awaiting triage', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->assertViewHas('triageCount', 1);
});

it('drops a conversation from triage once it carries a ticket', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Payment,
        supportUser('gestionnaire'),
    );

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->assertViewHas('triageCount', 0)
        ->assertViewHas('ticketCount', 1);
});

it('orders the triage queue oldest first', function (): void {
    // Celui qui attend depuis le plus longtemps passe devant : c'est une file.
    $older = Driver::factory()->create(['first_name' => 'Ancien']);
    $newer = Driver::factory()->create(['first_name' => 'Recent']);
    $messages = app(MessageService::class);

    $first = $messages->sendFromDriver($older, 'Premier arrivé');
    $messages->sendFromDriver($newer, 'Arrivé ensuite');

    Conversation::query()->where('driver_id', $older->id)->sole()
        ->forceFill(['last_message_at' => now()->subHours(5)])->save();

    $rows = Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->viewData('rows');

    expect($rows->first()->driver_id)->toBe($older->id);
});

it('creates a ticket from triage and attaches the untriaged messages', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->call('openTicketForm')
        ->assertSet('creatingTicket', true)
        ->set('ticketCategory', SupportRequestCategory::Payment->value)
        ->set('ticketSubject', 'Solde non crédité')
        ->call('createTicket')
        ->assertHasNoErrors()
        ->assertSet('creatingTicket', false);

    $request = SupportRequest::query()->sole();
    expect($request->subject)->toBe('Solde non crédité')
        ->and($request->category)->toBe(SupportRequestCategory::Payment)
        ->and($conversation->messages()->whereNull('support_request_id')->whereNull('triaged_at')->count())->toBe(0);
});

it('derives the priority from the category rather than asking for it', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->call('openTicketForm')
        ->set('ticketCategory', SupportRequestCategory::Payment->value)
        ->call('createTicket');

    expect(SupportRequest::query()->sole()->priority)->toBe(SupportRequestPriority::High);
});

it('exposes no priority field on the component', function (): void {
    // La priorité découle de la catégorie : l'agent ne doit pas pouvoir la
    // fixer, ni depuis l'écran ni en poussant une propriété Livewire.
    expect(property_exists(Index::class, 'ticketPriority'))->toBeFalse()
        ->and(property_exists(Index::class, 'priority'))->toBeFalse();
});

it('dismisses untriaged messages without creating a ticket', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Merci beaucoup !');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->call('confirmDismiss', $conversation->id)
        ->assertSet('confirmingDismiss', $conversation->id)
        ->call('dismiss')
        ->assertSet('confirmingDismiss', null);

    expect(SupportRequest::query()->count())->toBe(0)
        // Le message reste dans le fil du conducteur.
        ->and($conversation->messages()->count())->toBe(1);
});

it('replies without a ticket and keeps the answer in the thread', function (): void {
    // Le geste que l'écran promettait sans le rendre : répondre sans ouvrir de
    // dossier. La réponse part, et rien ne disparaît sans laisser de trace.
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'À quelle heure ouvre le magasin ?');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = supportUser('gestionnaire');

    $component = Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->set('triageDraft', 'De 8h à 18h.')
        ->call('sendTriageReply')
        ->assertHasNoErrors()
        ->assertSet('triageDraft', '');

    $reply = $conversation->messages()->where('sender_type', 'user')->sole();

    expect(SupportRequest::query()->count())->toBe(0)
        ->and($reply->support_request_id)->toBeNull()
        ->and($reply->body)->toBe('De 8h à 18h.')
        // La réponse est lisible là où l'agent vient de l'écrire.
        ->and($component->viewData('thread')->pluck('body'))->toContain('De 8h à 18h.');
});

it('keeps the answered conversation in triage until an agent clears it', function (): void {
    // Répondre ne tranche pas : l'agent attend le retour du conducteur, puis
    // écarte ou ouvre un ticket. Trier à l'envoi faisait disparaître de
    // l'écran ce qu'on venait d'écrire.
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question simple');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    $component = Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->assertViewHas('triageCount', 1)
        ->set('triageDraft', 'Voici la réponse.')
        ->call('sendTriageReply');

    // La question du conducteur attend toujours ; la réponse de l'agent ne se
    // compte pas elle-même dans la bannière.
    expect($component->viewData('triageCount'))->toBe(1)
        ->and($component->viewData('untriagedCount'))->toBe(1)
        ->and($conversation->messages()->whereNull('triaged_at')->pluck('sender_type')->all())->toBe(['driver']);

    // C'est « Retirer de la file » qui tranche, pas l'envoi.
    $component->call('confirmDismiss', $conversation->id)->call('dismiss');

    expect($component->viewData('triageCount'))->toBe(0)
        ->and(SupportRequest::query()->count())->toBe(0);
});

it('refuses an empty reply without a ticket', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->set('triageDraft', '')
        ->call('sendTriageReply')
        ->assertHasErrors('triageDraft');

    expect(Conversation::query()->sole()->messages()->where('sender_type', 'user')->count())->toBe(0);
});

it('offers a composer on a conversation that has no ticket', function (): void {
    // Le symptôme d'origine : rien pour écrire, et le message hors de vue.
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->assertSee(__('backoffice.support_requests.reply_without_ticket'))
        ->assertSee(__('backoffice.support_requests.dismiss'));
});

it('assigns the ticket to the agent who triaged it', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = supportUser('gestionnaire');

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->call('openTicketForm')
        ->set('ticketCategory', SupportRequestCategory::Payment->value)
        ->call('createTicket');

    // Un ticket sans propriétaire n'est réclamé par personne.
    expect(SupportRequest::query()->sole()->assigned_user_id)->toBe($agent->id);
});

it('lets the direction hand a ticket to another agent', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $head = supportUser('direction');
    $other = supportUser('gestionnaire');
    $request = app(SupportRequestService::class)->createFromTriage($conversation, SupportRequestCategory::Other, $head);

    Livewire::actingAs($head)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->call('reassign', $other->id);

    expect($request->fresh()->assigned_user_id)->toBe($other->id);
});

it('refuses a reassignment from an agent who is not the direction', function (): void {
    // Répartir la charge de l'équipe est un acte d'encadrement : masquer le
    // sélecteur ne suffit pas, l'appel direct doit être refusé.
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = supportUser('gestionnaire');
    $other = supportUser('gestionnaire');
    $request = app(SupportRequestService::class)->createFromTriage($conversation, SupportRequestCategory::Other, $agent);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->call('reassign', $other->id)
        ->assertForbidden();

    expect($request->fresh()->assigned_user_id)->toBe($agent->id);
});

it('refuses to assign a ticket to someone outside the module', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $head = supportUser('direction');
    // `admin` n'a pas `module.support-requests` : il ne peut rien traiter.
    $outsider = supportUser('admin');
    $request = app(SupportRequestService::class)->createFromTriage($conversation, SupportRequestCategory::Other, $head);

    Livewire::actingAs($head)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->call('reassign', $outsider->id);

    expect($request->fresh()->assigned_user_id)->toBe($head->id);
});

it('hides the reassignment control from an ordinary agent', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = supportUser('gestionnaire');
    app(SupportRequestService::class)->createFromTriage($conversation, SupportRequestCategory::Other, $agent);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->assertDontSee(__('backoffice.support_requests.reassign'))
        // Reprendre un ticket à son compte reste ouvert à tous.
        ->assertSee(__('backoffice.support_requests.assign_to_me'));
});

it('opens on the state of the queue', function (): void {
    // Les quatre libellés doivent être rendus : ils sont restés clés mortes
    // dans le fichier de langue le temps d'une version.
    $agent = supportUser('gestionnaire');
    $mine = SupportRequest::factory()->create([
        'assigned_user_id' => $agent->id,
        'status' => SupportRequestStatus::Open,
        'first_response_at' => null,
        'sla_first_response_due' => now()->subHour(),
        'sla_resolution_due' => now()->addDay(),
    ]);
    SupportRequest::factory()->create([
        'assigned_user_id' => null,
        'status' => SupportRequestStatus::Open,
        'first_response_at' => now(),
        'sla_first_response_due' => now()->addHour(),
        'sla_resolution_due' => now()->addDay(),
    ]);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->assertViewHas('ticketCount', 2)
        ->assertViewHas('breachedCount', 1)
        ->assertViewHas('mineCount', 1)
        ->assertSee(__('backoffice.support_requests.kpi_triage'))
        ->assertSee(__('backoffice.support_requests.kpi_tickets'))
        ->assertSee(__('backoffice.support_requests.kpi_breached'))
        ->assertSee(__('backoffice.support_requests.kpi_mine'));

    expect($mine->fresh()->assigned_user_id)->toBe($agent->id);
});

it('counts only live tickets in the queue health', function (): void {
    // Un ticket résolu hors délai n'est plus en souffrance, et n'incombe plus.
    $agent = supportUser('gestionnaire');
    SupportRequest::factory()->create([
        'assigned_user_id' => $agent->id,
        'status' => SupportRequestStatus::Resolved,
        'resolved_at' => now(),
        'first_response_at' => now(),
        'sla_first_response_due' => now()->subDay(),
        'sla_resolution_due' => now()->subHour(),
    ]);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->assertViewHas('ticketCount', 0)
        ->assertViewHas('breachedCount', 0)
        ->assertViewHas('mineCount', 0);
});

it('marks the open conversation for a screen reader', function (): void {
    // La teinte de la ligne sélectionnée ne suffit pas : c'est la seule
    // information qui dise quel fil est ouvert à droite.
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    $component = Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class);

    $component->assertDontSee('aria-current', escape: false);

    $component->call('select', $conversation->id)
        ->assertSee('aria-current="true"', escape: false);
});

it('spells out what each ticket status means', function (): void {
    // « Ouverte »/« En attente » et « Résolue »/« Fermée » se lisent sinon
    // comme deux paires de synonymes.
    $component = Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->set('tab', 'tickets');

    foreach (SupportRequestStatus::cases() as $case) {
        $component->assertSee($case->label())->assertSee($case->hint());
    }
});

it('shows the whole conversation history not just the current ticket', function (): void {
    // L'agent ne doit pas faire répéter le conducteur.
    $driver = Driver::factory()->create();
    $messages = app(MessageService::class);
    $requests = app(SupportRequestService::class);
    $agent = supportUser('gestionnaire');

    $messages->sendFromDriver($driver, 'Première demande');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $old = $requests->createFromTriage($conversation, SupportRequestCategory::Shop, $agent);
    $requests->resolve($old);

    $messages->sendFromDriver($driver->fresh(), 'Deuxième demande');
    $requests->createFromTriage($conversation->fresh(), SupportRequestCategory::Payment, $agent);

    $thread = Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->viewData('thread');

    expect($thread->pluck('body'))->toContain('Première demande')
        ->and($thread->pluck('body'))->toContain('Deuxième demande');
});

it('loads older messages thirty at a time', function (): void {
    $driver = Driver::factory()->create();
    $messages = app(MessageService::class);

    foreach (range(1, 45) as $i) {
        $messages->sendFromDriver($driver, "Message {$i}");
    }

    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    $component = Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $conversation->id);

    expect($component->viewData('thread'))->toHaveCount(30)
        ->and($component->viewData('hasOlder'))->toBeTrue();

    $component->call('loadOlder');

    expect($component->viewData('thread'))->toHaveCount(45)
        ->and($component->viewData('hasOlder'))->toBeFalse();
});

it('sends a reply and clears the composer', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = supportUser('gestionnaire');
    app(SupportRequestService::class)->createFromTriage($conversation, SupportRequestCategory::Other, $agent);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->set('draft', 'Nous regardons cela.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('draft', '');

    expect($conversation->messages()->where('sender_type', 'user')->count())->toBe(1);
});

it('refuses an empty reply', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = supportUser('gestionnaire');
    app(SupportRequestService::class)->createFromTriage($conversation, SupportRequestCategory::Other, $agent);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->set('draft', '')
        ->call('send')
        ->assertHasErrors('draft');
});

it('fills the composer from a template and records the use', function (): void {
    $template = MessageTemplate::factory()->create([
        'body' => 'Votre remboursement est en cours.',
        'usage_count' => 0,
        'is_active' => true,
    ]);

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('useTemplate', $template->id)
        ->assertSet('draft', 'Votre remboursement est en cours.');

    // Compté à l'insertion : l'agent retouche souvent avant d'envoyer.
    expect($template->fresh()->usage_count)->toBe(1);
});

it('asks for confirmation before resolving', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = supportUser('gestionnaire');
    $request = app(SupportRequestService::class)->createFromTriage($conversation, SupportRequestCategory::Other, $agent);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->assertSet('confirmingResolve', null)
        ->call('confirmResolve', $request->id)
        ->assertSet('confirmingResolve', $request->id)
        ->call('resolve')
        ->assertSet('confirmingResolve', null);

    expect($request->fresh()->status)->toBe(SupportRequestStatus::Resolved);
});

it('subscribes to the channels the server authorises', function (): void {
    // Les noms de canaux sont écrits deux fois : dans `resources/js/app.js` et
    // dans `routes/channels.php`. Renommer l'un sans l'autre couperait le
    // temps réel sans rien casser d'autre, donc sans que rien ne le signale.
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect($script)->toContain("Echo.private('support-queue')")
        ->and($script)->toContain('Echo.private(`conversation.${id}`)');

    // Et le composant est bien monté sur la page.
    $this->actingAs(supportUser('gestionnaire'))
        ->get(route(BackOfficeModule::SupportRequests->route()))
        ->assertOk()
        ->assertSee('supportRealtime(', escape: false);
});

it('keeps a poll as a fallback when the socket drops', function (): void {
    // Un websocket tombé ne doit pas faire disparaître une requête de la file.
    $this->actingAs(supportUser('gestionnaire'))
        ->get(route(BackOfficeModule::SupportRequests->route()))
        ->assertOk()
        ->assertSee('wire:poll.60s', escape: false);
});

it('never uses the native confirm dialog', function (): void {
    // Le dialogue natif bloque l'automatisation navigateur.
    $this->actingAs(supportUser('gestionnaire'))
        ->get(route(BackOfficeModule::SupportRequests->route()))
        ->assertOk()
        ->assertDontSee('wire:confirm');
});

it('marks the drivers messages as read when the agent opens the thread', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = supportUser('gestionnaire');
    $request = app(SupportRequestService::class)->createFromTriage($conversation, SupportRequestCategory::Other, $agent);

    expect($request->fresh()->staff_unread_count)->toBeGreaterThan(0);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('select', $conversation->id);

    expect($request->fresh()->staff_unread_count)->toBe(0);
});

it('filters the tickets down to the late ones', function (): void {
    $agent = supportUser('gestionnaire');
    $onTime = SupportRequest::factory()->create([
        'first_response_at' => null,
        'sla_first_response_due' => now()->addHours(3),
        'sla_resolution_due' => now()->addDay(),
    ]);
    $late = SupportRequest::factory()->create([
        'first_response_at' => null,
        'sla_first_response_due' => now()->subHour(),
        'sla_resolution_due' => now()->addDay(),
    ]);

    $rows = Livewire::actingAs($agent)
        ->test(Index::class)
        ->set('tab', 'tickets')
        ->set('breachedOnly', true)
        ->viewData('rows');

    expect($rows->pluck('id')->all())->toBe([$late->id]);
});

it('filters the tickets assigned to me', function (): void {
    $agent = supportUser('gestionnaire');
    $mine = SupportRequest::factory()->create(['assigned_user_id' => $agent->id]);
    SupportRequest::factory()->create(['assigned_user_id' => null]);

    $rows = Livewire::actingAs($agent)
        ->test(Index::class)
        ->set('tab', 'tickets')
        ->set('assigned', 'me')
        ->viewData('rows');

    expect($rows->pluck('id')->all())->toBe([$mine->id]);
});

it('returns to the first page when a filter changes', function (): void {
    // Filtrer depuis la page 3 sans revenir en page 1 afficherait une liste
    // vide alors que des résultats existent.
    SupportRequest::factory()->count(25)->create();

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->set('tab', 'tickets')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->call('filterByStatus', SupportRequestStatus::Open->value)
        ->assertSet('paginators.page', 1);
});

it('clears the assignment filter rather than leaving an empty string', function (): void {
    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('toggleAssignedToMe')
        ->assertSet('assigned', 'me')
        ->call('toggleAssignedToMe')
        ->assertSet('assigned', null);
});

it('searches a driver by phone', function (): void {
    $wanted = Driver::factory()->create(['phone' => '+2250700009999']);
    $other = Driver::factory()->create(['phone' => '+2250700001111']);
    $messages = app(MessageService::class);
    $messages->sendFromDriver($wanted, 'Trouvez-moi');
    $messages->sendFromDriver($other, 'Pas moi');

    $rows = Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->set('search', '0700009999')
        ->viewData('rows');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->driver_id)->toBe($wanted->id);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function supportUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}

it('guards the send button and closes the templates list from its own method', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->for($driver)->create();
    $request = SupportRequest::factory()->for($conversation)->create([
        'status' => SupportRequestStatus::Open,
    ]);

    Livewire::actingAs(supportUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $conversation->id)
        ->assertSeeHtml('wire:target="send"')
        ->set('templatesOpen', true)
        ->assertSeeHtml('$wire.closeTemplates()')
        ->call('closeTemplates')
        ->assertSet('templatesOpen', false);
});
