<?php

namespace Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20180618094707 extends AbstractMigration
{
    public function up(Schema $schema) : void
    {
        $this->addSql(
            'CREATE TABLE adherent_notification_subscription (
              adherent_id INT UNSIGNED NOT NULL, 
              notification_subscription_id INT UNSIGNED NOT NULL, 
              INDEX IDX_F44E2D1625F06C53 (adherent_id), 
              INDEX IDX_F44E2D16FB9196C7 (notification_subscription_id), 
              PRIMARY KEY(adherent_id, notification_subscription_id)
            ) DEFAULT CHARACTER SET UTF8 COLLATE UTF8_unicode_ci ENGINE = InnoDB'
        );
        $this->addSql(
            'CREATE TABLE notification_subscription (
              id INT UNSIGNED AUTO_INCREMENT NOT NULL, 
              label VARCHAR(255) NOT NULL, 
              code VARCHAR(255) NOT NULL, 
              PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE UTF8_unicode_ci ENGINE = InnoDB'
        );
        $this->addSql('ALTER TABLE adherent_notification_subscription ADD CONSTRAINT FK_F44E2D1625F06C53 FOREIGN KEY (adherent_id) REFERENCES adherents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE adherent_notification_subscription ADD CONSTRAINT FK_F44E2D16FB9196C7 FOREIGN KEY (notification_subscription_id) REFERENCES notification_subscription (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema) : void
    {
        $this->addSql('ALTER TABLE adherent_notification_subscription DROP FOREIGN KEY FK_F44E2D16FB9196C7');
        $this->addSql('DROP TABLE adherent_notification_subscription');
        $this->addSql('DROP TABLE notification_subscription');
    }
}
