<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TestModeController extends AbstractController
{
    #[Route('/test-mode/toggle', name: 'app_test_mode_toggle')]
    public function toggle(Request $request): Response
    {
        $session = $request->getSession();
        $isTestMode = $session->get('is_test_mode', false);
        $session->set('is_test_mode', !$isTestMode);

        return $this->redirectToRoute('app_hub');
    }

    #[Route('/test-mode/enable', name: 'app_test_mode_enable')]
    public function enable(Request $request): Response 
    {
        $session = $request->getSession();
        $session->set('is_test_mode', true);

        return $this->redirectToRoute('app_hub');
    }
}
