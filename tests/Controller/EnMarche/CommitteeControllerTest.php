<?php

namespace Tests\AppBundle\Controller\EnMarche;

use AppBundle\DataFixtures\ORM\LoadAdherentData;
use AppBundle\DataFixtures\ORM\LoadEventCategoryData;
use AppBundle\DataFixtures\ORM\LoadEventData;
use AppBundle\DataFixtures\ORM\LoadHomeBlockData;
use AppBundle\Entity\CommitteeFeedItem;
use AppBundle\Mailer\Message\CommitteeNewFollowerMessage;
use AppBundle\Entity\Committee;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group functional
 * @group committee
 */
class CommitteeControllerTest extends AbstractGroupControllerTest
{
    private $committeeRepository;

    public function testRedirectionComiteFromOldUrl()
    {
        $this->client->request(Request::METHOD_GET, '/comites/'.LoadAdherentData::COMMITTEE_3_UUID.'/en-marche-dammarie-les-lys');

        $this->assertClientIsRedirectedTo('/comites/en-marche-dammarie-les-lys', $this->client, false, true);

        $this->client->followRedirect();

        $this->isSuccessful($this->client->getResponse());
    }

    public function testAnonymousUserIsNotAllowedToFollowCommittee()
    {
        $committeeUrl = '/comites/en-marche-dammarie-les-lys';

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertFalse($this->seeFollowLink($crawler));
        $this->assertFalse($this->seeUnfollowLink($crawler));
        $this->assertTrue($this->seeRegisterLink($crawler));
    }

