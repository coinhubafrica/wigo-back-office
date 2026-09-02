<?php

use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OpenApiContract;

/**
 * Confronte les réponses réelles au contrat publié.
 *
 * Le contrat étant écrit à la main, plus rien ne garantit qu'il suive le code
 * — sauf ces tests. Ils échouent dès qu'une ressource gagne, perd ou retype
 * un champ sans que `docs/api/` soit mis à jour ; c'est ce qui remplace la
 * relecture du diff de régénération.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-08-29 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function apiContract(): OpenApiContract
{
    return new OpenApiContract;
}

function apiContractDriver(): Driver
{
    $driver = Driver::factory()->create();

    Sanctum::actingAs($driver, ['mobile:*']);

    return $driver;
}

it('the driver profile matches its schema', function (): void {
    apiContractDriver();

    apiContract()->assertMatches($this->getJson(route('api.v1.me'))->assertOk(), 'get', '/me');
});

it('the wallet balance matches its schema', function (): void {
    apiContractDriver();

    apiContract()->assertMatches($this->getJson(route('api.v1.wallet.show'))->assertOk(), 'get', '/wallet');
});

it('the recharge history matches its schema', function (): void {
    $driver = apiContractDriver();
    Transaction::factory()->count(2)->create(['driver_id' => $driver->id]);

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.wallet.recharges.index'))->assertOk(),
        'get',
        '/wallet/recharges',
    );
});

it('the announcements list matches its schema', function (): void {
    apiContractDriver();
    Announcement::factory()->count(2)->create();

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.announcements.index'))->assertOk(),
        'get',
        '/announcements',
    );
});

it('the product catalogue matches its schema', function (): void {
    apiContractDriver();
    Product::factory()->count(2)->create();

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.shop.products'))->assertOk(),
        'get',
        '/shop/products',
    );
});

it('the pickup points match their schema', function (): void {
    apiContractDriver();
    PickupPoint::factory()->count(2)->create();

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.shop.pickup-points'))->assertOk(),
        'get',
        '/shop/pickup-points',
    );
});

it('the support conversation matches its schema', function (): void {
    $driver = apiContractDriver();
    Conversation::factory()->create(['driver_id' => $driver->id]);

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.support.conversation'))->assertOk(),
        'get',
        '/support/conversation',
    );
});

it('the support messages match their schema', function (): void {
    $driver = apiContractDriver();
    $conversation = Conversation::factory()->create(['driver_id' => $driver->id]);
    Message::factory()->count(2)->create(['conversation_id' => $conversation->id]);

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.support.messages.index'))->assertOk(),
        'get',
        '/support/conversation/messages',
    );
});

it('the unread counter matches its schema', function (): void {
    apiContractDriver();

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.support.unread'))->assertOk(),
        'get',
        '/support/unread',
    );
});

it('the notifications list matches its schema', function (): void {
    apiContractDriver();

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.notifications.index'))->assertOk(),
        'get',
        '/notifications',
    );
});

it('the challenges list matches its schema', function (): void {
    apiContractDriver();

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.challenges'))->assertOk(),
        'get',
        '/challenges',
    );
});

it('the cnps statement matches its schema', function (): void {
    apiContractDriver();

    apiContract()->assertMatches(
        $this->getJson(route('api.v1.cnps.show'))->assertOk(),
        'get',
        '/cnps',
    );
});

it('the otp request matches its schema', function (): void {
    // `WIGO_OTP_EXPOSE_CODE` est actif dans phpunit.xml : la réponse porte donc
    // `code`, et ce test vérifie qu'il est bien publié. Qu'il soit facultatif
    // (absent de `required`) relève du contrat lui-même, pas de ce test — la
    // production ne l'envoie jamais.
    $driver = Driver::factory()->create();

    apiContract()->assertMatches(
        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => $driver->phone])->assertOk(),
        'post',
        '/auth/otp/request',
    );
});

// ------------------------------------------------- formes d'erreur partagées

it('a validation failure matches the shared schema', function (): void {
    // `ValidationException` est un composant partagé : le vérifier une fois
    // suffit, les 422 des autres opérations le référencent.
    apiContract()->assertMatches(
        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => ''])->assertUnprocessable(),
        'post',
        '/auth/otp/request',
        422,
    );
});

it('an unauthenticated call matches the shared schema', function (): void {
    apiContract()->assertMatches(
        $this->getJson(route('api.v1.me'))->assertUnauthorized(),
        'get',
        '/me',
        401,
    );
});
