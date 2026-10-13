<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\Controller;

use Koh\StructuredContent\Application\Service\RelationshipService;
use Koh\StructuredContent\Domain\Enum\RelationType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class RelationshipController
{
    public function __construct(
        private RelationshipService $relationshipService,
        private ModuleTemplateFactory $moduleTemplateFactory,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $params = array_merge($request->getQueryParams(), (array) ($request->getParsedBody() ?? []));
        $table = is_string($params['table'] ?? null) ? $params['table'] : 'pages';
        $uid = isset($params['uid']) ? (int) $params['uid'] : 0;

        $relations = [];
        if ($uid > 0) {
            $relations = $this->relationshipService->getRelationsFor(table: $table, uid: $uid);
        }

        $moduleTemplate->assignMultiple([
            'table' => $table,
            'uid' => $uid,
            'relations' => $relations,
            'relationTypes' => RelationType::cases(),
        ]);

        return $moduleTemplate->renderResponse('Relationship/List');
    }

    public function addAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid request body'], 400);
        }

        $sourceTable = is_string($body['sourceTable'] ?? null) ? $body['sourceTable'] : '';
        $sourceUid = isset($body['sourceUid']) ? (int) $body['sourceUid'] : 0;
        $targetTable = is_string($body['targetTable'] ?? null) ? $body['targetTable'] : '';
        $targetUid = isset($body['targetUid']) ? (int) $body['targetUid'] : 0;
        $typeValue = is_string($body['relationType'] ?? null) ? $body['relationType'] : '';
        $direction = is_string($body['direction'] ?? null) ? $body['direction'] : 'unidirectional';
        $confidence = isset($body['confidence']) ? (float) $body['confidence'] : 1.0;

        if ('' === $sourceTable || '' === $targetTable) {
            return new JsonResponse(['error' => 'sourceTable and targetTable must be non-empty'], 400);
        }

        if ($sourceUid <= 0 || $targetUid <= 0) {
            return new JsonResponse(['error' => 'sourceUid and targetUid must be greater than 0'], 400);
        }

        if (!in_array($direction, ['unidirectional', 'bidirectional'], true)) {
            return new JsonResponse(['error' => 'direction must be "unidirectional" or "bidirectional"'], 400);
        }

        if ($confidence < 0.0 || $confidence > 1.0) {
            return new JsonResponse(['error' => 'confidence must be between 0.0 and 1.0'], 400);
        }

        $type = RelationType::tryFrom($typeValue);
        if (null === $type) {
            return new JsonResponse(['error' => 'Invalid relation type'], 400);
        }

        try {
            $this->relationshipService->addRelation(
                sourceTable: $sourceTable,
                sourceUid: $sourceUid,
                targetTable: $targetTable,
                targetUid: $targetUid,
                type: $type,
                direction: $direction,
                confidence: $confidence,
                createdBy: 'backend_user',
            );
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => 'Could not add relation'], 409);
        }

        return new JsonResponse(['success' => true]);
    }
}
