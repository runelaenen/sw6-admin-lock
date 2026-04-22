/**
 * Shared mixin for lock-aware detail pages.
 *
 * Behavior changes vs v1 (PartsTree-driven):
 *   - The lock SURVIVES save. It is released only by explicit unlock,
 *     foreign take-over, force-release, or TTL expiry.
 *   - beforeUnmount does NOT auto-release the lock. Accidental tab close
 *     should not drop a long-running CSR session; TTL covers true abandonment.
 */

const LOCK_DEFAULTS = {
    entityName: null,
    entityId: null,
    locked: false,
    ownedByCurrentSession: false,
    ownedByCurrentUser: false,
    owner: null,
    note: null,
    lockedAt: null,
    heartbeatAt: null,
    expiresAt: null,
    ttlSeconds: 1800,
    heartbeatIntervalSeconds: 60,
    statusPollIntervalSeconds: 15,
    canForceRelease: false,
};

function emptyState() {
    return { ...LOCK_DEFAULTS };
}

Shopware.Mixin.register('laeAdminLock', {
    inject: ['laeAdminLockApiService'],

    data() {
        return {
            laeLockEntityName: null,
            laeLockEntityId: null,
            laeLockState: emptyState(),
            laeLockBusy: false,
            _laeLockStatusTimer: null,
            _laeLockHeartbeatTimer: null,
            _laeLockAcquirePromise: null,
        };
    },

    computed: {
        laeLockIsOwned() {
            return this.laeLockState.ownedByCurrentSession === true;
        },

        laeLockIsForeign() {
            return this.laeLockState.locked === true
                && this.laeLockState.ownedByCurrentSession !== true;
        },

        laeLockOwnerLabel() {
            return this.laeLockState.owner?.label
                || this.$tc('lae-admin-lock.common.anotherAdmin');
        },
    },

    beforeUnmount() {
        // Stop timers but DO NOT release - the lock must survive accidental
        // navigation away. TTL is the safety net.
        this.laeLockStopPolling();
        this.laeLockStopHeartbeat();
    },

    methods: {
        // -------------------- lifecycle --------------------

        async laeLockInit(entityName, entityId) {
            if (!entityName || !entityId) {
                return;
            }

            const entityChanged = this.laeLockEntityName !== entityName
                || this.laeLockEntityId !== entityId;

            if (entityChanged) {
                this.laeLockStopPolling();
                this.laeLockStopHeartbeat();
            }

            this.laeLockEntityName = entityName;
            this.laeLockEntityId = entityId;
            this.laeLockState = { ...emptyState(), entityName, entityId };

            await this.laeLockRefreshStatus(true);
            this.laeLockStartPolling();
        },

        // -------------------- state application --------------------

        laeLockApplyState(nextState) {
            const previousState = { ...emptyState(), ...(this.laeLockState || {}) };

            this.laeLockState = {
                ...emptyState(),
                ...(nextState || {}),
                entityName: this.laeLockEntityName,
                entityId: this.laeLockEntityId,
            };

            if (this.laeLockIsOwned) {
                this.laeLockStartHeartbeat();
            } else {
                this.laeLockStopHeartbeat();
            }

            if (typeof this.laeLockOnStatusChange === 'function') {
                this.laeLockOnStatusChange(this.laeLockState, previousState);
            }
        },

        // -------------------- API calls --------------------

        async laeLockRefreshStatus(silent = false) {
            if (!this.laeLockEntityName || !this.laeLockEntityId) {
                return this.laeLockState;
            }
            try {
                const state = await this.laeAdminLockApiService.getStatus(
                    this.laeLockEntityName,
                    this.laeLockEntityId,
                );
                this.laeLockApplyState(state);
                return state;
            } catch (error) {
                if (!silent) {
                    this.laeLockNotifyAcquireFailure();
                }
                return this.laeLockState;
            }
        },

        async laeLockAcquire(note = null, options = {}) {
            const { silent = false } = options;

            if (!this.laeLockEntityName || !this.laeLockEntityId) {
                return false;
            }
            if (this.laeLockIsOwned) {
                return true;
            }
            if (this._laeLockAcquirePromise) {
                return this._laeLockAcquirePromise;
            }

            this.laeLockBusy = true;
            this._laeLockAcquirePromise = this.laeAdminLockApiService
                .acquire(this.laeLockEntityName, this.laeLockEntityId, note)
                .then((state) => {
                    this.laeLockApplyState(state);
                    return this.laeLockIsOwned;
                })
                .catch((error) => {
                    const state = error?.response?.data;
                    if (state && typeof state === 'object') {
                        this.laeLockApplyState(state);
                    } else if (!silent) {
                        this.laeLockNotifyAcquireFailure();
                    }
                    return false;
                })
                .finally(() => {
                    this._laeLockAcquirePromise = null;
                    this.laeLockBusy = false;
                });

            return this._laeLockAcquirePromise;
        },

        async laeLockEnsureOwnership() {
            if (this.laeLockIsOwned) {
                return true;
            }
            if (this.laeLockIsForeign) {
                return false;
            }
            return this.laeLockAcquire();
        },

        async laeLockHeartbeat() {
            if (!this.laeLockIsOwned) {
                return;
            }
            try {
                const state = await this.laeAdminLockApiService.heartbeat(
                    this.laeLockEntityName,
                    this.laeLockEntityId,
                );
                this.laeLockApplyState(state);
            } catch (error) {
                const state = error?.response?.data;
                if (state && typeof state === 'object') {
                    this.laeLockApplyState(state);
                }
            }
        },

        async laeLockRelease() {
            if (!this.laeLockEntityName || !this.laeLockEntityId || !this.laeLockIsOwned) {
                return this.laeLockState;
            }
            this.laeLockBusy = true;
            try {
                const state = await this.laeAdminLockApiService.release(
                    this.laeLockEntityName,
                    this.laeLockEntityId,
                );
                this.laeLockApplyState(state);
                return state;
            } catch (error) {
                return this.laeLockState;
            } finally {
                this.laeLockBusy = false;
            }
        },

        async laeLockForceRelease() {
            if (!this.laeLockEntityName || !this.laeLockEntityId) {
                return this.laeLockState;
            }
            this.laeLockBusy = true;
            try {
                const state = await this.laeAdminLockApiService.forceRelease(
                    this.laeLockEntityName,
                    this.laeLockEntityId,
                );
                this.laeLockApplyState(state);

                if (typeof this.createNotificationSuccess === 'function') {
                    this.createNotificationSuccess({
                        message: this.$tc('lae-admin-lock.common.forceReleaseSuccess'),
                    });
                }

                return state;
            } catch (error) {
                if (typeof this.createNotificationError === 'function') {
                    this.createNotificationError({
                        message: this.$tc('lae-admin-lock.common.forceReleaseFailed'),
                    });
                }
                return this.laeLockState;
            } finally {
                this.laeLockBusy = false;
            }
        },

        // -------------------- timers --------------------

        laeLockStartPolling() {
            this.laeLockStopPolling();
            if (!this.laeLockEntityName || !this.laeLockEntityId) {
                return;
            }
            const ms = Number(this.laeLockState.statusPollIntervalSeconds || 15) * 1000;
            this._laeLockStatusTimer = window.setInterval(() => {
                this.laeLockRefreshStatus(true);
            }, ms);
        },

        laeLockStopPolling() {
            if (this._laeLockStatusTimer) {
                window.clearInterval(this._laeLockStatusTimer);
                this._laeLockStatusTimer = null;
            }
        },

        laeLockStartHeartbeat() {
            this.laeLockStopHeartbeat();
            if (!this.laeLockIsOwned) {
                return;
            }
            const ms = Number(this.laeLockState.heartbeatIntervalSeconds || 60) * 1000;
            this._laeLockHeartbeatTimer = window.setInterval(() => {
                this.laeLockHeartbeat();
            }, ms);
        },

        laeLockStopHeartbeat() {
            if (this._laeLockHeartbeatTimer) {
                window.clearInterval(this._laeLockHeartbeatTimer);
                this._laeLockHeartbeatTimer = null;
            }
        },

        // -------------------- notifications --------------------

        laeLockNotifyConflict() {
            if (typeof this.createNotificationWarning !== 'function') {
                return;
            }
            const msg = this.laeLockState.ownedByCurrentUser
                ? this.$tc('lae-admin-lock.common.lockedByCurrentUserOtherSession')
                : this.$tc('lae-admin-lock.common.lockedByAnotherAdmin', 0, {
                    owner: this.laeLockOwnerLabel,
                });
            this.createNotificationWarning({ message: msg });
        },

        laeLockNotifyAcquireFailure() {
            if (typeof this.createNotificationError !== 'function') {
                return;
            }
            this.createNotificationError({
                message: this.$tc('lae-admin-lock.common.acquireFailed'),
            });
        },
    },
});
