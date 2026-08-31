<?php

use App\Contracts\SmsSender;
use App\Enums\OtpChannel;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Services\Auth\OtpService;
use App\Services\Sms\LogSmsSender;
use App\Settings\OtpSettings;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->sender = new LogSmsSender;
    $this->app->instance(SmsSender::class, $this->sender);
    $this->service = $this->app->make(OtpService::class);
});

it('each send appends a row rather than overwriting', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver, OtpChannel::Sms);
    $this->service->send($driver, OtpChannel::Whatsapp);

    $this->assertSame(2, $driver->otpCodes()->count());
    $this->assertEqualsCanonicalizing(
        [OtpChannel::Sms, OtpChannel::Whatsapp],
        $driver->otpCodes()->pluck('channel')->all(),
    );
});

it('an earlier code still works after a second send', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver, OtpChannel::Sms);
    $first = otpServiceLastCode();

    $this->service->send($driver, OtpChannel::Sms);

    // Le SMS du premier code peut arriver après le second : il doit rester
    // valide tant qu'il n'a pas expiré.
    $this->service->verify($driver, $first);

    $this->assertSame(2, $driver->otpCodes()->whereNotNull('consumed_at')->count());
});

it('a successful verification consumes every code in flight', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver, OtpChannel::Sms);
    $this->service->send($driver, OtpChannel::Sms);
    $latest = otpServiceLastCode();

    $this->service->verify($driver, $latest);

    $this->assertSame(0, $driver->otpCodes()->usable()->count());
});

it('the history is kept after a successful login', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver, OtpChannel::Sms);
    $this->service->verify($driver, otpServiceLastCode());
    $this->service->send($driver, OtpChannel::Whatsapp);

    // Trace d'audit : les codes précédents ne sont pas supprimés.
    $this->assertSame(2, $driver->otpCodes()->count());
});

it('the request ip is recorded', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver, OtpChannel::Sms, '41.203.10.7');

    $this->assertSame('41.203.10.7', OtpCode::sole()->request_ip);
});

it('an expired code is refused', function (): void {
    $driver = Driver::factory()->create();
    OtpCode::factory()->for($driver)->withCode('482913')->expired()->create();

    $this->expectException(ValidationException::class);

    $this->service->verify($driver, '482913');
});

it('a consumed code cannot be replayed', function (): void {
    $driver = Driver::factory()->create();
    OtpCode::factory()->for($driver)->withCode('482913')->consumed()->create();

    $this->expectException(ValidationException::class);

    $this->service->verify($driver, '482913');
});

it('the failure counter is shared across codes in flight', function (): void {
    $driver = Driver::factory()->create();
    $this->service->send($driver, OtpChannel::Sms);
    $this->service->send($driver, OtpChannel::Sms);

    otpServiceAttemptAndSwallow($driver, '000000');

    $this->assertSame([1, 1], $driver->otpCodes()->pluck('attempts')->all());
});

it('the threshold locks the driver and invalidates the codes', function (): void {
    $driver = Driver::factory()->create();
    $this->service->send($driver, OtpChannel::Sms);
    $valid = otpServiceLastCode();

    for ($attempt = 0; $attempt < app(OtpSettings::class)->max_attempts; $attempt++) {
        otpServiceAttemptAndSwallow($driver, '000000');
    }

    $this->assertNotNull($this->service->lockedUntil($driver));
    $this->assertSame(0, $driver->otpCodes()->usable()->count());

    // Même le bon code est refusé pendant le verrouillage.
    $this->expectException(ValidationException::class);
    $this->service->verify($driver, $valid);
});

it('the lock expires on its own', function (): void {
    $driver = Driver::factory()->create();
    OtpCode::factory()->for($driver)->locked()->create([
        'locked_until' => now()->subMinute(),
    ]);

    $this->assertNull($this->service->lockedUntil($driver));

    // Un nouvel envoi redevient possible.
    $this->service->send($driver, OtpChannel::Sms);
    $this->assertSame(1, $driver->otpCodes()->usable()->count());
});

it('sending is refused while locked', function (): void {
    $driver = Driver::factory()->create();
    OtpCode::factory()->for($driver)->locked()->create();

    $this->expectException(ValidationException::class);

    $this->service->send($driver, OtpChannel::Sms);
});

it('a lock is scoped to one driver', function (): void {
    $locked = Driver::factory()->create();
    OtpCode::factory()->for($locked)->locked()->create();

    $other = Driver::factory()->create();

    $this->assertNotNull($this->service->lockedUntil($locked));
    $this->assertNull($this->service->lockedUntil($other));

    $this->service->send($other, OtpChannel::Sms);
    $this->assertSame(1, $other->otpCodes()->count());
});

/**
 * Dernier code émis, lu dans le SMS journalisé.
 */
function otpServiceLastCode(): string
{
    $messages = test()->sender->sent();
    preg_match('/\b(\d{'.app(OtpSettings::class)->length.'})\b/', end($messages)['message'], $matches);

    return $matches[1];
}

function otpServiceAttemptAndSwallow(Driver $driver, string $code): void
{
    try {
        test()->service->verify($driver, $code);
    } catch (ValidationException) {
        // Échec attendu : le test porte sur l'état persisté.
    }
}
