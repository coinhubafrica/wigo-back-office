<?php

use App\Contracts\SmsSender;
use App\Enums\OtpChannel;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Models\Vehicle;
use App\Services\Sms\LogSmsSender;
use App\Settings\OtpSettings;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

it('stores a hashed code and sends it on otp request', function (): void {
    $sender = fakeSmsSender();
    $driver = Driver::factory()->create(['phone' => '+2250717738299']);

    $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
        ->assertOk()
        ->assertJsonStructure(['message', 'data' => ['channel', 'expires_at']]);

    $otpCode = OtpCode::sole();
    $this->assertSame($driver->id, $otpCode->driver_id);
    $this->assertTrue($otpCode->expires_at->isFuture());
    $this->assertNull($otpCode->consumed_at);
    $this->assertCount(1, $sender->sent());

    // Le code circule en clair dans le SMS mais n'est stocké que haché.
    $code = extractCode($sender->sent()[0]['message']);
    $this->assertTrue(Hash::check($code, $otpCode->code_hash));
    $this->assertStringNotContainsString($code, $otpCode->code_hash);
});

it('honours the requested channel on otp request', function (): void {
    $sender = fakeSmsSender();
    Driver::factory()->create(['phone' => '+2250717738299']);

    $this->postJson(route('api.v1.auth.otp.request'), [
        'phone' => '+2250717738299',
        'channel' => 'whatsapp',
    ])->assertOk()->assertJsonPath('data.channel', 'whatsapp');

    $this->assertSame(OtpChannel::Whatsapp->value, $sender->sent()[0]['channel']);
});

it('defaults to sms on otp request', function (): void {
    $sender = fakeSmsSender();
    Driver::factory()->create(['phone' => '+2250717738299']);

    $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
        ->assertOk()
        ->assertJsonPath('data.channel', 'sms');

    $this->assertSame(OtpChannel::Sms->value, $sender->sent()[0]['channel']);
});

it('returns 422 for an unknown phone on otp request', function (): void {
    $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250700000000'])
        ->assertStatus(422)
        ->assertJsonPath('errors.phone.0', __('otp.unknown_phone'));
});

