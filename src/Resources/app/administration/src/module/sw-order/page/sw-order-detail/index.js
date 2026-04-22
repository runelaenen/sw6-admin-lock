import template from './sw-order-detail.html.twig';

const { Component, Mixin } = Shopware;

export default Component.wrapComponentConfig({
    template,

    mixins: [
        Mixin.getByName('laeAdminLock'),
    ],

    data() {
        return {
            _laeOrderEditingState: false,
            _laeConflictHandlingActive: false,
        };
    },

    methods: {
        async createdComponent() {
            await this.$super('createdComponent');
            await this.laeLockInit('order', this.orderId);
        },

        // Order detail page transitions in/out of editing via updateEditing(value).
        // We acquire on the first transition into editing and cancel the local
        // editing session if ownership cannot be obtained.
        async updateEditing(value) {
            const previous = this._laeOrderEditingState;
            this._laeOrderEditingState = value;

            this.$super('updateEditing', value);

            if (value === previous) {
                return;
            }

            // Going out of edit mode: do NOT release - lock survives until
            // the CSR explicitly clicks Unlock.
            if (value === false) {
                return;
            }

            const ok = await this.laeLockEnsureOwnership();
            if (ok) {
                return;
            }

            this.laeLockNotifyConflict();
            await this.laeAbortOrderEditDueToConflict();
        },

        // All save / recalculate paths go through ensureOwnership first but
        // never release on success.
        async onSaveEdits() {
            const ok = await this.laeLockEnsureOwnership();
            if (!ok) {
                this.laeLockNotifyConflict();
                return;
            }
            return this.$super('onSaveEdits');
        },

        async saveAndReload(afterSaveFn = null) {
            const ok = await this.laeLockEnsureOwnership();
            if (!ok) {
                this.laeLockNotifyConflict();
                return;
            }
            return this.$super('saveAndReload', afterSaveFn);
        },

        async onSaveAndRecalculate() {
            const ok = await this.laeLockEnsureOwnership();
            if (!ok) {
                this.laeLockNotifyConflict();
                return;
            }
            return this.$super('onSaveAndRecalculate');
        },

        async onRecalculateAndReload() {
            const ok = await this.laeLockEnsureOwnership();
            if (!ok) {
                this.laeLockNotifyConflict();
                return;
            }
            return this.$super('onRecalculateAndReload');
        },

        // Cancel exits edit mode but the lock is preserved.
        async onCancelEditing() {
            return this.$super('onCancelEditing');
        },

        // Lock-bar handlers, bound from the twig override.
        async onLaeLockClick() {
            await this.laeLockAcquire();
        },

        async onLaeUnlockClick() {
            await this.laeLockRelease();
        },

        async onLaeForceUnlockClick() {
            await this.laeLockForceRelease();
        },

        async onLaeRefreshClick() {
            this.laeLockBusy = true;
            try {
                await this.laeLockRefreshStatus(false);
            } finally {
                this.laeLockBusy = false;
            }
        },

        async laeAbortOrderEditDueToConflict() {
            if (this._laeConflictHandlingActive) {
                return;
            }
            this._laeConflictHandlingActive = true;
            try {
                await this.$super('onCancelEditing');
            } finally {
                this._laeConflictHandlingActive = false;
            }
        },

        // Reactive abort: lock vanished while we were editing.
        async laeLockOnStatusChange(currentState, previousState) {
            const lostOwnership = this.isOrderEditing
                && currentState.locked === true
                && currentState.ownedByCurrentSession !== true
                && (previousState.ownedByCurrentSession === true || previousState.locked === false);

            if (!lostOwnership) {
                return;
            }

            this.createNotificationWarning({
                message: this.$tc('lae-admin-lock.common.lostLock'),
            });

            await this.laeAbortOrderEditDueToConflict();
        },
    },
});
