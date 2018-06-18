<?php

namespace AppBundle\DataFixtures\ORM;

use AppBundle\Entity\NotificationSubscription;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\Persistence\ObjectManager;

class LoadNotificationSubscriptionData extends Fixture
{
    public function load(ObjectManager $manager)
    {
        $nsMain = new NotificationSubscription('', '');
        $manager->persist($nsMain);

        $nsMain = new NotificationSubscription('Emails En Marche !', '');
        $manager->persist($nsMain);

        $manager->flush();

//        public const SUBSCRIBED_EMAILS_MAIN = '';
//        public const SUBSCRIBED_EMAILS_LOCAL_HOST = '';
//
//        public const SUBSCRIBED_EMAILS_MOVEMENT_INFORMATION = 'subscribed_emails_movement_information';
//        public const SUBSCRIBED_EMAILS_GOVERNMENT_INFORMATION = 'subscribed_emails_government_information';
//        public const SUBSCRIBED_EMAILS_WEEKLY_LETTER = 'subscribed_emails_weekly_letter';
//        public const SUBSCRIBED_EMAILS_MOOC = 'subscribed_emails_mooc';
//        public const SUBSCRIBED_EMAILS_MICROLEARNING = 'subscribed_emails_microlearning';
//        public const SUBSCRIBED_EMAILS_DONATOR_INFORMATION = 'subscribed_emails_donator_information';
//        public const SUBSCRIBED_EMAILS_REFERENTS = 'subscribed_emails_referents';
//        public const SUBSCRIBED_EMAILS_CITIZEN_PROJECT_CREATION = 'subscribed_emails_citizen_project_creation';
    }

    private function getNotificationSubscriptions(): array
    {
        return [
            [
                'label' => 'Emails En Marche !',
                'code' => 'main_emails', // subscribed_emails_main
            ],
            [
                'label' => 'Emails de votre animateur local',
                'code' => 'local_host_emails', // subscribed_emails_local_host
            ],
            [
                'label' => 'Emails de votre animateur local',
                'code' => 'local_host_emails', // subscribed_emails_local_host
            ],
        ];
    }

    public function getDependencies()
    {
        return [
            LoadAdherentData::class,
        ];
    }
}
