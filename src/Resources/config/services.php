<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use LaenenAdminLock\Core\Lock\RecordLockService;
use LaenenAdminLock\Controller\RecordLockController;
use LaenenAdminLock\Subscriber\RecordLockWriteProtectionSubscriber;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->set(RecordLockService::class);

    $services
        ->set(RecordLockController::class)
        ->public();

    $services->set(RecordLockWriteProtectionSubscriber::class);
};
