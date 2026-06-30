<?php

namespace App\Service\Editor;

use App\Entity\EditorInteraction;
use App\Entity\SubProject;
use App\Entity\User;

class AIAssistantService
{
    public function __construct(
        private ReasoningVerifier $reasoningVerifier,
        private Reformulator $reformulator,
        private EquationChecker $equationChecker,
        private IdeaExpander $ideaExpander,
        private AIResponder $aiResponder,
        private ReferenceSuggester $referenceSuggester,
        private RedundancyDetector $redundancyDetector,
        private CodeGenerator $codeGenerator,
        private PeerReviewSimulator $peerReviewSimulator,
        private AcademicTranslator $academicTranslator,
        private AcademicToneAdjuster $academicToneAdjuster,
        private ConceptExplainer $conceptExplainer,
        private EditorHistoryManager $historyManager
    ) {
    }

    /**
     * Exécute une action IA synchrone (non-streaming) et l'enregistre dans l'historique.
     */
    public function execute(string $action, string $text, ?SubProject $subProject, ?User $user, array $options = []): array
    {
        $result = '';
        $meta = [];

        switch ($action) {
            case 'reasoning':
                $result = $this->reasoningVerifier->verify($text);
                break;
            case 'equation':
                $result = $this->equationChecker->check($text);
                break;
            case 'reference':
                $references = $this->referenceSuggester->suggestReferences($text);
                $result = json_encode($references, JSON_UNESCAPED_UNICODE);
                $meta['references'] = $references;
                break;
            case 'redundancy':
                $result = $this->redundancyDetector->detect($text);
                break;
            case 'reformulate':
                $result = $this->reformulator->reformulate($text);
                break;
            case 'expand':
                $result = $this->ideaExpander->expand($text);
                break;
            case 'ask':
                $question = $options['question'] ?? '';
                $result = $this->aiResponder->ask($text, $question);
                break;
            case 'code':
                $language = $options['language'] ?? 'python';
                $result = $this->codeGenerator->generate($text, $language);
                break;
            case 'peer_review':
                $result = $this->peerReviewSimulator->simulate($text);
                break;
            case 'translate':
                $targetLanguage = $options['target_language'] ?? 'anglais';
                $result = $this->academicTranslator->translate($text, $targetLanguage);
                break;
            case 'tone':
                $register = $options['register'] ?? 'Voix Active Directe';
                $result = $this->academicToneAdjuster->adjust($text, $register);
                break;
            case 'explain':
                $result = $this->conceptExplainer->explain($text);
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Action inconnue : %s', $action));
        }

        // Log de l'interaction
        $interaction = $this->historyManager->logInteraction($subProject, $user, $action, $text, $result);

        return [
            'interaction_id' => $interaction->getId(),
            'result' => $result,
            'meta' => $meta
        ];
    }

    /**
     * Exécute une action IA en streaming SSE et l'enregistre dans l'historique une fois terminée.
     */
    public function stream(string $action, string $text, ?SubProject $subProject, ?User $user, callable $chunkCallback, array $options = []): int
    {
        $fullResponse = '';
        $callbackWrapper = function (string $chunk) use ($chunkCallback, &$fullResponse) {
            $fullResponse .= $chunk;
            $chunkCallback($chunk);
        };

        switch ($action) {
            case 'reformulate':
                $this->reformulator->streamReformulate($text, $callbackWrapper);
                break;
            case 'expand':
                $this->ideaExpander->streamExpand($text, $callbackWrapper);
                break;
            case 'ask':
                $question = $options['question'] ?? '';
                $this->aiResponder->streamAsk($text, $question, $callbackWrapper);
                break;
            case 'code':
                $language = $options['language'] ?? 'python';
                $this->codeGenerator->streamGenerate($text, $language, $callbackWrapper);
                break;
            case 'peer_review':
                $this->peerReviewSimulator->streamSimulate($text, $callbackWrapper);
                break;
            case 'translate':
                $targetLanguage = $options['target_language'] ?? 'anglais';
                $this->academicTranslator->streamTranslate($text, $targetLanguage, $callbackWrapper);
                break;
            case 'tone':
                $register = $options['register'] ?? 'Voix Active Directe';
                $this->academicToneAdjuster->streamAdjust($text, $register, $callbackWrapper);
                break;
            default:
                // Pour les actions ne supportant pas nativement le streaming, on simule l'appel classique puis on diffuse d'un coup
                $res = $this->execute($action, $text, $subProject, $user, $options);
                $chunkCallback($res['result']);
                return $res['interaction_id'];
        }

        // Log final de l'interaction en base
        $interaction = $this->historyManager->logInteraction($subProject, $user, $action, $text, $fullResponse);

        return $interaction->getId();
    }
}
