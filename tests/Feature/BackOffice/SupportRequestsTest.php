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
