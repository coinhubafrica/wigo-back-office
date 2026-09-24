<?php

/**
 * Le code de connexion par WhatsApp : le modèle Meta `wigo_otp` part avec le
 * code dans le corps et dans le bouton « Copier le code ».
 *
 * Le client du paquet est conservé tel quel ; seul son transport HTTP est
 * remplacé, pour vérifier la requête réellement construite pour Meta.
 */

use App\Models\Driver;
use App\Notifications\WhatsappOtpCode;
use App\Settings\WhatsappSettings;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Netflie\WhatsAppCloudApi\Http\ClientHandler;
use Netflie\WhatsAppCloudApi\Http\RawResponse;
use Netflie\WhatsAppCloudApi\Response\ResponseException;
use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;

it('sends the wigo_otp template with the code in the body and the copy button', function (): void {
    whatsappConfigure();
    $transport = whatsappFakeTransport();
    $driver = Driver::factory()->create(['phone' => '+2250717738299']);

    $driver->notify(new WhatsappOtpCode('482913'));

    expect($transport->requests)->toHaveCount(1);

    [$url, $body, $headers] = $transport->requests[0];

    expect($url)->toEndWith('/123456789012345/messages')
        ->and($headers['Authorization'])->toBe('Bearer EAAB-jeton-de-test')
        ->and($body['to'])->toBe('2250717738299')
        ->and($body['type'])->toBe('template')
        ->and($body['template']['name'])->toBe('wigo_otp')
        ->and($body['template']['language'])->toBe(['code' => 'fr'])
        ->and($body['template']['components'])->toBe([
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '482913']]],
            [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => 0,
                'parameters' => [['type' => 'text', 'text' => '482913']],
            ],
        ]);
});

it('does not call Meta when WhatsApp is not configured', function (): void {
    $transport = whatsappFakeTransport();
    Log::spy();
    $driver = Driver::factory()->create();

    $driver->notify(new WhatsappOtpCode('482913'));

    expect($transport->requests)->toBeEmpty();
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message): bool => str_contains($message, 'aucun identifiant configuré'),
    )->once();
});

it('rethrows a Meta refusal without logging the code', function (): void {
    whatsappConfigure();
    whatsappFakeTransport(status: 400, body: [
        'error' => ['message' => 'Template name does not exist in the translation', 'code' => 132001],
    ]);
    Log::spy();
    $driver = Driver::factory()->create();

    // Relancée pour que la file retente.
    expect(fn () => $driver->notify(new WhatsappOtpCode('482913')))->toThrow(ResponseException::class);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['code'] === 132001
            && ! str_contains(json_encode($context), '482913'),
    )->once();
});

it('is queued encrypted and leaves no row in the driver notification history', function (): void {
    whatsappConfigure();
    whatsappFakeTransport();
    $driver = Driver::factory()->create();

    $notification = new WhatsappOtpCode('482913');
    $driver->notify($notification);

    // Chiffrée : le code en clair ne se lit pas dans la charge utile du job.
    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($driver->notifications()->count())->toBe(0);
});

function whatsappConfigure(): void
{
    $settings = app(WhatsappSettings::class);
    $settings->phone_number_id = '123456789012345';
    $settings->access_token = 'EAAB-jeton-de-test';
    $settings->save();
}

/**
 * Branche le client WhatsApp du paquet sur un transport qui note chaque
 * requête et répond `$status`.
 *
 * @param  array<string, mixed>  $body
 */
function whatsappFakeTransport(int $status = 200, array $body = ['messages' => [['id' => 'wamid.test']]]): object
{
    $transport = new class($status, $body) implements ClientHandler
    {
        /** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, string>}> */
        public array $requests = [];

        /**
         * @param  array<string, mixed>  $body
         */
        public function __construct(private int $status, private array $body) {}

        public function postJsonData(string $url, array $body, array $headers, int $timeout): RawResponse
        {
            $this->requests[] = [$url, $body, $headers];

            return new RawResponse([], (string) json_encode($this->body), $this->status);
        }

        public function postFormData(string $url, array $form, array $headers, int $timeout): RawResponse
        {
            return new RawResponse([], '{}', 200);
        }

        public function get(string $url, array $headers, int $timeout): RawResponse
        {
            return new RawResponse([], '{}', 200);
        }
    };

    app()->bind(WhatsAppCloudApi::class, fn ($app, array $parameters): WhatsAppCloudApi => new WhatsAppCloudApi([
        ...$parameters['config'],
        'client_handler' => $transport,
    ]));

    return $transport;
}
