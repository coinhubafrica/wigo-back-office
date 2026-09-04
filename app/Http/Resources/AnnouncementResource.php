<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bannière de l'accueil mobile.
 *
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            /**
             * @var 'image'|'video'
             */
            'media_type' => $this->media_type->value,
            'media_url' => Storage::url($this->media_url),
            'duration' => $this->duration,
            'order' => $this->order,
        ];
    }
}
