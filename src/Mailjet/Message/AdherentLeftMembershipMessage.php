<?php

namespace AppBundle\Mailjet\Message;

use AppBundle\Entity\Adherent;
use Ramsey\Uuid\Uuid;

final class AdherentLeftMembershipMessage extends MailjetMessage
{
    public static function createFromAdherent(Adherent $adherent): self
    {
        return new self(
            Uuid::uuid4(),
            '54665',   // ROL TODO
            $adherent->getEmailAddress(),
            self::fixMailjetParsing($adherent->getFullName()),
            'Votre départ d\'En Marche !',
            static::getTemplateVars(),
            static::getRecipientVars($adherent->getFirstName())
        );
    }

    private static function getTemplateVars(): array
    {
        return [
            'target_firstname' => '',
        ];
    }

    private static function getRecipientVars(string $firstName): array
    {
        return [
            'target_firstname' => self::escape($firstName),
        ];
    }
}
