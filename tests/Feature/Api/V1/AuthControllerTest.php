<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\SmsSender;
use App\Enums\OtpChannel;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Models\Vehicle;
use App\Services\Sms\LogSmsSender;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_otp_request_stores_a_hashed_code_and_sends_it(): void
    {
        $sender = $this->fakeSmsSender();
        $driver = Driver::factory()->create(['phone' => '+2250717738299']);

        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
            ->assertOk()
            ->assertJsonStructure(['message', 'channel', 'expires_at']);

        $otpCode = OtpCode::sole();
        $this->assertSame($driver->id, $otpCode->driver_id);
        $this->assertTrue($otpCode->expires_at->isFuture());
        $this->assertNull($otpCode->consumed_at);
        $this->assertCount(1, $sender->sent());

        // Le code circule en clair dans le SMS mais n'est stocké que haché.
        $code = $this->extractCode($sender->sent()[0]['message']);
        $this->assertTrue(Hash::check($code, $otpCode->code_hash));
        $this->assertStringNotContainsString($code, $otpCode->code_hash);
    }

    public function test_otp_request_honours_the_requested_channel(): void
    {
        $sender = $this->fakeSmsSender();
        Driver::factory()->create(['phone' => '+2250717738299']);

        $this->postJson(route('api.v1.auth.otp.request'), [
            'phone' => '+2250717738299',
            'channel' => 'whatsapp',
        ])->assertOk()->assertJsonPath('channel', 'whatsapp');

        $this->assertSame(OtpChannel::Whatsapp->value, $sender->sent()[0]['channel']);
    }

    public function test_otp_request_defaults_to_sms(): void
    {
        $sender = $this->fakeSmsSender();
        Driver::factory()->create(['phone' => '+2250717738299']);

        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
            ->assertOk()
            ->assertJsonPath('channel', 'sms');

        $this->assertSame(OtpChannel::Sms->value, $sender->sent()[0]['channel']);
    }

    public function test_otp_request_returns_422_for_an_unknown_phone(): void
    {
        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250700000000'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', __('otp.unknown_phone'));
    }

    public function test_otp_request_returns_422_for_a_malformed_phone(): void
    {
        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '0717738299'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_otp_request_returns_the_code_when_exposure_is_enabled(): void
    {
        $this->fakeSmsSender();
        config(['wigo.otp.expose_code' => true]);
        $driver = Driver::factory()->create(['phone' => '+2250717738299']);

        $code = $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
            ->assertOk()
            ->json('code');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue(Hash::check($code, OtpCode::sole()->code_hash));

        // Le code renvoyé permet effectivement de s'authentifier.
        $this->postJson(route('api.v1.auth.otp.verify'), [
            'phone' => $driver->phone,
            'code' => $code,
            'device_name' => 'Pixel 8',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_otp_request_omits_the_code_when_exposure_is_disabled(): void
    {
        $this->fakeSmsSender();
        config(['wigo.otp.expose_code' => false]);
        Driver::factory()->create(['phone' => '+2250717738299']);

        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
            ->assertOk()
            ->assertJsonMissingPath('code');
    }

    public function test_the_code_is_never_exposed_in_production(): void
    {
        $this->fakeSmsSender();

        // Même configuration explicite : la production doit refuser.
        config(['wigo.otp.expose_code' => true]);
        $this->app->detectEnvironment(fn (): string => 'production');

        Driver::factory()->create(['phone' => '+2250717738299']);

        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
            ->assertOk()
            ->assertJsonMissingPath('code');
    }

    public function test_otp_request_returns_429_after_three_sends(): void
    {
        $this->fakeSmsSender();
        Driver::factory()->create(['phone' => '+2250717738299']);

        for ($send = 0; $send < 3; $send++) {
            $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
                ->assertOk();
        }

        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
            ->assertStatus(429)
            ->assertJsonPath('message', __('otp.throttled', ['minutes' => 10]));
    }

    public function test_otp_verify_returns_a_token_scoped_to_the_mobile_ability(): void
    {
        $driver = $this->driverWithOtp('482913');

        $this->postJson(route('api.v1.auth.otp.verify'), [
            'phone' => $driver->phone,
            'code' => '482913',
            'device_name' => 'Pixel 8',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'driver' => ['id', 'first_name', 'phone', 'status'], 'terms'])
            ->assertJsonPath('driver.id', $driver->id);

        $this->assertCount(1, $driver->tokens()->get());
        $this->assertSame(['mobile:*'], $driver->tokens()->first()->abilities);
    }

    public function test_otp_verify_consumes_the_code(): void
    {
        $driver = $this->driverWithOtp('482913');
        $payload = ['phone' => $driver->phone, 'code' => '482913', 'device_name' => 'Pixel 8'];

        $this->postJson(route('api.v1.auth.otp.verify'), $payload)->assertOk();

        $this->assertNotNull(OtpCode::sole()->consumed_at);
        $this->assertNotNull($driver->refresh()->last_login_at);

        // Le même code ne peut pas être rejoué.
        $this->postJson(route('api.v1.auth.otp.verify'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', __('otp.not_requested'));
    }

    public function test_otp_verify_records_the_accepted_terms_version(): void
    {
        $driver = $this->driverWithOtp('482913', Driver::factory()->withoutTerms());

        $this->postJson(route('api.v1.auth.otp.verify'), [
            'phone' => $driver->phone,
            'code' => '482913',
            'device_name' => 'Pixel 8',
            'terms_version' => '1.0',
        ])->assertOk()->assertJsonPath('terms.accepted', true);

        $driver->refresh();
        $this->assertSame('1.0', $driver->terms_version);
        $this->assertNotNull($driver->terms_accepted_at);
    }

    public function test_otp_verify_reports_outdated_terms(): void
    {
        $driver = $this->driverWithOtp('482913', Driver::factory()->withoutTerms());

        $this->postJson(route('api.v1.auth.otp.verify'), [
            'phone' => $driver->phone,
            'code' => '482913',
            'device_name' => 'Pixel 8',
        ])
            ->assertOk()
            ->assertJsonPath('terms.accepted', false)
            ->assertJsonPath('terms.current_version', '1.0');
    }

    public function test_otp_verify_returns_422_for_a_wrong_code(): void
    {
        $driver = $this->driverWithOtp('482913');

        $this->postJson(route('api.v1.auth.otp.verify'), [
            'phone' => $driver->phone,
            'code' => '000000',
            'device_name' => 'Pixel 8',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', __('otp.invalid'));

        $this->assertSame(1, OtpCode::sole()->attempts);
        $this->assertCount(0, $driver->tokens()->get());
    }

    public function test_otp_verify_returns_422_for_an_expired_code(): void
    {
        $driver = Driver::factory()->create();
        OtpCode::factory()->for($driver)->withCode('482913')->expired()->create();

        $this->postJson(route('api.v1.auth.otp.verify'), [
            'phone' => $driver->phone,
            'code' => '482913',
            'device_name' => 'Pixel 8',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', __('otp.expired'));
    }

    public function test_otp_verify_locks_the_account_after_five_failures(): void
    {
        $driver = $this->driverWithOtp('482913');
        $wrong = ['phone' => $driver->phone, 'code' => '000000', 'device_name' => 'Pixel 8'];

        for ($attempt = 0; $attempt < (int) config('wigo.otp.max_attempts'); $attempt++) {
            $this->postJson(route('api.v1.auth.otp.verify'), $wrong)->assertStatus(422);
        }

        $lockingCode = OtpCode::whereNotNull('locked_until')->sole();
        $this->assertTrue($lockingCode->locked_until->isFuture());
        $this->assertNotNull($lockingCode->consumed_at);

        // Le bon code est refusé tant que le verrou court.
        $this->postJson(route('api.v1.auth.otp.verify'), [
            'phone' => $driver->phone,
            'code' => '482913',
            'device_name' => 'Pixel 8',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', __('otp.locked', ['minutes' => 15]));
    }

    public function test_otp_request_is_refused_while_the_account_is_locked(): void
    {
        $this->fakeSmsSender();
        $driver = Driver::factory()->create();
        OtpCode::factory()->for($driver)->locked()->create();

        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => $driver->phone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_me_returns_401_without_a_token(): void
    {
        $this->getJson(route('api.v1.me'))->assertUnauthorized();
    }

    public function test_me_returns_401_for_a_token_without_the_mobile_ability(): void
    {
        $driver = Driver::factory()->create();
        $token = $driver->createToken('Pixel 8', ['admin:*']);

        $this->withToken($token->plainTextToken)
            ->getJson(route('api.v1.me'))
            ->assertForbidden();
    }

    public function test_me_returns_the_profile_with_the_active_vehicle(): void
    {
        $driver = Driver::factory()->create();
        Vehicle::factory()->for($driver)->create([
            'plate_number' => 'AA-567-HJ',
            'brand' => 'Suzuki',
            'model' => 'Dzire',
            'color' => 'Blanc',
        ]);

        Sanctum::actingAs($driver, ['mobile:*']);

        $this->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('id', $driver->id)
            ->assertJsonPath('license_no', $driver->license_number)
            ->assertJsonPath('vehicle.plate', 'AA-567-HJ')
            ->assertJsonPath('vehicle.make', 'Suzuki');
    }

    public function test_me_does_not_expose_the_otp_hash_or_the_push_token(): void
    {
        $driver = Driver::factory()->withPushToken()->create();
        OtpCode::factory()->for($driver)->create();

        Sanctum::actingAs($driver, ['mobile:*']);

        $body = $this->getJson(route('api.v1.me'))->assertOk()->json();

        $this->assertArrayNotHasKey('otp_code_hash', $body);
        $this->assertArrayNotHasKey('fcm_token', $body);
    }

    public function test_push_token_is_stored(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->putJson(route('api.v1.me.push-token'), ['fcm_token' => 'token-abc'])->assertOk();

        $this->assertSame('token-abc', $driver->refresh()->fcm_token);
    }

    public function test_push_token_requires_a_value(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->putJson(route('api.v1.me.push-token'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fcm_token');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $driver = Driver::factory()->create();
        $kept = $driver->createToken('Autre appareil', ['mobile:*']);
        $current = $driver->createToken('Pixel 8', ['mobile:*']);

        $this->withToken($current->plainTextToken)
            ->postJson(route('api.v1.auth.logout'))
            ->assertOk();

        $this->assertCount(1, $driver->tokens()->get());
        $this->assertSame($kept->accessToken->id, $driver->tokens()->first()->id);
    }

    public function test_a_suspended_driver_keeps_the_profile_but_loses_protected_routes(): void
    {
        $driver = Driver::factory()->suspended('Documents non conformes')->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        // Le profil et la déconnexion restent accessibles : l'application doit
        // pouvoir afficher le motif de suspension.
        $this->getJson(route('api.v1.me'))->assertOk();
        $this->postJson(route('api.v1.auth.logout'))->assertOk();
    }

    private function fakeSmsSender(): LogSmsSender
    {
        $sender = new LogSmsSender;
        $this->app->instance(SmsSender::class, $sender);

        return $sender;
    }

    /**
     * @param  Factory<Driver>|null  $factory
     */
    private function driverWithOtp(string $code, $factory = null): Driver
    {
        $driver = ($factory ?? Driver::factory())->create();

        OtpCode::factory()->for($driver)->withCode($code)->create();

        return $driver;
    }

    private function extractCode(string $message): string
    {
        preg_match('/\b(\d{'.config('wigo.otp.length').'})\b/', $message, $matches);

        return $matches[1] ?? '';
    }
}
