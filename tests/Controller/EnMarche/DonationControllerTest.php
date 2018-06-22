<?php

namespace Tests\AppBundle\Controller\EnMarche;

use AppBundle\Donation\PayboxPaymentSubscription;
use AppBundle\Entity\Donation;
use AppBundle\Entity\Transaction;
use AppBundle\Mailer\Message\DonationMessage;
use AppBundle\Repository\DonationRepository;
use AppBundle\Repository\TransactionRepository;
use Goutte\Client as PayboxClient;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AppBundle\Controller\ControllerTestTrait;
use Liip\FunctionalTestBundle\Test\WebTestCase;

/**
 * @group functional
 * @group donation
 */
class DonationControllerTest extends WebTestCase
{
    use ControllerTestTrait;

    private const PAYBOX_PREPROD_URL = 'https://preprod-tpeweb.paybox.com/cgi/MYchoix_pagepaiement.cgi';

    /* @var PayboxClient */
    private $payboxClient;
    /* @var DonationRepository */
    private $donationRepository;
    /* @var TransactionRepository */
    private $transactionRepository;

    public function getDonationSubscriptions(): iterable
    {
        yield 'None' => [PayboxPaymentSubscription::NONE];
        yield 'Unlimited' => [PayboxPaymentSubscription::UNLIMITED];
    }

    public function getInvalidSubscriptionsUrl(): iterable
    {
        yield 'invalid subscription' => ['/don/coordonnees?montant=30&abonnement=42'];
        yield 'without amount' => ['/don/coordonnees?abonnement=-1'];
    }

    public function testPayboxPreprodIsHealthy()
    {
        $client = new Client([
            'base_uri' => self::PAYBOX_PREPROD_URL,
            'timeout' => 0,
            'allow_redirects' => false,
        ]);

        if (Response::HTTP_OK === $client->request(Request::METHOD_HEAD)->getStatusCode()) {
            $this->assertSame('healthy', 'healthy');
        } else {
            $this->markTestSkipped('Paybox preprod server is not available.');
        }
    }

