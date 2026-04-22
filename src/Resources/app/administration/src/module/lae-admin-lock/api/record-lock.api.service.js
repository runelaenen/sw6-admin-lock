const ApiService = Shopware.Classes.ApiService;

export default class RecordLockApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, 'lae-admin-lock');
        this.name = 'laeAdminLockApiService';
    }

    getStatus(entityName, entityId, additionalHeaders = {}) {
        return this.httpClient.get(
            `/_action/${this.getApiBasePath()}/${entityName}/${entityId}`,
            { headers: this.getBasicHeaders(additionalHeaders) },
        ).then((response) => ApiService.handleResponse(response));
    }

    acquire(entityName, entityId, note = null, additionalHeaders = {}) {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/${entityName}/${entityId}/acquire`,
            note ? { note } : {},
            { headers: this.getBasicHeaders(additionalHeaders) },
        ).then((response) => ApiService.handleResponse(response));
    }

    heartbeat(entityName, entityId, additionalHeaders = {}) {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/${entityName}/${entityId}/heartbeat`,
            {},
            { headers: this.getBasicHeaders(additionalHeaders) },
        ).then((response) => ApiService.handleResponse(response));
    }

    release(entityName, entityId, additionalHeaders = {}) {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/${entityName}/${entityId}/release`,
            {},
            { headers: this.getBasicHeaders(additionalHeaders) },
        ).then((response) => ApiService.handleResponse(response));
    }

    forceRelease(entityName, entityId, additionalHeaders = {}) {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/${entityName}/${entityId}/force-release`,
            {},
            { headers: this.getBasicHeaders(additionalHeaders) },
        ).then((response) => ApiService.handleResponse(response));
    }

    bulkStatus(idsByEntity, additionalHeaders = {}) {
        return this.httpClient.post(
            `/_action/${this.getApiBasePath()}/bulk-status`,
            idsByEntity,
            { headers: this.getBasicHeaders(additionalHeaders) },
        ).then((response) => ApiService.handleResponse(response));
    }

    listActive(additionalHeaders = {}) {
        return this.httpClient.get(
            `/_action/${this.getApiBasePath()}/active`,
            { headers: this.getBasicHeaders(additionalHeaders) },
        ).then((response) => ApiService.handleResponse(response));
    }
}
