<?php

namespace App\Models;

use Database\Factories\PartCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Famille de pièces (freinage, suspension…).
 *
 * @property string $id
 * @property string $name
 * @property int $order
 */
class PartCategory extends Model
{
    /** @use HasFactory<PartCategoryFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['order' => 'integer'];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