    /**
     * @depends testPayboxPreprodIsHealthy
     * @dataProvider getDonationSubscriptions
     */
    public function testSuccessFulProcess(int $duration)
    {
        $appClient = $this->client;
        // There should not be any donation for the moment
        $this->assertCount(0, $this->donationRepository->findAll());

        $crawler = $appClient->request(Request::METHOD_GET, sprintf('/don/coordonnees?montant=30&abonnement=%d', $duration));

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        $this->client->submit($crawler->filter('form[name=app_donation]')->form([
            'app_donation' => [
                'gender' => 'male',
                'lastName' => 'Doe',
                'firstName' => 'John',
                'emailAddress' => 'test@paybox.com',
                'address' => '9 rue du Lycée',
                'country' => 'FR',
                'postalCode' => '06000',
                'cityName' => 'Nice',
                'phone' => [
                    'country' => 'FR',
                    'number' => '04 01 02 03 04',
                ],
                'isPhysicalPerson' => true,
                'hasFrenchNationality' => true,
            ],
        ]));

        $this->assertStatusCode(302, $this->client);
        // Donation should have been saved
        $this->assertCount(1, $donations = $this->donationRepository->findAll());
        $this->assertInstanceOf(Donation::class, $donation = $donations[0]);

        /* @var Donation $donation */
        $this->assertEquals(3000, $donation->getAmount());
        $this->assertSame('male', $donation->getGender());
        $this->assertSame('Doe', $donation->getLastName());
        $this->assertSame('John', $donation->getFirstName());
        $this->assertSame('test@paybox.com', $donation->getEmailAddress());
        $this->assertSame('FR', $donation->getCountry());
        $this->assertSame('06000', $donation->getPostalCode());
        $this->assertSame('Nice', $donation->getCityName());
        $this->assertSame('9 rue du Lycée', $donation->getAddress());
        $this->assertSame(33, $donation->getPhone()->getCountryCode());
        $this->assertSame('401020304', $donation->getPhone()->getNationalNumber());
        $this->assertSame($duration, $donation->getDuration());

        // Email should not have been sent
        $this->assertCount(0, $this->getEmailRepository()->findMessages(DonationMessage::class));

        // We should be redirected to payment
        $this->assertClientIsRedirectedTo(sprintf('/don/%s/paiement', $donation->getUuid()->toString()), $appClient);

        $crawler = $appClient->followRedirect();

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        $formNode = $crawler->filter('input[name=PBX_CMD]');

        if ($suffix = PayboxPaymentSubscription::getCommandSuffix($donation->getAmount(), $donation->getDuration())) {
            $this->assertContains($suffix, $formNode->attr('value'));
        }

        /*
         * En-Marche payment page (verification and form to Paybox)
         */
        $formNode = $crawler->filter('form[name=app_donation_payment]');

        $this->assertSame(self::PAYBOX_PREPROD_URL, $formNode->attr('action'));

        $crawler = $this->payboxClient->submit($formNode->form());

        if (Response::HTTP_OK !== $status = $this->payboxClient->getInternalResponse()->getStatus()) {
            $this->markTestSkipped(sprintf('Paybox preproduction server has responded with %d.', $status));
        }

        /*
         * Paybox redirection and payment form
         */
        $crawler = $this->payboxClient->submit($crawler->filter('form[name=PAYBOX]')->form());

        // Pay using a testing account
        $crawler = $this->payboxClient->submit($crawler->filter('form[name=form_pay]')->form([
            'NUMERO_CARTE' => '4012001037141112',
            'MOIS_VALIDITE' => '12',
            'AN_VALIDITE' => '32',
            'CVVX' => '123',
        ]));

        $content = $this->payboxClient->getInternalResponse()->getContent();

        // Check payment was successful
        $callbackUrl = $crawler->filter('a')->attr('href');
        $callbackUrlRegExp = 'http://'.$this->hosts['app'].'/don/callback/(.+)'; // token
        $callbackUrlRegExp .= '\?id=(.+)_john-doe';
        if (PayboxPaymentSubscription::NONE !== $duration) {
            $durationRegExp = $duration < 0 ? 0 : $duration - 1;
            $callbackUrlRegExp .= 'PBX_2MONT0000003000PBX_NBPAIE0'.$durationRegExp.'PBX_FREQ01PBX_QUAND00';
        }
        $callbackUrlRegExp .= '&authorization=XXXXXX&result=00000';
        $callbackUrlRegExp .= '&transaction=(\d+)&amount=3000&date=(\d+)&time=(.+)';
        $callbackUrlRegExp .= '&card_type=(CB|Visa|MasterCard)&card_end=3212&card_print=(.+)&subscription=(\d+)&Sign=(.+)';

        $this->assertRegExp('#'.$callbackUrlRegExp.'#', $content);
        $this->assertRegExp('#'.$callbackUrlRegExp.'#', $callbackUrl);

        $appClient->request(Request::METHOD_GET, $callbackUrl);

        $this->assertResponseStatusCode(Response::HTTP_FOUND, $appClient->getResponse());

        $statusUrl = $appClient->getResponse()->headers->get('location');
        $statusUrlRegExp = '/don/(.+)'; // uuid
        $statusUrlRegExp .= '/effectue\?code=donation_paybox_success&is_registration=0&_status_token=(.+)';

        $this->assertRegExp('#'.$statusUrlRegExp.'#', $statusUrl);

        $appClient->followRedirect();

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        // Donation should have been completed
        $this->getEntityManager(Donation::class)->refresh($donation);

        $this->assertFalse($donation->hasError());
        if ($donation->hasSubscription()) {
            $this->assertTrue($donation->isSubscriptionInProgress());
            $donation->nextDonationAt();
        } else {
            $this->assertTrue($donation->isFinished());

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Donation without subscription can\'t have next donation date.');
            $donation->nextDonationAt();
        }
        /** @var Transaction[] $transactions */
        $transactions = $this->transactionRepository->findBy(['donation' => $donation]);
        $this->assertCount(1, $transactions);
        $transaction = $transactions[0];
        $this->assertSame('00000', $transaction->getPayboxResultCode());
        $this->assertSame('XXXXXX', $transaction->getPayboxAuthorizationCode());

