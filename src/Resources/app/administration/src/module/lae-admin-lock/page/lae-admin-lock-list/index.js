import template from './lae-admin-lock-list.html.twig';

const { Component } = Shopware;

Component.register('lae-admin-lock-list', {
    template,

    inject: ['laeAdminLockApiService', 'acl'],

    mixins: ['notification'],

    data() {
        return {
            locks: [],
            isLoading: false,
            refreshTimer: null,
        };
    },

    computed: {
        columns() {
            return [
                { property: 'entityName',  label: this.$tc('lae-admin-lock.overview.colEntity'), allowResize: true },
                { property: 'entityId',    label: this.$tc('lae-admin-lock.overview.colEntityId'), allowResize: true },
                { property: 'owner.label', label: this.$tc('lae-admin-lock.overview.colOwner'),  allowResize: true },
                { property: 'lockedAt',    label: this.$tc('lae-admin-lock.overview.colLockedAt'), allowResize: true },
                { property: 'expiresAt',   label: this.$tc('lae-admin-lock.overview.colExpiresAt'), allowResize: true },
                { property: 'note',        label: this.$tc('lae-admin-lock.overview.colNote'),   allowResize: true },
            ];
        },

        canForceUnlock() {
            return this.acl.can('lae_admin_lock.force_unlock');
        },
    },

    created() {
        this.loadLocks();
        this.refreshTimer = window.setInterval(() => this.loadLocks(true), 30 * 1000);
    },

    beforeUnmount() {
        if (this.refreshTimer) {
            window.clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        }
    },

    methods: {
        async loadLocks(silent = false) {
            if (!silent) {
                this.isLoading = true;
            }
            try {
                const response = await this.laeAdminLockApiService.listActive();
                this.locks = Array.isArray(response?.locks) ? response.locks : [];
            } catch (error) {
                if (!silent) {
                    this.createNotificationError({
                        message: this.$tc('lae-admin-lock.overview.loadFailed'),
                    });
                }
            } finally {
                this.isLoading = false;
            }
        },

        async forceUnlock(item) {
            if (!item || !item.entityName || !item.entityId) {
                return;
            }
            try {
                await this.laeAdminLockApiService.forceRelease(item.entityName, item.entityId);
                this.createNotificationSuccess({
                    message: this.$tc('lae-admin-lock.common.forceReleaseSuccess'),
                });
                this.loadLocks();
            } catch (error) {
                this.createNotificationError({
                    message: this.$tc('lae-admin-lock.common.forceReleaseFailed'),
                });
            }
        },

        formatTimestamp(value) {
            if (!value) return '';
            try {
                return Shopware.Utils.format.dateTime(new Date(value));
            } catch (e) {
                return value;
            }
        },

        detailRouterLink(item) {
            if (item.entityName === 'order') {
                return { name: 'sw.order.detail', params: { id: item.entityId } };
            }
            if (item.entityName === 'customer') {
                return { name: 'sw.customer.detail', params: { id: item.entityId } };
            }
            return null;
        },
    },
});
