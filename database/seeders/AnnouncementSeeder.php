<?php

namespace Database\Seeders;

use App\Enums\AnnouncementMediaType;
use App\Models\Announcement;
use Illuminate\Database\Seeder;

/**
 * Annonces de développement : 3 images + 1 vidéo, comme décrit dans le
 * prototype. Idempotent : la clé est le titre.
 */
class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $this->announcement(
            title: 'Nouvelle offre de bonus hebdomadaire',
            attributes: [
                'media_type' => AnnouncementMediaType::Image,
                'media_url' => 'https://picsum.photos/seed/wigo-bonus/800/400',
                'order' => 1,
                'is_active' => true,
            ],
        );

        $this->announcement(
            title: 'Maintenance programmée du parc de véhicules',
            attributes: [
                'media_type' => AnnouncementMediaType::Image,
                'media_url' => 'https://picsum.photos/seed/wigo-maintenance/800/400',
                'order' => 2,
                'is_active' => true,
            ],
        );

        // Programmée dans le futur : is_active vrai mais starts_at pas encore
        // atteinte, donc invisible côté mobile pour l'instant.
        $this->announcement(
            title: 'Challenge de fin d\'année',
            attributes: [
                'media_type' => AnnouncementMediaType::Image,
                'media_url' => 'https://picsum.photos/seed/wigo-challenge/800/400',
                'order' => 3,
                'starts_at' => now()->addMonth(),
                'is_active' => true,
            ],
        );

        $this->announcement(
            title: 'Présentation vidéo de l\'application WiGO',
            attributes: [
                'media_type' => AnnouncementMediaType::Video,
                'media_url' => 'https://example.com/video.mp4',
                'order' => 4,
                'is_active' => true,
            ],
        );

        // Désactivée : sert à vérifier que is_active=false masque bien
        // l'annonce côté mobile.
        $this->announcement(
            title: 'Ancienne promotion (désactivée)',
            attributes: [
                'media_type' => AnnouncementMediaType::Image,
                'media_url' => 'https://picsum.photos/seed/wigo-ancienne/800/400',
                'order' => 5,
                'is_active' => false,
            ],
        );

        $this->command->table(
            ['Titre', 'Type', 'Ordre', 'Particularité'],
            [
                ['Nouvelle offre de bonus hebdomadaire', 'image', 1, 'active'],
                ['Maintenance programmée du parc de véhicules', 'image', 2, 'active'],
                ['Challenge de fin d\'année', 'image', 3, 'programmée dans le futur'],
                ['Présentation vidéo de l\'application WiGO', 'video', 4, 'active'],
                ['Ancienne promotion (désactivée)', 'image', 5, 'is_active=false'],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function announcement(string $title, array $attributes): Announcement
    {
        $announcement = Announcement::firstOrNew(['title' => $title]);
        $announcement->forceFill(array_merge(['title' => $title], $attributes))->save();

        return $announcement;
    }
}