        // Email should have been sent
        $this->assertCount(1, $this->getEmailRepository()->findMessages(DonationMessage::class));
    }

    /**
     * @depends testPayboxPreprodIsHealthy
     * @dataProvider getDonationSubscriptions
     */
    public function testRetryProcess(int $duration)
    {
        $appClient = $this->client;
        // There should not be any donation for the moment
        $this->assertCount(0, $this->donationRepository->findAll());

        $crawler = $appClient->request(Request::METHOD_GET, sprintf('/don/coordonnees?montant=30&abonnement=%d', $duration));

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        $this->client->submit($crawler->filter('form[name=app_donation]')->form([
            'app_donation' => [
                'gender' => 'male',
                'lastName' => 'Doe',
                'firstName' => 'John',
                'emailAddress' => 'test@paybox.com',
                'address' => '9 rue du Lycée',
                'country' => 'FR',
                'postalCode' => '06000',
                'cityName' => 'Nice',
                'phone' => [
                    'country' => 'FR',
                    'number' => '04 01 02 03 04',
                ],
                'isPhysicalPerson' => true,
                'hasFrenchNationality' => true,
            ],
        ]));

        // Donation should have been saved
        $this->assertCount(1, $donations = $this->donationRepository->findAll());
        $this->assertInstanceOf(Donation::class, $donation = $donations[0]);

        /* @var Donation $donation */
        $this->assertEquals(3000, $donation->getAmount());
        $this->assertSame('male', $donation->getGender());
        $this->assertSame('Doe', $donation->getLastName());
        $this->assertSame('John', $donation->getFirstName());
        $this->assertSame('test@paybox.com', $donation->getEmailAddress());
        $this->assertSame('FR', $donation->getCountry());
        $this->assertSame('06000', $donation->getPostalCode());
        $this->assertSame('Nice', $donation->getCityName());
        $this->assertSame('9 rue du Lycée', $donation->getAddress());
        $this->assertSame(33, $donation->getPhone()->getCountryCode());
        $this->assertSame('401020304', $donation->getPhone()->getNationalNumber());
        $this->assertSame($duration, $donation->getDuration());

        // Email should not have been sent
        $this->assertCount(0, $this->getEmailRepository()->findMessages(DonationMessage::class));

        // We should be redirected to payment
        $this->assertClientIsRedirectedTo(sprintf('/don/%s/paiement', $donation->getUuid()->toString()), $appClient);

        $crawler = $appClient->followRedirect();

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        $formNode = $crawler->filter('input[name=PBX_CMD]');

        if ($suffix = PayboxPaymentSubscription::getCommandSuffix($donation->getAmount(), $donation->getDuration())) {
            $this->assertContains($suffix, $formNode->attr('value'));
        }

        /*
         * En-Marche payment page (verification and form to Paybox)
         */
        $formNode = $crawler->filter('form[name=app_donation_payment]');

        $this->assertSame(self::PAYBOX_PREPROD_URL, $formNode->attr('action'));

        /*
         * Paybox cancellation of payment form
         */
        $crawler = $this->payboxClient->submit($formNode->form());

        if (Response::HTTP_OK !== $status = $this->payboxClient->getInternalResponse()->getStatus()) {
            $this->markTestSkipped(sprintf('Paybox preproduction server has responded with %d.', $status));
        }

        $crawler = $this->payboxClient->submit($crawler->filter('form[name=PAYBOX]')->form());
        $cancelUrl = $crawler->filter('#pbx-annuler a')->attr('href');
        $cancelUrlRegExp = 'http://'.$this->hosts['app'].'/don/callback/(.+)'; // token
        $cancelUrlRegExp .= '\?id=(.+)_john-doe';
        if (PayboxPaymentSubscription::NONE !== $duration) {
            $durationRegExp = $duration < 0 ? 0 : $duration - 1;
            $cancelUrlRegExp .= 'PBX_2MONT0000003000PBX_NBPAIE0'.$durationRegExp.'PBX_FREQ01PBX_QUAND00';
        }
        $cancelUrlRegExp .= '&result=00001'; // error code
        $cancelUrlRegExp .= '&transaction=0&subscription=0&Sign=(.+)';

        $this->assertRegExp('#'.$cancelUrlRegExp.'#', $cancelUrl);

        $appClient->request(Request::METHOD_GET, $cancelUrl);

        $this->assertResponseStatusCode(Response::HTTP_FOUND, $appClient->getResponse());

        $statusUrl = $appClient->getResponse()->headers->get('location');
        $statusUrlRegExp = '/don/(.+)'; // uuid
        $statusUrlRegExp .= '/erreur\?code=paybox&is_registration=0&_status_token=(.+)';

        $this->assertRegExp('#'.$statusUrlRegExp.'#', $statusUrl);

        $crawler = $appClient->followRedirect();

        $this->assertResponseStatusCode(Response::HTTP_OK, $appClient->getResponse());

        // Donation should have been aborted
        $this->getEntityManager(Donation::class)->refresh($donation);

        $this->assertTrue($donation->hasError());

        /** @var Transaction[] $transactions */
        $transactions = $this->transactionRepository->findBy(['donation' => $donation]);
        $this->assertCount(1, $transactions);
        $transaction = $transactions[0];
        $this->assertSame('00001', $transaction->getPayboxResultCode());
        $this->assertNull($transaction->getPayboxAuthorizationCode());
        $this->assertNull($transaction->getPayboxTransactionId());

        // Email should not have been sent
        $this->assertCount(0, $this->getEmailRepository()->findMessages(DonationMessage::class));

        $retryUrl = $crawler->selectLink('Je souhaite réessayer')->attr('href');
        $retryUrlRegExp = '/don/coordonnees\?donation_retry_payload=(.*)&montant=30';

        $this->assertRegExp('#'.$retryUrlRegExp.'#', $retryUrl);

        $crawler = $this->client->request(Request::METHOD_GET, $retryUrl);

        $this->assertStatusCode(Response::HTTP_OK, $appClient);
        $this->assertContains('Doe', $crawler->filter('input[name="app_donation[lastName]"]')->attr('value'), 'Retry should be prefilled.');
    }

    /**
     * @depends testPayboxPreprodIsHealthy
     * @dataProvider getInvalidSubscriptionsUrl
     */
    public function testInvalidSubscription(string $url)
    {
        $this->client->request(Request::METHOD_GET, $url);

        $this->assertClientIsRedirectedTo('/don', $this->client);
    }

    public function testCallbackWithNoId()
    {
        $this->client->request(Request::METHOD_GET, '/don/callback/token');

        $this->assertClientIsRedirectedTo('/don', $this->client);
    }

    public function testCallbackWithWrongUuid()
    {
        $this->client->request(Request::METHOD_GET, '/don/callback/token', [
            'id' => 'wrong_uuid',
        ]);

        $this->assertStatusCode(Response::HTTP_FOUND, $this->client);
        $this->assertClientIsRedirectedTo('/don', $this->client);
    }

    public function testCallbackWithWrongToken()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/don/coordonnees?montant=30');

        $this->client->submit($crawler->filter('form[name=app_donation]')->form([
            'app_donation' => [
                'gender' => 'male',
                'lastName' => 'Doe',
                'firstName' => 'John',
                'emailAddress' => 'test@paybox.com',
                'address' => '9 rue du Lycée',
                'country' => 'FR',
                'postalCode' => '06000',
                'cityName' => 'Nice',
                'phone' => [
                    'country' => 'FR',
                    'number' => '04 01 02 03 04',
                ],
                'isPhysicalPerson' => true,
                'hasFrenchNationality' => true,
            ],
        ]));

        // Donation should have been saved
        /** @var Donation[] $donations */
        $this->assertCount(1, $donations = $this->donationRepository->findAll());
        $this->assertInstanceOf(Donation::class, $donation = $donations[0]);

        $this->client->request(Request::METHOD_GET, '/don/callback/token', [
            'id' => $donation->getUuid()->toString().'_',
        ]);

        $this->assertStatusCode(Response::HTTP_BAD_REQUEST, $this->client);
    }

    protected function setUp()
    {
        parent::setUp();

        $this->init();
        $this->loadFixtures([]);

        $this->payboxClient = new PayboxClient();
        $this->donationRepository = $this->getDonationRepository();
        $this->transactionRepository = $this->getTransactionRepository();
    }

    protected function tearDown()
    {
        $this->kill();

        $this->transactionRepository = null;
        $this->payboxClient = null;
        $this->donationRepository = null;

        parent::tearDown();
    }
}
