<?php

namespace App\Models;

use Database\Factories\PickupPointFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Agence de retrait des commandes.
 *
 * @property string $id
 * @property string $name
 * @property string $address
 * @property string|null $opening_hours
 * @property bool $is_active
 */
class PickupPoint extends Model
{
    /** @use HasFactory<PickupPointFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
