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
        if ($this->getUser() || $request->getSession()->get('is_test_mode')) {
            return $this->redirectToRoute('app_hub');
        }

        return $this->render('index.html.twig');
    }
}
