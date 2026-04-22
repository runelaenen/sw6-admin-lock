<?php
declare(strict_types=1);

namespace LaenenAdminLock\Subscriber;

use LaenenAdminLock\Core\Lock\RecordLockService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Backend write protection. Blocks customer / live-order writes that originate
 * from an administration UI session that does NOT own the lock for the entity.
 *
 * Three deliberate early-exits keep this subscriber free for all non-relevant
 * traffic on a busy installation:
 *   1. Non-AdminApiSource contexts (storefront, system, sync) are never blocked.
 *   2. Admin API requests without the per-tab session header are never blocked
 *      - this is the integration bypass for OAuth-token sync clients.
 *   3. Order writes against versioned drafts are never blocked - draft creation
 *      on order page open would otherwise need to be carved out individually.
 */
final readonly class RecordLockWriteProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RecordLockService $lockService,
        private RequestStack $requestStack
    ) {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'preValidate'];
    }

    public function preValidate(PreWriteValidationEvent $event): void
    {
        $context = $event->getContext();

        if (!$context->getSource() instanceof AdminApiSource) {
            return; // (1) Storefront / system contexts.
        }

        $request = $this->requestStack->getCurrentRequest();
        $ownerToken = $request?->headers->get(RecordLockService::HEADER_NAME);
        if ($ownerToken === null || $ownerToken === '') {
            return; // (2) Sync integrations (Sage 100, Klaviyo, warehouse).
        }

        $customerIds = $this->extractIds($event, 'customer');
        $orderIds = $context->getVersionId() === Defaults::LIVE_VERSION
            ? $this->extractIds($event, 'order')
            : []; // (3) Versioned drafts are not enforced at the backend.

        if ($customerIds === [] && $orderIds === []) {
            return;
        }

        $foreignLocks = $this->lockService->bulkForeignLocks(
            ['customer' => $customerIds, 'order' => $orderIds],
            $ownerToken
        );

        if ($foreignLocks === []) {
            return;
        }

        foreach ($foreignLocks as $row) {
            $event->getExceptions()->add(
                $this->buildViolationException(
                    (string)$row['entity_name'],
                    Uuid::fromBytesToHex((string)$row['entity_id']),
                    (string)($row['user_label'] ?? 'Another admin')
                )
            );
        }
    }

    /** @return list<string> hex ids, deduplicated */
    private function extractIds(PreWriteValidationEvent $event, string $entityName): array
    {
        $ids = [];
        foreach ($event->getPrimaryKeys($entityName) as $primaryKey) {
            $raw = $primaryKey['id'] ?? null;
            if (!is_string($raw)) {
                continue;
            }
            if (Uuid::isValid($raw)) {
                $ids[] = $raw;
            } elseif (strlen($raw) === 16) {
                $ids[] = Uuid::fromBytesToHex($raw);
            }
        }

        return array_values(array_unique($ids));
    }

    private function buildViolationException(
        string $entityName,
        string $entityId,
        string $ownerLabel
    ): WriteConstraintViolationException {
        $message = sprintf(
            'The %s is currently locked by %s. Use "Force unlock" or wait for the lease to expire.',
            $entityName,
            $ownerLabel
        );

        $violations = new ConstraintViolationList([
            new ConstraintViolation(
                $message,
                $message,
                [],
                null,
                'editLock',
                $entityId
            ),
        ]);

        return new WriteConstraintViolationException($violations, '/');
    }
}
