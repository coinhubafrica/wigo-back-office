<?php

/**
 * Réponses types : les phrases que les agents écrivent dix fois par jour.
 */

use App\Livewire\SupportRequests\Templates;
use App\Models\MessageTemplate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('lets an authorised user reach the templates', function (): void {
    $this->actingAs(templatesUser('gestionnaire'))
        ->get(route('bo.support-requests.templates'))
        ->assertOk();
});

it('refuses a user without the support permission', function (): void {
    $this->actingAs(templatesUser('admin'))
        ->get(route('bo.support-requests.templates'))
        ->assertForbidden();
});

it('creates a template and records its author', function (): void {
    $agent = templatesUser('gestionnaire');

    Livewire::actingAs($agent)
        ->test(Templates::class)
        ->call('newTemplate')
        ->set('title', 'Remboursement')
        ->set('body', 'Votre remboursement est en cours.')
        ->set('shortcut', '/remb')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('formOpen', false);

    $template = MessageTemplate::query()->sole();
    expect($template->title)->toBe('Remboursement')
        ->and($template->created_by_user_id)->toBe($agent->id);
});

it('keeps the original author when another agent edits', function (): void {
    $author = templatesUser('gestionnaire');
    $editor = templatesUser('gestionnaire');
    $template = MessageTemplate::factory()->create(['created_by_user_id' => $author->id]);

    Livewire::actingAs($editor)
        ->test(Templates::class)
        ->call('edit', $template->id)
        ->set('title', 'Titre corrigé')
        ->call('save')
        ->assertHasNoErrors();

    expect($template->fresh()->created_by_user_id)->toBe($author->id)
        ->and($template->fresh()->title)->toBe('Titre corrigé');
});

it('refuses a shortcut already taken', function (): void {
    MessageTemplate::factory()->create(['shortcut' => '/remb']);

    Livewire::actingAs(templatesUser('gestionnaire'))
        ->test(Templates::class)
        ->call('newTemplate')
        ->set('title', 'Autre')
        ->set('body', 'Un autre texte.')
        ->set('shortcut', '/remb')
        ->call('save')
        ->assertHasErrors('shortcut');
});

it('lets a template keep its own shortcut while being edited', function (): void {
    $template = MessageTemplate::factory()->create(['shortcut' => '/remb']);

    Livewire::actingAs(templatesUser('gestionnaire'))
        ->test(Templates::class)
        ->call('edit', $template->id)
        ->set('title', 'Titre corrigé')
        ->call('save')
        ->assertHasNoErrors();
});

it('refuses a shortcut that is not a slash command', function (): void {
    Livewire::actingAs(templatesUser('gestionnaire'))
        ->test(Templates::class)
        ->call('newTemplate')
        ->set('title', 'Autre')
        ->set('body', 'Un texte.')
        ->set('shortcut', 'remb')
        ->call('save')
        ->assertHasErrors('shortcut');
});

it('pauses and reactivates a template', function (): void {
    $template = MessageTemplate::factory()->create(['is_active' => true]);

    $component = Livewire::actingAs(templatesUser('gestionnaire'))
        ->test(Templates::class)
        ->call('toggle', $template->id);

    expect($template->fresh()->is_active)->toBeFalse();

    $component->call('toggle', $template->id);

    expect($template->fresh()->is_active)->toBeTrue();
});

it('asks for confirmation before deleting', function (): void {
    $template = MessageTemplate::factory()->create();

    Livewire::actingAs(templatesUser('gestionnaire'))
        ->test(Templates::class)
        ->assertSet('confirmingDeleteId', null)
        ->call('confirmDelete', $template->id)
        ->assertSet('confirmingDeleteId', $template->id)
        ->call('delete')
        ->assertSet('confirmingDeleteId', null);

    expect(MessageTemplate::query()->count())->toBe(0);
});

it('never uses the native confirm dialog', function (): void {
    MessageTemplate::factory()->create();

    $this->actingAs(templatesUser('gestionnaire'))
        ->get(route('bo.support-requests.templates'))
        ->assertOk()
        ->assertDontSee('wire:confirm');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function templatesUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}

it('closes the form from its own method so Escape and the backdrop work', function (): void {
    // `close="$set('formOpen', false)"` donnait `$wire.$set(…)()` : Échap était mort.
    Livewire::actingAs(templatesUser('gestionnaire'))
        ->test(Templates::class)
        ->call('newTemplate')
        ->assertSet('formOpen', true)
        ->assertSeeHtml('$wire.closeForm()')
        ->assertSeeHtml('wire:target="save"')
        ->call('closeForm')
        ->assertSet('formOpen', false);
});
