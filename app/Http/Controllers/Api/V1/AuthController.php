<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OtpRequestRequest;
use App\Http\Requests\Api\V1\OtpVerifyRequest;
use App\Http\Requests\Api\V1\UpdatePushTokenRequest;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ResolvesDriver;

    public function __construct(private OtpService $otpService) {}

    /**
     * Demander un code OTP
     *
     * Envoie un code à 6 chiffres (valable 5 minutes) par SMS ou WhatsApp. Le
     * conducteur doit être pré-enregistré par le back-office : l'application
     * mobile ne crée pas de compte.
     *
     * Limité à 3 envois par tranche de 10 minutes et par numéro.
     *
     * En développement et en test (`WIGO_OTP_EXPOSE_CODE`), la réponse contient
     * en plus le champ `code` : il est toujours absent en production.
     *
     * @response array{
     *     message: string,
     *     channel: 'sms'|'whatsapp',
     *     expires_at: string,
     *     code?: string,
     * }
     */
    public function requestOtp(OtpRequestRequest $request): JsonResponse
    {
        $driver = $this->findByPhone($request->string('phone')->toString());

        $otpCode = $this->otpService->send($driver, $request->channel(), $request->ip());

        $payload = [
            'message' => __('otp.sent', ['minutes' => config('wigo.otp.ttl_minutes')]),
            'channel' => $otpCode->channel->value,
            'expires_at' => $otpCode->expires_at->toIso8601String(),
        ];

        $plainCode = $this->otpService->lastPlainCode();

        if ($plainCode !== null) {
            $payload['code'] = $plainCode;
        }

        return new JsonResponse($payload);
    }

    /**
     * Vérifier l'OTP et obtenir un jeton
     *
     * Délivre un jeton Sanctum portant l'habilitation `mobile:*`, à placer dans
     * l'en-tête `Authorization: Bearer <token>`. Plusieurs codes peuvent être
     * valides simultanément ; une vérification réussie les consomme tous.
     *
     * Après 5 saisies erronées, le numéro est verrouillé 15 minutes.
     */
    public function verifyOtp(OtpVerifyRequest $request): JsonResponse
    {
        $driver = $this->findByPhone($request->string('phone')->toString());

        $this->otpService->verify($driver, $request->string('code')->toString());

        if ($request->filled('terms_version')) {
            $driver->forceFill([
                'terms_version' => $request->string('terms_version')->toString(),
                'terms_accepted_at' => now(),
            ])->save();
        }

        $token = $driver->createToken($request->string('device_name')->toString(), ['mobile:*']);

        return new JsonResponse([
            'token' => $token->plainTextToken,
            'driver' => new DriverResource($driver->load('vehicle')),
            'terms' => [
                'current_version' => config('wigo.terms_version'),
                'accepted' => $driver->hasAcceptedCurrentTerms(),
            ],
        ]);
    }

    /**
     * Se déconnecter
     *
     * Révoque uniquement le jeton présenté ; les autres appareils du conducteur
     * restent connectés.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->driver($request)->currentAccessToken()->delete();

        return new JsonResponse(['message' => __('auth.logged_out')]);
    }

    /**
     * Profil du conducteur
     *
     * Accessible même lorsque le compte est suspendu, afin que l'application
     * puisse afficher le motif et permettre la déconnexion.
     */
    public function me(Request $request): DriverResource
    {
        return new DriverResource($this->driver($request)->load('vehicle'));
    }

    /**
     * Enregistrer le jeton FCM de l'appareil
     *
     * Requis pour recevoir les notifications push (messages data-only).
     */
    public function updatePushToken(UpdatePushTokenRequest $request): JsonResponse
    {
        $this->driver($request)->forceFill([
            'fcm_token' => $request->string('fcm_token')->toString(),
        ])->save();

        return new JsonResponse(['message' => __('auth.push_token_saved')]);
    }

    /**
     * @throws ValidationException si le numéro n'est pas enregistré
     */
    private function findByPhone(string $phone): Driver
    {
        $driver = Driver::where('phone', $phone)->first();

        if ($driver === null) {
            throw ValidationException::withMessages([
                'phone' => [__('otp.unknown_phone')],
            ]);
        }

        return $driver;
    }
}
