<?php

namespace Tests\Feature\Services\Auth;

use App\Contracts\SmsSender;
use App\Enums\OtpChannel;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Services\Auth\OtpService;
use App\Services\Sms\LogSmsSender;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private OtpService $service;

    private LogSmsSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = new LogSmsSender;
        $this->app->instance(SmsSender::class, $this->sender);
        $this->service = $this->app->make(OtpService::class);
    }

    public function test_each_send_appends_a_row_rather_than_overwriting(): void
    {
        $driver = Driver::factory()->create();

        $this->service->send($driver, OtpChannel::Sms);
        $this->service->send($driver, OtpChannel::Whatsapp);

        $this->assertSame(2, $driver->otpCodes()->count());
        $this->assertEqualsCanonicalizing(
            [OtpChannel::Sms, OtpChannel::Whatsapp],
            $driver->otpCodes()->pluck('channel')->all(),
        );
    }

    public function test_an_earlier_code_still_works_after_a_second_send(): void
    {
        $driver = Driver::factory()->create();

        $this->service->send($driver, OtpChannel::Sms);
        $first = $this->lastCode();

        $this->service->send($driver, OtpChannel::Sms);

        // Le SMS du premier code peut arriver après le second : il doit rester
        // valide tant qu'il n'a pas expiré.
        $this->service->verify($driver, $first);

        $this->assertSame(2, $driver->otpCodes()->whereNotNull('consumed_at')->count());
    }

    public function test_a_successful_verification_consumes_every_code_in_flight(): void
    {
        $driver = Driver::factory()->create();

        $this->service->send($driver, OtpChannel::Sms);
        $this->service->send($driver, OtpChannel::Sms);
        $latest = $this->lastCode();

        $this->service->verify($driver, $latest);

        $this->assertSame(0, $driver->otpCodes()->usable()->count());
    }

    public function test_the_history_is_kept_after_a_successful_login(): void
    {
        $driver = Driver::factory()->create();

        $this->service->send($driver, OtpChannel::Sms);
        $this->service->verify($driver, $this->lastCode());
        $this->service->send($driver, OtpChannel::Whatsapp);

        // Trace d'audit : les codes précédents ne sont pas supprimés.
        $this->assertSame(2, $driver->otpCodes()->count());
    }

    public function test_the_request_ip_is_recorded(): void
    {
        $driver = Driver::factory()->create();

        $this->service->send($driver, OtpChannel::Sms, '41.203.10.7');

        $this->assertSame('41.203.10.7', OtpCode::sole()->request_ip);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $driver = Driver::factory()->create();
        OtpCode::factory()->for($driver)->withCode('482913')->expired()->create();

        $this->expectException(ValidationException::class);

        $this->service->verify($driver, '482913');
    }

    public function test_a_consumed_code_cannot_be_replayed(): void
    {
        $driver = Driver::factory()->create();
        OtpCode::factory()->for($driver)->withCode('482913')->consumed()->create();

        $this->expectException(ValidationException::class);

        $this->service->verify($driver, '482913');
    }

    public function test_the_failure_counter_is_shared_across_codes_in_flight(): void
    {
        $driver = Driver::factory()->create();
        $this->service->send($driver, OtpChannel::Sms);
        $this->service->send($driver, OtpChannel::Sms);

        $this->attemptAndSwallow($driver, '000000');

        $this->assertSame([1, 1], $driver->otpCodes()->pluck('attempts')->all());
    }

    public function test_the_threshold_locks_the_driver_and_invalidates_the_codes(): void
    {
        $driver = Driver::factory()->create();
        $this->service->send($driver, OtpChannel::Sms);
        $valid = $this->lastCode();

        for ($attempt = 0; $attempt < (int) config('wigo.otp.max_attempts'); $attempt++) {
            $this->attemptAndSwallow($driver, '000000');
        }

        $this->assertNotNull($this->service->lockedUntil($driver));
        $this->assertSame(0, $driver->otpCodes()->usable()->count());

        // Même le bon code est refusé pendant le verrouillage.
        $this->expectException(ValidationException::class);
        $this->service->verify($driver, $valid);
    }

    public function test_the_lock_expires_on_its_own(): void
    {
        $driver = Driver::factory()->create();
        OtpCode::factory()->for($driver)->locked()->create([
            'locked_until' => now()->subMinute(),
        ]);

        $this->assertNull($this->service->lockedUntil($driver));

        // Un nouvel envoi redevient possible.
        $this->service->send($driver, OtpChannel::Sms);
        $this->assertSame(1, $driver->otpCodes()->usable()->count());
    }

    public function test_sending_is_refused_while_locked(): void
    {
        $driver = Driver::factory()->create();
        OtpCode::factory()->for($driver)->locked()->create();

        $this->expectException(ValidationException::class);

        $this->service->send($driver, OtpChannel::Sms);
    }

    public function test_a_lock_is_scoped_to_one_driver(): void
    {
        $locked = Driver::factory()->create();
        OtpCode::factory()->for($locked)->locked()->create();

        $other = Driver::factory()->create();

        $this->assertNotNull($this->service->lockedUntil($locked));
        $this->assertNull($this->service->lockedUntil($other));

        $this->service->send($other, OtpChannel::Sms);
        $this->assertSame(1, $other->otpCodes()->count());
    }

    /**
     * Dernier code émis, lu dans le SMS journalisé.
     */
    private function lastCode(): string
    {
        $messages = $this->sender->sent();
        preg_match('/\b(\d{'.config('wigo.otp.length').'})\b/', end($messages)['message'], $matches);

        return $matches[1];
    }

    private function attemptAndSwallow(Driver $driver, string $code): void
    {
        try {
            $this->service->verify($driver, $code);
        } catch (ValidationException) {
            // Échec attendu : le test porte sur l'état persisté.
        }
    }
}
