<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\Project;
use App\Entity\SubProject;
use App\Entity\EditorInteraction;
use App\Service\IA\DeepSeekService;
use App\Service\SuggestionService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EditorAIControllerTest extends WebTestCase
{
    private $client;
    private $user;
    private $project;
    private $subProject;
    private $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();

        // 1. Création utilisateur de test
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'ai-test@djoliba.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('ai-test@djoliba.com');
            $user->setPassword('password');
            $user->setFirstName('AiTester');
            $this->em->persist($user);
            $this->em->flush();
        }
        $this->user = $user;

        // 2. Création d'un SubProject de test
        $subProject = new SubProject();
        $subProject->setName('Sous-projet IA');
        $subProject->setType('writing');
        $subProject->setUser($user);
        $this->em->persist($subProject);
        $this->em->flush();
        $this->subProject = $subProject;

        // 3. Création d'un Project de test
        $project = new Project();
        $project->setName('Projet de rédaction IA');
        $project->setType('writing');
        $project->setUser($user);
        $project->setSubProject($subProject);
        $this->em->persist($project);
        $this->em->flush();
        $this->project = $project;
    }

    private function mockAIService(): void
    {
        $aiAssistantMock = $this->createMock(\App\Service\Editor\AIAssistantService::class);
        
        $aiAssistantMock->method('execute')->willReturnCallback(function($action, $text, $subProject, $user, $options) {
            $interaction = new EditorInteraction();
            $interaction->setAction($action);
            $interaction->setSelectedText($text);
            $interaction->setSubProject($subProject);
            $interaction->setUser($user);

            if ($action === 'reference') {
                $references = [
                    [
                        'title' => 'An Analysis of AI',
                        'authors' => 'Author A, Author B',
                        'year' => 2026,
                        'abstract' => 'This is an abstract',
                        'doi' => '10.1000/xyz123',
                        'verified' => true,
                        'url' => 'https://example.com/source',
                        'journal' => 'AI Journal'
                    ]
                ];
                $interaction->setSuggestion(json_encode($references));
                $this->em->persist($interaction);
                $this->em->flush();

                return [
                    'interaction_id' => $interaction->getId(),
                    'result' => json_encode($references),
                    'meta' => ['references' => $references]
                ];
            }

            if ($action === 'reasoning') {
                $reasoningResult = json_encode([
                    'analysis' => 'Mocked reasoning analysis',
                    'reformulation' => 'Mocked reformulation text'
                ]);
                $interaction->setSuggestion($reasoningResult);
                $this->em->persist($interaction);
                $this->em->flush();

                return [
                    'interaction_id' => $interaction->getId(),
                    'result' => $reasoningResult,
                    'meta' => []
                ];
            }

            if ($action === 'reformulate') {
                $reformulateResult = json_encode([
                    'options' => [
                        ['label' => 'Variation 1', 'text' => 'Mocked reformulation 1'],
                        ['label' => 'Variation 2', 'text' => 'Mocked reformulation 2'],
                        ['label' => 'Variation 3', 'text' => 'Mocked reformulation 3']
                    ]
                ]);
                $interaction->setSuggestion($reformulateResult);
                $this->em->persist($interaction);
                $this->em->flush();

                return [
                    'interaction_id' => $interaction->getId(),
                    'result' => $reformulateResult,
                    'meta' => []
                ];
            }

            $interaction->setSuggestion('Mocked AI response');
            $this->em->persist($interaction);
            $this->em->flush();

            return [
                'interaction_id' => $interaction->getId(),
                'result' => 'Mocked AI response',
                'meta' => []
            ];
        });

        $aiAssistantMock->method('stream')->will($this->returnCallback(function($action, $text, $subProject, $user, $callback, $options = []) {
            if (is_callable($callback)) {
                $callback('Mocked ');
                $callback('AI ');
                $callback('stream ');
                $callback('response');
            }

            $interaction = new EditorInteraction();
            $interaction->setAction($action);
            $interaction->setSelectedText($text);
            $interaction->setSuggestion('Mocked AI stream response');
            $interaction->setSubProject($subProject);
            $interaction->setUser($user);
            
            $this->em->persist($interaction);
            $this->em->flush();

            return $interaction->getId();
        }));

        static::getContainer()->set(\App\Service\Editor\AIAssistantService::class, $aiAssistantMock);
    }

    public function testExecuteAiAction(): void
    {
        $this->client->loginUser($this->user);
        $this->mockAIService();

        $url = sprintf('/api/projects/%d/editor-ai/execute', $this->project->getId());

        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'action' => 'equation',
            'text' => 'E=mc^2'
        ]));

        $this->assertResponseIsSuccessful();
        $res = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($res['success']);
        $this->assertNotNull($res['data']['interaction_id']);
        $this->assertEquals('Mocked AI response', $res['data']['result']);

        // Vérifier l'historique enregistré en base
        $interaction = $this->em->getRepository(EditorInteraction::class)->find($res['data']['interaction_id']);
        $this->assertNotNull($interaction);
        $this->assertEquals('equation', $interaction->getAction());
        $this->assertEquals('E=mc^2', $interaction->getSelectedText());
        $this->assertEquals('Mocked AI response', $interaction->getSuggestion());
    }

    public function testExecuteReasoningAction(): void
    {
        $this->client->loginUser($this->user);
        $this->mockAIService();

        $url = sprintf('/api/projects/%d/editor-ai/execute', $this->project->getId());

        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'action' => 'reasoning',
            'text' => 'Tous les chats ont quatre pattes'
        ]));

        $this->assertResponseIsSuccessful();
        $res = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($res['success']);
        $this->assertNotNull($res['data']['interaction_id']);
        
        $result = json_decode($res['data']['result'], true);
        $this->assertEquals('Mocked reasoning analysis', $result['analysis']);
        $this->assertEquals('Mocked reformulation text', $result['reformulation']);

        // Vérifier l'historique enregistré en base
        $interaction = $this->em->getRepository(EditorInteraction::class)->find($res['data']['interaction_id']);
        $this->assertNotNull($interaction);
        $this->assertEquals('reasoning', $interaction->getAction());
        $this->assertEquals('Tous les chats ont quatre pattes', $interaction->getSelectedText());
        $this->assertStringContainsString('Mocked reasoning analysis', $interaction->getSuggestion());
    }

    public function testExecuteReformulateAction(): void
    {
        $this->client->loginUser($this->user);
        $this->mockAIService();

        $url = sprintf('/api/projects/%d/editor-ai/execute', $this->project->getId());

        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'action' => 'reformulate',
            'text' => 'Texte original à reformuler'
        ]));

        $this->assertResponseIsSuccessful();
        $res = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($res['success']);
        $this->assertNotNull($res['data']['interaction_id']);
        
        $result = json_decode($res['data']['result'], true);
        $this->assertCount(3, $result['options']);
        $this->assertEquals('Variation 1', $result['options'][0]['label']);
        $this->assertEquals('Mocked reformulation 1', $result['options'][0]['text']);

        // Vérifier l'historique enregistré en base
        $interaction = $this->em->getRepository(EditorInteraction::class)->find($res['data']['interaction_id']);
        $this->assertNotNull($interaction);
        $this->assertEquals('reformulate', $interaction->getAction());
        $this->assertEquals('Texte original à reformuler', $interaction->getSelectedText());
    }

    public function testStreamAiAction(): void
    {
        $this->client->loginUser($this->user);
        $this->mockAIService();

        $container = static::getContainer();
        $dispatcher = $container->get('event_dispatcher');
        $listener = function (\Symfony\Component\HttpKernel\Event\ResponseEvent $event) {
            $response = $event->getResponse();
            if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                $initialObLevel = ob_get_level();
                ob_start();
                ob_start();
                try {
                    $response->sendContent();
                } finally {
                    $content = '';
                    if (ob_get_level() > $initialObLevel) {
                        $content = ob_get_clean();
                    }
                    while (ob_get_level() < $initialObLevel) {
                        ob_start();
                    }
                }
                
                $event->setResponse(new \Symfony\Component\HttpFoundation\Response(
                    $content,
                    $response->getStatusCode(),
                    $response->headers->all()
                ));
            }
        };
        $dispatcher->addListener('kernel.response', $listener, 1000);

        $url = sprintf('/api/projects/%d/editor-ai/stream', $this->project->getId());

        try {
            $this->client->request('POST', $url, [], [], [
                'CONTENT_TYPE' => 'application/json'
            ], json_encode([
                'action' => 'expand',
                'text' => 'Texte à reformuler'
            ]));
        } finally {
            $dispatcher->removeListener('kernel.response', $listener);
        }

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        
        $this->assertStringContainsString('data: {"chunk":"Mocked "}', $content);
        $this->assertStringContainsString('data: {"chunk":"AI "}', $content);
        $this->assertStringContainsString('data: {"chunk":"stream "}', $content);
        $this->assertStringContainsString('data: {"chunk":"response"}', $content);
        $this->assertStringContainsString('data: [DONE]', $content);
    }

    public function testGetHistoryAndStatusUpdate(): void
    {
        $this->client->loginUser($this->user);

        // 1. Ajouter manuellement une interaction dans l'historique
        $interaction = new EditorInteraction();
        $interaction->setSubProject($this->subProject);
        $interaction->setUser($this->user);
        $interaction->setAction('reasoning');
        $interaction->setSelectedText('Texte de test');
        $interaction->setSuggestion('Suggestion de test');
        $this->em->persist($interaction);
        $this->em->flush();

        $interactionId = $interaction->getId();

        // 2. Récupérer l'historique via l'API
        $historyUrl = sprintf('/api/projects/%d/editor-ai/history', $this->project->getId());
        $this->client->request('GET', $historyUrl);

        $this->assertResponseIsSuccessful();
        $res = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $res['data']);
        $this->assertEquals($interactionId, $res['data'][0]['id']);
        $this->assertEquals('reasoning', $res['data'][0]['action']);

        // 3. Modifier le statut d'acceptation de l'interaction
        $statusUrl = sprintf('/api/editor-ai/interaction/%d/status', $interactionId);
        $this->client->request('POST', $statusUrl, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'accepted' => true
        ]));

        $this->assertResponseIsSuccessful();
        $statusRes = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($statusRes['success']);
        $this->assertTrue($statusRes['data']['accepted']);

        // 4. Rafraîchir depuis la base pour s'assurer que c'est persisté
        $this->em->clear();
        $updatedInteraction = $this->em->getRepository(EditorInteraction::class)->find($interactionId);
        $this->assertTrue($updatedInteraction->isAccepted());
    }

    public function testSnapshotOperations(): void
    {
        $this->client->loginUser($this->user);

        // 1. Sauvegarder un instantané
        $saveUrl = sprintf('/api/projects/%d/snapshots', $this->project->getId());
        $this->client->request('POST', $saveUrl, [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode([
            'name' => 'Version Test Snapshot',
            'content_wysiwyg' => 'HTML content wysiwyg',
            'content_latex' => 'LaTeX content raw',
            'mode' => 'wysiwyg'
        ]));

        $this->assertResponseIsSuccessful();
        $res = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($res['success']);
        $this->assertEquals('Version Test Snapshot', $res['data']['name']);
        $snapshotId = $res['data']['id'];
        $this->assertNotEmpty($snapshotId);

        // 2. Récupérer la liste des instantanés
        $getUrl = sprintf('/api/projects/%d/snapshots', $this->project->getId());
        $this->client->request('GET', $getUrl);
        $this->assertResponseIsSuccessful();
        $listRes = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($listRes['success']);
        $this->assertCount(1, $listRes['data']);
        $this->assertEquals($snapshotId, $listRes['data'][0]['id']);

        // 3. Supprimer l'instantané
        $deleteUrl = sprintf('/api/projects/%d/snapshots/%s', $this->project->getId(), $snapshotId);
        $this->client->request('DELETE', $deleteUrl);
        $this->assertResponseIsSuccessful();
        $delRes = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($delRes['success']);

        // 4. Vérifier que la liste est vide à présent
        $this->client->request('GET', $getUrl);
        $this->assertResponseIsSuccessful();
        $emptyRes = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(0, $emptyRes['data']);
    }
}
