<?php
declare(strict_types=1);

namespace LaenenAdminLock\Controller;

use LaenenAdminLock\Core\Lock\RecordLockService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
final readonly class RecordLockController
{
    public function __construct(private RecordLockService $lockService)
    {
    }

    #[Route(
        path: '/api/_action/lae-admin-lock/{entityName}/{entityId}',
        name: 'api.action.lae_admin_lock.status',
        methods: ['GET']
    )]
    public function status(string $entityName, string $entityId, Context $context, Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->lockService->getStatus(
                $entityName,
                $entityId,
                $context,
                $request->headers->get(RecordLockService::HEADER_NAME)
            )
        );
    }

    #[Route(
        path: '/api/_action/lae-admin-lock/{entityName}/{entityId}/acquire',
        name: 'api.action.lae_admin_lock.acquire',
        methods: ['POST']
    )]
    public function acquire(string $entityName, string $entityId, Context $context, Request $request): JsonResponse
    {
        $payload = $this->safeJsonBody($request);
        $note = isset($payload['note']) && is_string($payload['note']) && trim($payload['note']) !== ''
            ? $payload['note']
            : null;

        $state = $this->lockService->acquire(
            $entityName,
            $entityId,
            $context,
            $request->headers->get(RecordLockService::HEADER_NAME),
            $note
        );

        return new JsonResponse($state, $this->resolveStatusCode($state));
    }

    #[Route(
        path: '/api/_action/lae-admin-lock/{entityName}/{entityId}/heartbeat',
        name: 'api.action.lae_admin_lock.heartbeat',
        methods: ['POST']
    )]
    public function heartbeat(string $entityName, string $entityId, Context $context, Request $request): JsonResponse
    {
        $state = $this->lockService->heartbeat(
            $entityName,
            $entityId,
            $context,
            $request->headers->get(RecordLockService::HEADER_NAME)
        );

        return new JsonResponse($state, $this->resolveStatusCode($state));
    }

    #[Route(
        path: '/api/_action/lae-admin-lock/{entityName}/{entityId}/release',
        name: 'api.action.lae_admin_lock.release',
        methods: ['POST']
    )]
    public function release(string $entityName, string $entityId, Context $context, Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->lockService->release(
                $entityName,
                $entityId,
                $context,
                $request->headers->get(RecordLockService::HEADER_NAME)
            )
        );
    }

    #[Route(
        path: '/api/_action/lae-admin-lock/{entityName}/{entityId}/force-release',
        name: 'api.action.lae_admin_lock.force_release',
        methods: ['POST']
    )]
    public function forceRelease(string $entityName, string $entityId, Context $context): JsonResponse
    {
        return new JsonResponse($this->lockService->forceRelease($entityName, $entityId, $context));
    }

    #[Route(
        path: '/api/_action/lae-admin-lock/bulk-status',
        name: 'api.action.lae_admin_lock.bulk_status',
        methods: ['POST']
    )]
    public function bulkStatus(Context $context, Request $request): JsonResponse
    {
        $payload = $this->safeJsonBody($request);
        $idsByEntity = [];

        foreach (['customer', 'order'] as $entityName) {
            $ids = $payload[$entityName] ?? [];
            if (is_array($ids)) {
                $idsByEntity[$entityName] = array_values(array_filter($ids, 'is_string'));
            }
        }

        return new JsonResponse(
            $this->lockService->bulkStatus(
                $idsByEntity,
                $context,
                $request->headers->get(RecordLockService::HEADER_NAME)
            )
        );
    }

    #[Route(
        path: '/api/_action/lae-admin-lock/active',
        name: 'api.action.lae_admin_lock.active',
        methods: ['GET']
    )]
    public function active(Context $context): JsonResponse
    {
        return new JsonResponse(['locks' => $this->lockService->listActive($context)]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveStatusCode(array $state): int
    {
        return ($state['locked'] ?? false) === true && ($state['ownedByCurrentSession'] ?? false) !== true
            ? Response::HTTP_CONFLICT
            : Response::HTTP_OK;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeJsonBody(Request $request): array
    {
        $content = (string)$request->getContent();
        if ($content === '') {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
