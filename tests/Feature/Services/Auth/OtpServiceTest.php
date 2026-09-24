<?php

use App\Enums\OtpChannel;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Notifications\WhatsappOtpCode;
use App\Services\Auth\OtpService;
use App\Settings\OtpSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->service = $this->app->make(OtpService::class);
});

it('each send appends a row rather than overwriting', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver);
    $this->service->send($driver);

    $this->assertSame(2, $driver->otpCodes()->count());
    $this->assertEquals(
        [OtpChannel::Whatsapp, OtpChannel::Whatsapp],
        $driver->otpCodes()->pluck('channel')->all(),
    );
});

it('an earlier code still works after a second send', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver);
    $first = otpServiceLastCode($driver);

    $this->service->send($driver);

    // Le message du premier code peut arriver après le second : il doit rester
    // valide tant qu'il n'a pas expiré.
    $this->service->verify($driver, $first);

    $this->assertSame(2, $driver->otpCodes()->whereNotNull('consumed_at')->count());
});

it('a successful verification consumes every code in flight', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver);
    $this->service->send($driver);
    $latest = otpServiceLastCode($driver);

    $this->service->verify($driver, $latest);

    $this->assertSame(0, $driver->otpCodes()->usable()->count());
});

it('the history is kept after a successful login', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver);
    $this->service->verify($driver, otpServiceLastCode($driver));
    $this->service->send($driver);

    // Trace d'audit : les codes précédents ne sont pas supprimés.
    $this->assertSame(2, $driver->otpCodes()->count());
});

it('the request ip is recorded', function (): void {
    $driver = Driver::factory()->create();

    $this->service->send($driver, '41.203.10.7');

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
    $this->service->send($driver);
    $this->service->send($driver);

    otpServiceAttemptAndSwallow($driver, '000000');

    $this->assertSame([1, 1], $driver->otpCodes()->pluck('attempts')->all());
});

it('the threshold locks the driver and invalidates the codes', function (): void {
    $driver = Driver::factory()->create();
    $this->service->send($driver);
    $valid = otpServiceLastCode($driver);

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
    $this->service->send($driver);
    $this->assertSame(1, $driver->otpCodes()->usable()->count());
});

it('sending is refused while locked', function (): void {
    $driver = Driver::factory()->create();
    OtpCode::factory()->for($driver)->locked()->create();

    $this->expectException(ValidationException::class);

    $this->service->send($driver);
});

it('a lock is scoped to one driver', function (): void {
    $locked = Driver::factory()->create();
    OtpCode::factory()->for($locked)->locked()->create();

    $other = Driver::factory()->create();

    $this->assertNotNull($this->service->lockedUntil($locked));
    $this->assertNull($this->service->lockedUntil($other));

    $this->service->send($other);
    $this->assertSame(1, $other->otpCodes()->count());
});

/**
 * Dernier code émis, lu dans la notification WhatsApp interceptée.
 */
function otpServiceLastCode(Driver $driver): string
{
    return Notification::sent($driver, WhatsappOtpCode::class)->last()->code;
}

function otpServiceAttemptAndSwallow(Driver $driver, string $code): void
{
    try {
        test()->service->verify($driver, $code);
    } catch (ValidationException) {
        // Échec attendu : le test porte sur l'état persisté.
    }
}
