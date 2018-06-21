<?php

namespace AppBundle\Controller\EnMarche;

use AppBundle\Form\DeputyMessageType;
use AppBundle\Deputy\DeputyMessage;
use AppBundle\Deputy\DeputyMessageNotifier;
use AppBundle\Referent\ManagedCommitteesExporter;
use AppBundle\Referent\ManagedEventsExporter;
use AppBundle\Repository\AdherentRepository;
use AppBundle\Repository\CommitteeRepository;
use AppBundle\Repository\EventRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/espace-depute")
 * @Security("is_granted('ROLE_DEPUTY')")
 */
class DeputyController extends Controller
{
    /**
     * @Route("/utilisateurs/message", name="app_deputy_users_message")
     * @Method("GET|POST")
     */
    public function usersSendMessageAction(Request $request, AdherentRepository $adherentRepository): Response
    {
        $recipients = $adherentRepository->findAllInDistrict($this->getUser()->getManagedDistrict());
        $message = $this->createMessage($recipients);

        $form = $this->createForm(DeputyMessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get(DeputyMessageNotifier::class)->sendMessage($message);
            $this->addFlash('info', $this->get('translator')->trans('deputy.message.success'));

            return $this->redirect($this->generateUrl('app_deputy_users_message'));
        }

        return $this->render('deputy/users_message.html.twig', [
            'results_count' => count($recipients),
            'message' => $message,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/evenements", name="app_deputy_events")
     * @Method("GET")
     */
    public function eventsAction(EventRepository $eventRepository, ManagedEventsExporter $eventsExporter): Response
    {
        return $this->render('deputy/events_list.html.twig', [
            'managedEventsJson' => $eventsExporter->exportAsJson($eventRepository->findAllInDistrict($this->getUser()->getManagedDistrict())),
        ]);
    }

    /**
     * @Route("/comites", name="app_deputy_committees")
     * @Method("GET")
     */
    public function committeesAction(CommitteeRepository $committeeRepository, ManagedCommitteesExporter $committeesExporter): Response
    {
        return $this->render('deputy/committees_list.html.twig', [
            'managedCommitteesJson' => $committeesExporter->exportAsJson(
                $committeeRepository->findAllInDistrict($this->getUser()->getManagedDistrict())
            ),
        ]);
    }

    private function createMessage(array $recipients): DeputyMessage
    {
        return new DeputyMessage($this->getUser(), $recipients);
    }
}
