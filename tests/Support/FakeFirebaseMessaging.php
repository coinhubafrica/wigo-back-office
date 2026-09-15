<?php

namespace Tests\Support;

use BadMethodCallException;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\AppInstance;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\Messages;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\RegistrationToken;
use Kreait\Firebase\Messaging\RegistrationTokens;
use Kreait\Firebase\Messaging\SendReport;
use Kreait\Firebase\Messaging\Topic;
use Throwable;

/**
 * Doublure locale de Firebase : enregistre les envois au lieu de les émettre.
 *
 * Le contrat `Messaging` est large — authentification de jetons, abonnements
 * aux sujets, validation — mais le canal FCM n'appelle que `sendMulticast()`.
 * Tout le reste lève : un test qui s'y aventure doit échouer bruyamment
 * plutôt que recevoir un `null` trompeur.
 */
class FakeFirebaseMessaging implements Messaging
{
    /** @var list<array{message: Message, tokens: list<string>}> */
    private array $sent = [];

    /** @var list<string> */
    private array $deadTokens = [];

    private ?Throwable $failure = null;

    /**
     * Jetons que Firebase refusera, comme une application désinstallée.
     *
     * @param  list<string>  $tokens
     */
    public function rejectTokens(array $tokens): self
    {
        $this->deadTokens = $tokens;

        return $this;
    }

    /**
     * Panne de transport : `sendMulticast()` lève au lieu de rapporter.
     */
    public function failWith(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /**
     * @return list<array{message: Message, tokens: list<string>}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function sendMulticast(Message|array $message, RegistrationTokens|RegistrationToken|array|string $registrationTokens, bool $validateOnly = false): MulticastSendReport
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $tokens = array_map(
            static fn (mixed $token): string => (string) $token,
            is_array($registrationTokens) ? $registrationTokens : [$registrationTokens],
        );

        $this->sent[] = ['message' => $message, 'tokens' => $tokens];

        return MulticastSendReport::withItems(array_map(
            fn (string $token): SendReport => $this->reportFor($token, $message),
            $tokens,
        ));
    }

    private function reportFor(string $token, Message $message): SendReport
    {
        $target = MessageTarget::with(MessageTarget::TOKEN, $token);

        if (! in_array($token, $this->deadTokens, strict: true)) {
            return SendReport::success($target, ['name' => 'projects/wigo/messages/1'], $message);
        }

        return SendReport::failure($target, $this->unregistered($token), $message);
    }

    /**
     * Le refus que Firebase rend pour un jeton dont l'application a été
     * désinstallée — c'est lui qui doit faire effacer la colonne.
     */
    private function unregistered(string $token): MessagingException
    {
        return NotFound::becauseTokenNotFound($token);
    }

    public function send(Message|array $message, bool $validateOnly = false): array
    {
        throw new BadMethodCallException('Le canal FCM ne passe pas par send().');
    }

    public function sendAll(array|Messages $messages, bool $validateOnly = false): MulticastSendReport
    {
        throw new BadMethodCallException('Non utilisé.');
    }

    public function validate(Message|array $message): array
    {
        throw new BadMethodCallException('Non utilisé.');
    }

    public function validateRegistrationTokens(RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        throw new BadMethodCallException('Non utilisé.');
    }

    public function subscribeToTopic(string|Topic $topic, RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        throw new BadMethodCallException('Non utilisé.');
    }

    public function subscribeToTopics(iterable $topics, RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        throw new BadMethodCallException('Non utilisé.');
    }

    public function unsubscribeFromTopic(string|Topic $topic, RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        throw new BadMethodCallException('Non utilisé.');
    }

    public function unsubscribeFromTopics(array $topics, RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        throw new BadMethodCallException('Non utilisé.');
    }

    public function unsubscribeFromAllTopics(RegistrationTokens|RegistrationToken|array|string $registrationTokenOrTokens): array
    {
        throw new BadMethodCallException('Non utilisé.');
    }

    public function getAppInstance(RegistrationToken|string $registrationToken): AppInstance
    {
        throw new BadMethodCallException('Non utilisé.');
    }
}
