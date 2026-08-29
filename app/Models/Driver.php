<?php

namespace App\Models;

use App\Enums\DriverPhotoStatus;
use App\Enums\DriverStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string|null $yango_id
 * @property string $first_name
 * @property string $last_name
 * @property string $phone
 * @property string|null $license_number
 * @property string|null $photo_url
 * @property DriverPhotoStatus|null $photo_status
 * @property DriverStatus $status
 * @property string|null $suspension_reason
 * @property string|null $terms_version
 * @property CarbonImmutable|null $terms_accepted_at
 * @property string|null $fcm_token
 * @property CarbonImmutable|null $last_sync_at
 * @property CarbonImmutable|null $last_login_at
 * @property-read Vehicle|null $vehicle
 * @property-read Collection<int, OtpCode> $otpCodes
 * @property-read Collection<int, CnpsDeclaration> $cnpsDeclarations
 * @property-read Collection<int, CnpsReference> $cnpsReferences
 * @property-read int|null $period_orders  alias de withCount() sur la periode d'un challenge
 */
class Driver extends Authenticatable
{
    /** @use HasFactory<DriverFactory> */
    use HasApiTokens, HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * Jamais exposé par l'API.
     *
     * @var list<string>
     */
    protected $hidden = [
        'fcm_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'photo_status' => DriverPhotoStatus::class,
            'terms_accepted_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Véhicule actuellement affecté. Yango n'expose qu'une affectation active
     * à la fois ; `latestOfMany` protège d'un état transitoire pendant la
     * synchronisation.
     *
     * @return HasOne<Vehicle, $this>
     */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class)
            ->where('is_active', true)
            ->latestOfMany();
    }

    /**
     * Historique des codes OTP émis, du plus récent au plus ancien.
     *
     * @return HasMany<OtpCode, $this>
     */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class)->latest('sent_at');
    }

    /**
     * @return HasMany<Vehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * @return HasMany<YangoOrder, $this>
     */
    public function yangoOrders(): HasMany
    {
        return $this->hasMany(YangoOrder::class);
    }

    /**
     * @return HasMany<ShopOrder, $this>
     */
    public function shopOrders(): HasMany
    {
        return $this->hasMany(ShopOrder::class);
    }

    /**
     * @return HasMany<DriverDailyActivity, $this>
     */
    public function dailyActivities(): HasMany
    {
        return $this->hasMany(DriverDailyActivity::class);
    }

    /**
     * @return HasMany<CnpsDeclaration, $this>
     */
    public function cnpsDeclarations(): HasMany
    {
        return $this->hasMany(CnpsDeclaration::class);
    }

    /**
     * @return HasMany<CnpsReference, $this>
     */
    public function cnpsReferences(): HasMany
    {
        return $this->hasMany(CnpsReference::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === DriverStatus::Suspended;
    }

    public function hasPhotoPendingModeration(): bool
    {
        return $this->photo_status === DriverPhotoStatus::Pending;
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function hasAcceptedCurrentTerms(): bool
    {
        return $this->terms_version === config('wigo.terms_version')
            && $this->terms_accepted_at !== null;
    }

    public function initials(): string
    {
        $initials = Str::initials($this->fullName(), true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
