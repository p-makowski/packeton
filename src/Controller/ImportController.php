<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Job;
use Packeton\Form\Type\Package\ImportPackagesType;
use Packeton\Import\MassImportHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ImportController extends AbstractController
{
    public function __construct(
        protected MassImportHandler $importHandler,
        protected ManagerRegistry $registry,
    ){
    }

    #[Route('/import', name: 'package_import')]
    public function importAction(Request $request)
    {
        $form = $this->createForm(ImportPackagesType::class);
        if ($request->getMethod() === 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $import = $form->getData();
                $this->importHandler->createImportJob($import, $this->getUser());
                $this->addFlash('success', 'Import job was schedule');
                return $this->redirect($this->generateUrl('package_import'));
            }
        }

        $jobs = $this->registry->getRepository(Job::class)->findJobsByType('mass:import', limit: 10);
        return $this->render('import/import.html.twig', ['form' => $form->createView(), 'jobs' => $jobs]);
    }

    #[Route('/import/fetch-info', name: 'package_import_check', methods: ['POST'])]
    public function fetchImportInfo(Request $request)
    {
        $form = $this->createForm(ImportPackagesType::class);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $import = $form->getData();
            try {
                $repos = $this->importHandler->getRepoUrls($import);
            } catch (\Exception $e) {
                return new JsonResponse(['status' => 'error', 'reason' => $e->getMessage()]);
            }

            return new JsonResponse(['repos' => array_values($repos)]);
        }

        if ($form->isSubmitted()) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getMessage();
            }
            return new JsonResponse(['status' => 'error', 'reason' => $errors]);
        }

        return new JsonResponse(['status' => 'error', 'reason' => 'No data posted.']);
    }
}