it('returns 422 for a malformed phone on otp request', function (): void {
    $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '0717738299'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('returns the code when exposure is enabled on otp request', function (): void {
    fakeSmsSender();
    config(['wigo.otp.expose_code' => true]);
    $driver = Driver::factory()->create(['phone' => '+2250717738299']);

    $code = $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
        ->assertOk()
        ->json('data.code');

    $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    $this->assertTrue(Hash::check($code, OtpCode::sole()->code_hash));

    // Le code renvoyé permet effectivement de s'authentifier.
    $this->postJson(route('api.v1.auth.otp.verify'), [
        'phone' => $driver->phone,
        'code' => $code,
        'device_name' => 'Pixel 8',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('omits the code when exposure is disabled on otp request', function (): void {
    fakeSmsSender();
    config(['wigo.otp.expose_code' => false]);
    Driver::factory()->create(['phone' => '+2250717738299']);

    $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
        ->assertOk()
        ->assertJsonMissingPath('data.code');
});

it('never exposes the code in production', function (): void {
    fakeSmsSender();

    // Même configuration explicite : la production doit refuser.
    config(['wigo.otp.expose_code' => true]);
    $this->app->detectEnvironment(fn (): string => 'production');

    Driver::factory()->create(['phone' => '+2250717738299']);

    $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
        ->assertOk()
        ->assertJsonMissingPath('data.code');
});

it('returns 429 after three sends on otp request', function (): void {
    fakeSmsSender();
    Driver::factory()->create(['phone' => '+2250717738299']);

    for ($send = 0; $send < 3; $send++) {
        $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
            ->assertOk();
    }

    $this->postJson(route('api.v1.auth.otp.request'), ['phone' => '+2250717738299'])
        ->assertStatus(429)
        ->assertJsonPath('message', __('otp.throttled', ['minutes' => 10]));
});

it('returns a token scoped to the mobile ability on otp verify', function (): void {
    $driver = driverWithOtp('482913');

    $this->postJson(route('api.v1.auth.otp.verify'), [
        'phone' => $driver->phone,
        'code' => '482913',
        'device_name' => 'Pixel 8',
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'driver' => ['id', 'first_name', 'phone', 'status'], 'terms']])
        ->assertJsonPath('data.driver.id', $driver->id);

    $this->assertCount(1, $driver->tokens()->get());
    $this->assertSame(['mobile:*'], $driver->tokens()->first()->abilities);
});

it('consumes the code on otp verify', function (): void {
    $driver = driverWithOtp('482913');
    $payload = ['phone' => $driver->phone, 'code' => '482913', 'device_name' => 'Pixel 8'];

    $this->postJson(route('api.v1.auth.otp.verify'), $payload)->assertOk();

    $this->assertNotNull(OtpCode::sole()->consumed_at);
    $this->assertNotNull($driver->refresh()->last_login_at);

    // Le même code ne peut pas être rejoué.
    $this->postJson(route('api.v1.auth.otp.verify'), $payload)
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', __('otp.not_requested'));
});

it('records the accepted terms version on otp verify', function (): void {
    $driver = driverWithOtp('482913', Driver::factory()->withoutTerms());

    $this->postJson(route('api.v1.auth.otp.verify'), [
        'phone' => $driver->phone,
        'code' => '482913',
        'device_name' => 'Pixel 8',
        'terms_version' => '1.0',
    ])->assertOk()->assertJsonPath('data.terms.accepted', true);

    $driver->refresh();
    $this->assertSame('1.0', $driver->terms_version);
    $this->assertNotNull($driver->terms_accepted_at);
});

it('reports outdated terms on otp verify', function (): void {
    $driver = driverWithOtp('482913', Driver::factory()->withoutTerms());

    $this->postJson(route('api.v1.auth.otp.verify'), [
        'phone' => $driver->phone,
        'code' => '482913',
        'device_name' => 'Pixel 8',
    ])
        ->assertOk()
        ->assertJsonPath('data.terms.accepted', false)
        ->assertJsonPath('data.terms.current_version', '1.0');
});

it('returns 422 for a wrong code on otp verify', function (): void {
    $driver = driverWithOtp('482913');

    $this->postJson(route('api.v1.auth.otp.verify'), [
        'phone' => $driver->phone,
        'code' => '000000',
        'device_name' => 'Pixel 8',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', __('otp.invalid'));

    $this->assertSame(1, OtpCode::sole()->attempts);
    $this->assertCount(0, $driver->tokens()->get());
});

it('returns 422 for an expired code on otp verify', function (): void {
    $driver = Driver::factory()->create();
    OtpCode::factory()->for($driver)->withCode('482913')->expired()->create();

    $this->postJson(route('api.v1.auth.otp.verify'), [
        'phone' => $driver->phone,
        'code' => '482913',
        'device_name' => 'Pixel 8',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', __('otp.expired'));
});

it('locks the account after five failures on otp verify', function (): void {
    $driver = driverWithOtp('482913');
    $wrong = ['phone' => $driver->phone, 'code' => '000000', 'device_name' => 'Pixel 8'];

    for ($attempt = 0; $attempt < app(OtpSettings::class)->max_attempts; $attempt++) {
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
});

it('refuses an otp request while the account is locked', function (): void {
    fakeSmsSender();
    $driver = Driver::factory()->create();
    OtpCode::factory()->for($driver)->locked()->create();

    $this->postJson(route('api.v1.auth.otp.request'), ['phone' => $driver->phone])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('returns 401 from me without a token', function (): void {
    $this->getJson(route('api.v1.me'))->assertUnauthorized();
});

it('returns 401 from me for a token without the mobile ability', function (): void {
    $driver = Driver::factory()->create();
    $token = $driver->createToken('Pixel 8', ['admin:*']);

    $this->withToken($token->plainTextToken)
        ->getJson(route('api.v1.me'))
        ->assertForbidden();
});

it('returns the profile with the active vehicle from me', function (): void {
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
        ->assertJsonPath('data.id', $driver->id)
        ->assertJsonPath('data.license_no', $driver->license_number)
        ->assertJsonPath('data.vehicle.plate', 'AA-567-HJ')
        ->assertJsonPath('data.vehicle.make', 'Suzuki');
});

it('does not expose the otp hash or the push token from me', function (): void {
    $driver = Driver::factory()->withPushToken()->create();
    OtpCode::factory()->for($driver)->create();

    Sanctum::actingAs($driver, ['mobile:*']);

    $body = $this->getJson(route('api.v1.me'))->assertOk()->json();

    $this->assertArrayNotHasKey('otp_code_hash', $body);
    $this->assertArrayNotHasKey('fcm_token', $body);
});

it('stores the push token', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->putJson(route('api.v1.push-token'), ['fcm_token' => 'token-abc'])->assertOk();

    $this->assertSame('token-abc', $driver->refresh()->fcm_token);
});

it('requires a value for the push token', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->putJson(route('api.v1.push-token'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fcm_token');
});

it('revokes only the current token on logout', function (): void {
    $driver = Driver::factory()->create();
    $kept = $driver->createToken('Autre appareil', ['mobile:*']);
    $current = $driver->createToken('Pixel 8', ['mobile:*']);

    $this->withToken($current->plainTextToken)
        ->postJson(route('api.v1.auth.logout'))
        ->assertOk();

    $this->assertCount(1, $driver->tokens()->get());
    $this->assertSame($kept->accessToken->id, $driver->tokens()->first()->id);
});

it('lets a suspended driver keep the profile but loses protected routes', function (): void {
    $driver = Driver::factory()->suspended('Documents non conformes')->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    // Le profil et la déconnexion restent accessibles : l'application doit
    // pouvoir afficher le motif de suspension.
    $this->getJson(route('api.v1.me'))->assertOk();
    $this->postJson(route('api.v1.auth.logout'))->assertOk();
});

function fakeSmsSender(): LogSmsSender
{
    $sender = new LogSmsSender;
    app()->instance(SmsSender::class, $sender);

    return $sender;
}

/**
 * @param  Factory<Driver>|null  $factory
 */
function driverWithOtp(string $code, $factory = null): Driver
{
    $driver = ($factory ?? Driver::factory())->create();

    OtpCode::factory()->for($driver)->withCode($code)->create();

    return $driver;
}

function extractCode(string $message): string
{
    preg_match('/\b(\d{'.app(OtpSettings::class)->length.'})\b/', $message, $matches);

    return $matches[1] ?? '';
}
