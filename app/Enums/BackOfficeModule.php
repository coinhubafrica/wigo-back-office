<?php

namespace App\Enums;

/**
 * Modules du back-office. Chaque module correspond à une permission
 * `module.<code>` et à une entrée de la barre latérale.
 */
enum BackOfficeModule: string
{
    case Dashboard = 'dashboard';
    case SupportRequests = 'support-requests';
    case Drivers = 'drivers';
    case Challenges = 'challenges';
    case Announcements = 'announcements';
    case Campaigns = 'campaigns';
    case Shop = 'shop';
    case Recharges = 'recharges';
    case Cnps = 'cnps';
    case Settings = 'settings';
    case Audit = 'audit';

    public function permission(): string
    {
        return "module.{$this->value}";
    }

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Tableau de bord',
            self::SupportRequests => 'Requêtes',
            self::Drivers => 'Chauffeurs',
            self::Challenges => 'Challenges',
            self::Announcements => 'Annonces',
            self::Campaigns => 'Campagnes',
            self::Shop => 'Produits',
            self::Recharges => 'Recharges',
            self::Cnps => 'CNPS',
            self::Settings => 'Paramètres',
            self::Audit => "Journal d'audit",
        };
    }

    /**
     * Titre affiché dans la barre supérieure (source : prototype).
     */
    public function title(): string
    {
        return match ($this) {
            self::Dashboard => 'Tableau de bord',
            self::Drivers => 'Chauffeurs',
            self::SupportRequests => 'Requêtes',
            self::Challenges => 'Challenges',
            self::Recharges => 'Paiements',
            self::Cnps => 'CNPS (RSTI)',
            self::Shop => 'Boutique',
            self::Announcements => 'Annonces',
            self::Campaigns => 'Campagnes',
            self::Settings => 'Paramètres',
            self::Audit => "Journal d'audit",
        };
    }

    /**
     * Sous-titre affiché sous le titre du module (source : prototype).
     */
    public function subtitle(): string
    {
        return match ($this) {
            self::Dashboard => "Vue d'ensemble du parc, de l'activité et de la performance des équipes",
            self::Drivers => 'Liste, recherche, fiche 360°, modération des photos',
            self::SupportRequests => 'File de traitement — chaque requête porte son fil de messages avec le conducteur',
            self::Challenges => 'La base de toute gratification : des critères, une période, un prix — classement, tirage au sort ou bonus surprise',
            self::Recharges => 'Journal des transactions Wave, réconciliation, rejeux',
            self::Cnps => 'Suivi des cotisations déclarées par les conducteurs, mois par mois',
            self::Shop => 'Catalogue, stock, commandes, livraisons',
            self::Announcements => "Bannières de l'accueil : image ou vidéo 15–30 s",
            self::Campaigns => 'Un message déposé dans le fil des conducteurs visés : tous, un segment ou un conducteur nommé',
            self::Settings => 'Utilisateurs et rôles, seuils, clés API',
            self::Audit => 'Traçabilité des actions sensibles',
        };
    }

    /**
     * Mot-clé affiché au-dessus du titre dans la barre supérieure (source : prototype).
     */
    public function eyebrow(): string
    {
        return match ($this) {
            self::Dashboard => 'Pilotage',
            self::SupportRequests, self::Drivers => 'Support',
            self::Challenges, self::Announcements, self::Campaigns => 'Marketing',
            self::Shop => 'Boutique / Stock',
            self::Recharges, self::Cnps => 'Finance',
            self::Settings, self::Audit => 'Système',
        };
    }

    /**
     * Chemin `d` de l'icône (24x24, style heroicons outline) affichée dans la barre latérale.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Dashboard => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
            self::SupportRequests => 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z',
            self::Drivers => 'M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
            self::Challenges => 'M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.148 2.148A12.061 12.061 0 0 1 16.5 7.605',
            self::Announcements => 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46',
            self::Campaigns => 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0',
            self::Shop => 'M2.25 3h1.386c.51 0 .955.343 1.087.836l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.936-4.762 2.32-7.342a.75.75 0 0 0-.622-.858H5.106M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z',
            self::Recharges => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
            self::Cnps => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
            self::Settings => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 0 1 0 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 0 1 0-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
            self::Audit => 'M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z',
        };
    }

    /**
     * Groupe de la barre latérale.
     */
    public function group(): ?string
    {
        return match ($this) {
            self::Dashboard => null,
            self::SupportRequests, self::Drivers => 'Support',
            self::Challenges, self::Announcements, self::Campaigns => 'Marketing',
            self::Shop => 'Boutique / Stock',
            self::Recharges, self::Cnps => 'Finance',
            self::Settings, self::Audit => 'Système',
        };
    }

    public function route(): string
    {
        return "bo.{$this->value}";
    }
}
