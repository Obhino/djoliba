<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $isTestMode = $request->hasSession() ? $request->getSession()?->get('is_test_mode') : false;
        if ($this->getUser() || $isTestMode) {
            return $this->redirectToRoute('app_hub');
        }

        return $this->render('index.html.twig');
    }
}
