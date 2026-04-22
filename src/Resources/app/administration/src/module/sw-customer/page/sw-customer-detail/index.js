import template from './sw-customer-detail.html.twig';

const { Component, Mixin } = Shopware;

export default Component.wrapComponentConfig({
    template,

    mixins: [
        Mixin.getByName('laeAdminLock'),
    ],

    methods: {
        async createdComponent() {
            await this.$super('createdComponent');
            await this.laeLockInit('customer', this.customerId);

            // If the page somehow opened in editMode while another admin holds
            // the lock, exit editMode so the user is not in a draft they cannot save.
            if (this.editMode && this.laeLockIsForeign) {
                this.editMode = false;
            }
        },

        // Edit-mode entry is the natural choke point for customer locking.
        async onActivateCustomerEditMode() {
            const ok = await this.laeLockEnsureOwnership();
            if (!ok) {
                this.laeLockNotifyConflict();
                return;
            }
            return this.$super('onActivateCustomerEditMode');
        },

        // Save MUST verify ownership before mutating, but MUST NOT release on success.
        async onSave() {
            if (!this.editMode) {
                return this.$super('onSave');
            }
            const ok = await this.laeLockEnsureOwnership();
            if (!ok) {
                this.laeLockNotifyConflict();
                return false;
            }
            return this.$super('onSave');
        },

        // Cancel discards the form draft, but the lock is held until the CSR
        // explicitly clicks "Unlock" on the lock bar.
        async onAbortButtonClick() {
            return this.$super('onAbortButtonClick');
        },

        // Lock-bar handlers are bound from the twig override.
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

        // Reactive abort if the lock disappears while in edit mode.
        async laeLockOnStatusChange(currentState, previousState) {
            const lostOwnership = this.editMode
                && currentState.locked === true
                && currentState.ownedByCurrentSession !== true
                && (previousState.ownedByCurrentSession === true || previousState.locked === false);

            if (!lostOwnership) {
                return;
            }

            this.createNotificationWarning({
                message: this.$tc('lae-admin-lock.common.lostLock'),
            });

            // Exit edit mode without releasing; lock is now foreign.
            await this.$super('onAbortButtonClick');
        },
    },
});
