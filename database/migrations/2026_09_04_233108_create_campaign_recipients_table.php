<?php

use App\Enums\CampaignRecipientStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Destinataires matérialisés d'un envoi groupé : une ligne par conducteur
     * visé, écrite au moment de l'envoi.
     *
     * Ce qui change, et pourquoi. Jusqu'ici « les messages déposés font foi » :
     * pas de table de destinataires. C'était tenable tant qu'un envoi ne
     * pouvait qu'aboutir — mais un conducteur dont le message n'a jamais été
     * écrit n'a alors *aucune* ligne, donc n'apparaît nulle part, et l'échec
     * est invisible autant qu'irrattrapable. Il faut un endroit où poser
     * « visé, mais pas remis, et voici pourquoi ».
     *
     * Le partage des rôles reste net :
     * - cette table porte l'état de la **remise** (visé / déposé / échoué) ;
     * - `messages.read_at` porte l'état de la **lecture**, et lui seul. Aucun
     *   drapeau de lecture n'est recopié ici : un `read_at` ne dérive pas,
     *   c'est précisément ce qui en fait une preuve.
     *
     * `UNIQUE (campaign_id, driver_id)` est l'idempotence, enfin portée par la
     * base. Le docblock de `DispatchCampaignJob` l'affirmait déjà alors que
     * rien ne la garantissait : la garde n'était qu'un `whereIn` en PHP, par
     * lot et hors transaction, que deux workers pouvaient franchir ensemble.
     * Cinq mille conducteurs notifiés en double, c'est un incident.
     *
     * `claimed_at` est la mécanique de réservation, jamais montrée à l'agent :
     * un `UPDATE ... WHERE claimed_at IS NULL` d'une seule instruction est
     * atomique, donc un seul worker peut remettre un destinataire donné. Le
     * statut, lui, reste une notion d'écran à trois valeurs.
     */
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            // Nul avant la remise, et de nouveau nul si le message disparaît :
            // ne doit jamais entrer dans la clé unique.
            $table->foreignUlid('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default(CampaignRecipientStatus::Pending->value);
            $table->timestamp('claimed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'driver_id']);
            $table->index(['campaign_id', 'status']);
        });

        $this->backfill();
    }

    /**
     * Les envois déjà partis n'ont que leurs messages. Sans ce rattrapage leur
     * page de détail afficherait « 0 destinataire » — ce qui se lit comme
     * « personne n'a reçu », soit l'inverse de la vérité.
     *
     * Les échecs d'avant ce changement n'ont laissé aucune trace nulle part :
     * ils ne seront pas reconstitués. C'est sans remède, et c'est assumé.
     */
    private function backfill(): void
    {
        DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereNotNull('messages.campaign_id')
            ->select([
                'messages.id as message_id',
                'messages.campaign_id',
                'messages.created_at',
                'conversations.driver_id',
            ])
            ->orderBy('messages.id')
            ->chunk(500, function ($messages): void {
                DB::table('campaign_recipients')->insertOrIgnore(
                    collect($messages)->map(fn (object $message): array => [
                        'id' => (string) Str::ulid(),
                        'campaign_id' => $message->campaign_id,
                        'driver_id' => $message->driver_id,
                        'message_id' => $message->message_id,
                        'status' => CampaignRecipientStatus::Sent->value,
                        // Un message existe : la remise a eu lieu, en une fois.
                        'claimed_at' => $message->created_at,
                        'delivered_at' => $message->created_at,
                        'attempts' => 1,
                        'created_at' => $message->created_at,
                        'updated_at' => $message->created_at,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
