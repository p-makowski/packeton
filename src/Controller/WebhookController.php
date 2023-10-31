<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Entity\Job;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Entity\Webhook;
use Packeton\Form\Type\WebhookType;
use Packeton\Webhook\HookResponse;
use Packeton\Webhook\HookTestAction;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/webhooks')]
class WebhookController extends AbstractController
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected HookTestAction $testAction,
    ){
    }

    #[Route('', name: 'webhook_index')]
    public function indexAction(): Response
    {
        $user = $this->getUser();
        $qb = $this->registry
            ->getRepository(Webhook::class)
            ->createQueryBuilder('w')
            ->orderBy('w.id');

        $qb->where('w.owner IS NULL')
            ->orWhere("w.visibility = 'global'")
            ->orWhere("w.visibility = 'user' AND IDENTITY(w.owner) = :owner")
            ->setParameter('owner', $user instanceof User ? $user->getId() : null);

        /** @var Webhook[] $webhooks */
        $webhooks = $qb->getQuery()->getResult();

        return $this->render('webhook/index.html.twig', [
            'webhooks' => $webhooks,
        ]);
    }

    #[Route('/create', name: 'webhook_create')]
    public function createAction(Request $request): Response
    {
        $hook = new Webhook();
        $data = $this->handleUpdate($request, $hook, 'Successfully saved.');

        return $data instanceof Response ? $data : $this->render('webhook/update.html.twig', $data);
    }

    #[Route('/update/{id}', name: 'webhook_update', requirements: ['id' => '\d+'])]
    public function updateAction(Request $request, #[Vars] Webhook $hook): Response
    {
        if (!$this->isGranted('VIEW', $hook)) {
            throw new AccessDeniedException();
        }

        $data = $this->handleUpdate($request, $hook, 'Successfully saved.');
        if ($request->getMethod() === 'GET') {
            $data['jobs'] = $this->registry
                ->getRepository(Job::class)
                ->findJobsByType('webhook:send', $hook->getId());
        }

        return $data instanceof Response ? $data : $this->render('webhook/update.html.twig', $data);
    }

    #[Route('/delete/{id}', name: 'webhook_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function deleteAction(Request $request, #[Vars] Webhook $hook): Response
    {
        if (!$this->isGranted('VIEW', $hook)) {
            throw new AccessDeniedException;
        }
        if (!$this->isCsrfTokenValid('webhook_delete', $request->request->get('_token'))) {
            throw new AccessDeniedException;
        }

        $em = $this->registry->getManager();
        $em->remove($hook);
        $em->flush();
        return new Response('', 204);
    }

    #[Route('/job/{id}', name: 'webhook_job_action')]
    public function jobAction(#[Vars] Job $entity): Response
    {
        $hook = $this->registry->getRepository(Webhook::class)
            ->find($entity->getPackageId());
        if ($hook === null || !$this->isGranted('VIEW', $hook)) {
            throw new AccessDeniedException();
        }

        $result = $entity->getResult() ?: [];
        try {
            $response = array_map(HookResponse::fromArray(...), $result['response'] ?? []);
        } catch (\Throwable $e) {
            $response = null;
        }

        return $this->render('webhook/hook_widget.html.twig', [
            'response' => $response,
            'errors' => $result['exceptionMsg'] ?? null
        ]);
    }

    #[Route('/test/{id}/send', name: 'webhook_test_action', requirements: ['id' => '\d+'])]
    public function testAction(Request $request, #[Vars] Webhook $entity): Response
    {
        if (!$this->isGranted('VIEW', $entity)) {
            throw new AccessDeniedException();
        }

        $form = $this->createFormBuilder()
            ->add('event', ChoiceType::class, [
                'required' => true,
                'choices' => WebhookType::getEventsChoices(),
            ])
            ->add('package', EntityType::class, [
                'class' => Package::class,
                'choice_label' => 'name',
                'required' => false,
            ])
            ->add('versions', TextType::class, ['required' => false])
            ->add('payload', TextareaType::class, ['required' => false])
            ->add('sendReal', CheckboxType::class, [
                'required' => false,
                'label' => 'Send real request?'
            ])
            ->getForm();

        $errors = $response = null;
        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $data['user'] = $this->getUser();
                try {
                    $response = $this->testAction->runTest($entity, $data);
                } catch (\Throwable $exception) {
                    $errors = $exception->getMessage();
                }
            } else {
                $errors = $form->getErrors(true);
            }

            return $this->render('webhook/hook_widget.html.twig', [
                'response' => $response,
                'errors' => $errors
            ]);
        }

        return $this->render('webhook/test.html.twig', [
            'form' => $form->createView(),
            'entity' => $entity,
        ]);
    }

    /**
     * @param Request $request
     * @param Webhook $entity
     * @param $flashMessage
     *
     * @return array|RedirectResponse
     */
    protected function handleUpdate(Request $request, Webhook $entity, string $flashMessage)
    {
        $em = $this->registry->getManager();
        $form = $this->createForm(WebhookType::class, $entity);
        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $entity = $form->getData();
                $em->persist($entity);
                $em->flush();
                $this->addFlash('success', $flashMessage);
                return new RedirectResponse($this->generateUrl('webhook_index'));
            }
        }

        return [
            'form' => $form->createView(),
            'entity' => $entity
        ];
    }
}