    public function testAuthenticatedCommitteeSupervisorCannotUnfollowCommittee()
    {
        // Login as supervisor
        $crawler = $this->authenticateAsAdherent($this->client, 'jacques.picard@en-marche.fr');

        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());
        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $crawler = $this->client->click($crawler->selectLink('Gérer les adhérents')->link());
        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // There is another animator (2 = supervisor + host)
        $this->assertSame(2, $crawler->filter('.committee__members__list__host')->count());
    }

    public function testAuthenticatedCommitteeHostCanUnfollowCommittee()
    {
        // Login as host
        $crawler = $this->authenticateAsAdherent($this->client, 'gisele-berthoux@caramail.com');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $unfollowButton = $crawler->filter('.committee-unfollow');

        // Button should be enabled for there is a supervisor
        $this->assertNull($unfollowButton->attr('disabled'));

        // Unfollowing
        $committeeUrl = $this->client->getRequest()->getRequestUri();
        $this->client->request(Request::METHOD_POST, $committeeUrl.'/quitter', [
            'token' => $unfollowButton->attr('data-csrf-token'),
        ]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        // Ex-host should be allow to follow again
        $this->assertTrue($this->seeFollowLink($crawler));

        // Clear security token
        $this->client->getCookieJar()->clear();

        // Login again as supervisor
        $crawler = $this->authenticateAsAdherent($this->client, 'jacques.picard@en-marche.fr');

        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        // Unfollow link must be disabled because there is no other host
        $this->assertSame('disabled', $crawler->filter('.committee-unfollow')->attr('disabled'));
        // Other follower/register links must not exist
        $this->assertFalse($this->seeFollowLink($crawler));
        $this->assertFalse($this->seeRegisterLink($crawler, 0));
    }

    public function testAuthenticatedAdherentCanFollowCommittee()
    {
        $this->authenticateAsAdherent($this->client, 'carl999@example.fr');

        // Browse to the committee details page
        $committeeUrl = sprintf('/comites/%s', 'en-marche-dammarie-les-lys');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame('2 adhérents', $crawler->filter('.committee__card > .committee-members')->text());
        $this->assertTrue($this->seeFollowLink($crawler));
        $this->assertFalse($this->seeUnfollowLink($crawler));
        $this->assertFalse($this->seeRegisterLink($crawler, 0));

        // Emulate POST request to follow the committee.
        $token = $crawler->selectButton('Suivre ce comité')->attr('data-csrf-token');
        $this->client->request(Request::METHOD_POST, $committeeUrl.'/rejoindre', ['token' => $token]);

        // Email sent to the host
        $this->assertCountMails(1, CommitteeNewFollowerMessage::class, 'francis.brioul@yahoo.com');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // Refresh the committee details page
        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame('3 adhérents', $crawler->filter('.committee__card > .committee-members')->text());
        $this->assertFalse($this->seeFollowLink($crawler));
        $this->assertTrue($this->seeUnfollowLink($crawler));
        $this->assertFalse($this->seeRegisterLink($crawler, 0));

        // Emulate POST request to unfollow the committee.
        $token = $crawler->selectButton('Quitter ce comité')->attr('data-csrf-token');
        $this->client->request(Request::METHOD_POST, $committeeUrl.'/quitter', ['token' => $token]);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // Refresh the committee details page
        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSame('2 adhérents', $crawler->filter('.committee__card > .committee-members')->text());
        $this->assertTrue($this->seeFollowLink($crawler));
        $this->assertFalse($this->seeUnfollowLink($crawler));
        $this->assertFalse($this->seeRegisterLink($crawler, 0));
    }

    public function testApprovedCommitteePageIsViewableByAnyone()
    {
        $committeeUrl = sprintf('/comites/%s', 'en-marche-dammarie-les-lys');

        // Anonymous
        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSeeHosts($crawler, [
            ['Francis B.', 'animateur'],
            ['Jacques P.', 'co-animateur'],
        ]);
        $this->assertCountTimelineMessages($crawler, 2);
        $this->assertSeeTimelineMessages($crawler, [
            ['Jacques P.', 'co-animateur', 'Connectez-vous'],
            ['Jacques P.', 'co-animateur', 'Connectez-vous'],
        ]);

        // Adherent
        $this->authenticateAsAdherent($this->client, 'carl999@example.fr');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCountTimelineMessages($crawler, 2);
        $this->assertSeeTimelineMessages($crawler, [
            ['Jacques Picard', 'co-animateur', 'À la recherche de volontaires !'],
            ['Jacques Picard', 'co-animateur', 'Lancement du comité !'],
        ]);

        $this->logout($this->client);

        // Member
        $this->authenticateAsAdherent($this->client, 'francis.brioul@yahoo.com');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertCountTimelineMessages($crawler, 2);
        $this->assertSeeTimelineMessages($crawler, [
            ['Jacques Picard', 'co-animateur', 'À la recherche de volontaires !', true],
            ['Jacques Picard', 'co-animateur', 'Lancement du comité !', true],
        ]);
    }

    public function testUnapprovedCommitteeIsViewableByItsCreator()
    {
        $committeeUrl = sprintf('/comites/%s', 'en-marche-marseille-3');

        // Adherent
        $this->authenticateAsAdherent($this->client, 'carl999@example.fr');

        $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_FORBIDDEN, $this->client->getResponse());

        $this->logout($this->client);

        // Creator
        $this->authenticateAsAdherent($this->client, 'benjyd@aol.com');

        $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
    }

    public function testAnonymousGuestCanShowCommitteePage()
    {
        $committeeUrl = sprintf('/comites/%s', 'en-marche-paris-8');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertFalse($this->seeRegisterLink($crawler), 'The guest should not see the "register link"');
        $this->assertTrue($this->seeLoginLink($crawler), 'The guest should see the "login link"');
        $this->assertFalse($this->seeFollowLink($crawler), 'The guest should not see the "follow link"');
        $this->assertFalse($this->seeUnfollowLink($crawler), 'The guest should not see the "unfollow link"');
        $this->assertTrue($this->seeMembersCount($crawler, 4), 'The guest should see the members count');
        $this->assertTrue($this->seeHosts($crawler, 2), 'The guest should see the hosts');
        $this->assertTrue($this->seeHostsContactLink($crawler, 2), 'The guest should see the hosts contact link');
        $this->assertFalse($this->seeHostNav($crawler), 'The guest should not see the host navigation');
        $this->assertSeeSocialLinks($crawler, $this->committeeRepository->findOneByUuid(LoadAdherentData::COMMITTEE_1_UUID));
        $this->assertFalse($this->seeMessageForm($crawler));
    }

    public function testAuthenticatedAdherentCanShowCommitteePage()
    {
        $this->authenticateAsAdherent($this->client, 'benjyd@aol.com');

        $committeeUrl = sprintf('/comites/%s', 'en-marche-paris-8');

        $crawler = $this->client->request(Request::METHOD_GET, $committeeUrl);

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertSeeHosts($crawler, [
            ['Jacques Picard', 'animateur'],
            ['Gisele Berthoux', 'co-animateur'],
        ]);
        $this->assertFalse($this->seeRegisterLink($crawler, 0), 'The adherent should not see the "register link"');
        $this->assertFalse($this->seeLoginLink($crawler), 'The adherent should not see the "login link"');
        $this->assertTrue($this->seeFollowLink($crawler), 'The adherent should see the "follow link"');
        $this->assertFalse($this->seeUnfollowLink($crawler), 'The adherent should not see the "unfollow link"');
        $this->assertTrue($this->seeMembersCount($crawler, 4), 'The adherent should see the members count');
        $this->assertTrue($this->seeHosts($crawler, 2), 'The adherent should see the hosts');
        $this->assertTrue($this->seeHostsContactLink($crawler, 2), 'The adherent should see the hosts contact link');
        $this->assertFalse($this->seeHostNav($crawler), 'The adherent should not see the host navigation');
        $this->assertFalse($this->seeMessageForm($crawler));
    }

    public function testAuthenticatedCommitteeFollowerCanShowCommitteePage()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'carl999@example.fr');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertFalse($this->seeRegisterLink($crawler, 0), 'The follower should not see the "register link"');
        $this->assertFalse($this->seeLoginLink($crawler), 'The adherent should not see the "login link"');
        $this->assertFalse($this->seeFollowLink($crawler), 'The follower should not see the "follow link"');
        $this->assertTrue($this->seeUnfollowLink($crawler), 'The follower should see the "unfollow link"');
        $this->assertTrue($this->seeMembersCount($crawler, 4), 'The follower should see the members count');
        $this->assertTrue($this->seeHosts($crawler, 2), 'The follower should see the hosts');
        $this->assertTrue($this->seeHostsContactLink($crawler, 2), 'The follower should see the hosts contact link');
        $this->assertFalse($this->seeHostNav($crawler), 'The follower should not see the host navigation');
        $this->assertFalse($this->seeMessageForm($crawler));
    }

    public function testAuthenticatedCommitteeHostCanShowCommitteePage()
    {
        $crawler = $this->authenticateAsAdherent($this->client, 'gisele-berthoux@caramail.com');
        $crawler = $this->client->click($crawler->selectLink('En Marche Paris 8')->link());

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
        $this->assertFalse($this->seeRegisterLink($crawler, 0), 'The host should not see the "register link"');
        $this->assertFalse($this->seeLoginLink($crawler), 'The adherent should not see the "login link"');
        $this->assertFalse($this->seeFollowLink($crawler), 'The host should not see the "follow link"');
        $this->assertTrue($this->seeUnfollowLink($crawler), 'The host should see the "unfollow link" because there is another host');
        $this->assertTrue($this->seeMembersCount($crawler, 4), 'The host should see the members count');
        $this->assertTrue($this->seeHosts($crawler, 2), 'The host should see the hosts');
        $this->assertTrue($this->seeHostsContactLink($crawler, 1), 'The host should see the other contact links');
        $this->assertTrue($this->seeSelfHostContactLink($crawler, 'Gisele Berthoux', 'co-animateur'), 'The host should see his own contact link');
        $this->assertTrue($this->seeHostNav($crawler), 'The host should see the host navigation');
        $this->assertTrue($this->seeMessageForm($crawler));
    }

    public function testNoEditLinkWithAnonymousUser()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/comites/en-marche-paris-8');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->assertEditDeleteButton($crawler, 0);
    }

    public function testDisplayEditLinkWithAnimateurUser()
    {
        $this->authenticateAsAdherent($this->client, 'jacques.picard@en-marche.fr');
        $crawler = $this->client->request(Request::METHOD_GET, '/comites/en-marche-paris-8');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->assertEditDeleteButton($crawler, 10);
    }

    public function testDisplayEditLinkWithNormaleUser()
    {
        $this->authenticateAsAdherent($this->client, 'francis.brioul@yahoo.com');
        $crawler = $this->client->request(Request::METHOD_GET, '/comites/en-marche-paris-8');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->assertEditDeleteButton($crawler, 5);
    }

    public function testEditMessage()
    {
        $committee = $this->manager->getRepository(Committee::class)->findOneBy(['slug' => 'en-marche-paris-8']);
        $messages = $this->manager->getRepository(CommitteeFeedItem::class)->findMostRecentFeedEvent($committee->getUuid());
        $this->authenticateAsAdherent($this->client, 'jacques.picard@en-marche.fr');

        $crawler = $this->client->request(Request::METHOD_GET, '/comites/en-marche-paris-8/timeline/'.$messages->getId().'/modifier');
        $this->isSuccessful($this->client->getResponse());

        $form = $crawler->selectButton('committee_feed_item_message_send')->form();
        $this->assertSame($messages->getContent(), $form->get('committee_feed_item_message[content]')->getValue());

        $form->setValues(['committee_feed_item_message[content]' => $messages->getContent().' test']);
        $this->client->submit($form);
        $this->assertClientIsRedirectedTo('/comites/en-marche-paris-8', $this->client);

        $this->client->followRedirect();
        self::assertContains($messages->getContent().' test', $this->client->getResponse()->getContent());
    }

    public function testDeleteMessage()
    {
        $committee = $this->manager->getRepository(Committee::class)->findOneBy(['slug' => 'en-marche-paris-8']);
        $messages = $this->manager->getRepository(CommitteeFeedItem::class)->findMostRecentFeedEvent($committee->getUuid());
        $this->authenticateAsAdherent($this->client, 'jacques.picard@en-marche.fr');

        $crawler = $this->client->request(Request::METHOD_GET, '/comites/en-marche-paris-8');
        $form = $crawler->selectButton('delete_entity_delete')->form();
        $this->client->submit($form);
        $this->assertClientIsRedirectedTo('/comites/en-marche-paris-8', $this->client);

        $this->client->followRedirect();
        self::assertNotContains($messages->getContent(), $this->client->getResponse()->getContent());
    }

    public function testDeleteEditDenied()
    {
        $committee = $this->manager->getRepository(Committee::class)->findOneBy(['slug' => 'en-marche-paris-8']);
        $messages = $this->manager->getRepository(CommitteeFeedItem::class)->findMostRecentFeedEvent($committee->getUuid());

        $this->client->request(Request::METHOD_GET, '/comites/en-marche-paris-8/timeline/'.$messages->getId().'/modifier');
        $this->assertClientIsRedirectedTo($this->hosts['scheme'].'://'.$this->hosts['app'].'/connexion', $this->client);

        $this->client->request(Request::METHOD_GET, '/comites/en-marche-paris-8/timeline/'.$messages->getId().'/supprimer');
        $this->assertClientIsRedirectedTo('/comites', $this->client, false, true);

        $this->client->request(Request::METHOD_DELETE, '/comites/en-marche-paris-8/timeline/'.$messages->getId().'/supprimer');
        $this->assertClientIsRedirectedTo($this->hosts['scheme'].'://'.$this->hosts['app'].'/connexion', $this->client);
    }

    private function seeLoginLink(Crawler $crawler): bool
    {
        return 1 === count($crawler->filter('#committee-login-link'));
    }

    private function seeRegisterLink(Crawler $crawler, $nb = 1): bool
    {
        $this->assertCount($nb, $crawler->filter('.committee-follow--disabled'));

        return 1 === count($crawler->filter('#committee-register-link'));
    }

    private function seeFollowLink(Crawler $crawler): bool
    {
        return 1 === count($crawler->filter('.committee-follow'));
    }

    private function seeUnfollowLink(Crawler $crawler): bool
    {
        return 1 === count($crawler->filter('.committee-unfollow'));
    }

    private function seeMembersCount(Crawler $crawler, string $membersCount): bool
    {
        return $membersCount.' adhérent'.($membersCount > 1 ? 's' : '') === $crawler->filter('.committee__card .committee-members')->text();
    }

    private function seeHosts(Crawler $crawler, int $hostsCount): bool
    {
        return $hostsCount === count($crawler->filter('.committee__card .committee-host'));
    }

    private function assertSeeHosts(Crawler $crawler, array $hosts): void
    {
        $this->assertCount(count($hosts), $nodes = $crawler->filter('.committee-host'));

        foreach ($hosts as $position => $host) {
            list($name, $role) = $host;
            $this->assertRegExp('/^'.preg_quote($name).'\s+'.$role.'\s+(Contacter)?$/', trim($nodes->eq($position)->text()));
        }
    }

    private function seeHostsContactLink(Crawler $crawler, int $hostsCount): bool
    {
        return $hostsCount === count($crawler->filter('.committee__card .committee-host a'));
    }

    private function seeSelfHostContactLink(Crawler $crawler, string $name, string $role): bool
    {
        /** @var \DOMElement $host */
        foreach ($crawler->filter('.committee__card .committee-host') as $host) {
            if (false !== strpos($host->textContent, 'Contacter')) {
                continue;
            }

            return preg_match('/'.preg_quote($name).'\s+'.$role.'\s+\(vous\)/', $host->textContent);
        }

        return false;
    }

    private function seeHostNav(Crawler $crawler): bool
    {
        return 1 === count($crawler->filter('#committee-host-nav'));
    }

    private function seeMessageForm(Crawler $crawler, array $errorMessages = []): bool
    {
        if ($errorMessages) {
            $errors = $crawler->filter('form[name="committee_feed_message"] .form__error');

            $this->assertCount(count($errorMessages), $errors);

            foreach ($errorMessages as $i => $errorMessage) {
                $this->assertSame($errorMessage, trim($errors->eq($i)->text()));
            }
        } else {
            $this->assertCount(0, $crawler->filter('form[name="committee_feed_message"] .form__errors'));
        }

        return 1 === count($crawler->filter('form[name="committee_feed_message"]'));
    }

    private function assertCountTimelineMessages(Crawler $crawler, int $nb, string $message = '')
    {
        $this->assertSame($nb, $crawler->filter('.committee__timeline__message')->count(), $message);
    }

    private function assertSeeTimelineMessages(Crawler $crawler, array $messages)
    {
        foreach ($messages as $position => $message) {
            list($author, $role, $text) = $message;
            $this->assertSeeCommitteeTimelineMessage($crawler, $position, $author, $role, $text, $message[3] ?? false);
        }
    }

    private function assertSeeSocialLinks(Crawler $crawler, Committee $committee)
    {
        $facebookLinkPattern = 'a.committee__social--facebook';
        $googlePlusLinkPattern = 'a.committee__social--google_plus';
        $twitterLinkPattern = 'a.committee__social--twitter';

        if ($facebookUrl = $committee->getFacebookPageUrl()) {
            $this->assertCount(1, $facebookLink = $crawler->filter($facebookLinkPattern));
            $this->assertSame($facebookUrl, $facebookLink->attr('href'));
        } else {
            $this->assertCount(0, $crawler->filter($facebookLinkPattern));
        }

        if ($googlePlusUrl = $committee->getGooglePlusPageUrl()) {
            $this->assertCount(1, $googlePlusLink = $crawler->filter($googlePlusLinkPattern));
            $this->assertSame($googlePlusUrl, $googlePlusLink->attr('href'));
        } else {
            $this->assertCount(0, $crawler->filter($googlePlusLinkPattern));
        }

        if ($twitterNickname = $committee->getTwitterNickname()) {
            $this->assertCount(1, $twitterLink = $crawler->filter($twitterLinkPattern));
            $this->assertSame(sprintf('https://twitter.com/%s', $twitterNickname), $twitterLink->attr('href'));
        } else {
            $this->assertCount(0, $crawler->filter($twitterLinkPattern));
        }
    }

    public function assertRedictIfCommitteeNotExist()
    {
        $this->client->request(Request::METHOD_GET, '/comites/ariege-leze');

        $this->assertStatusCode(Response::HTTP_MOVED_PERMANENTLY, $this->client);

        $this->assertClientIsRedirectedTo('/comites', $this->client);
        $this->client->followRedirect();

        $this->assertStatusCode(Response::HTTP_OK, $this->client);
    }

    protected function setUp()
    {
        parent::setUp();

        $this->init([
            LoadHomeBlockData::class,
            LoadAdherentData::class,
            LoadEventCategoryData::class,
            LoadEventData::class,
        ]);

        $this->committeeRepository = $this->getCommitteeRepository();
    }

    protected function tearDown()
    {
        $this->kill();

        $this->committeeRepository = null;

        parent::tearDown();
    }

    protected function getGroupUrl(): string
    {
        return '/comites/en-marche-dammarie-les-lys';
    }

    private function assertEditDeleteButton(Crawler $crawler, int $nbExpected)
    {
        $result = $crawler->selectLink('Modifier le message');
        $this->assertSame($nbExpected, $result->count());

        $result = $crawler->selectButton('delete_entity[delete]');
        $this->assertSame($nbExpected, $result->count());
    }
}
