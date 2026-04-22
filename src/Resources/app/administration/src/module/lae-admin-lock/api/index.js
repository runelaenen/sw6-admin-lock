import RecordLockApiService from './record-lock.api.service';
import { ensureLockSessionInterceptor } from '../service/lock-session.service';

const initContainer = Shopware.Application.getContainer('init');

ensureLockSessionInterceptor();

Shopware.Service().register('laeAdminLockApiService', () => {
    return new RecordLockApiService(
        initContainer.httpClient,
        Shopware.Service('loginService'),
    );
});
