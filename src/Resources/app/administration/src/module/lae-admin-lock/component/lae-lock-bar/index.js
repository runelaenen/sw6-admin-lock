import template from './lae-lock-bar.html.twig';
import './lae-lock-bar.scss';

const { Component } = Shopware;

Component.register('lae-lock-bar', {
    template,

    props: {
        state: {
            type: Object,
            required: true,
        },
        loading: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    emits: ['lock', 'unlock', 'force-unlock', 'refresh'],

    computed: {
        variant() {
            if (!this.state.locked) {
                return 'idle';
            }
            if (this.state.ownedByCurrentSession) {
                return 'owned';
            }
            if (this.state.ownedByCurrentUser) {
                return 'ownedElsewhere';
            }
            return 'foreign';
        },

        canForceUnlock() {
            return this.state.canForceRelease === true;
        },

        ownerLabel() {
            return this.state.owner?.label
                || this.$tc('lae-admin-lock.common.anotherAdmin');
        },

        formattedExpiresAt() {
            return this.formatTimestamp(this.state.expiresAt);
        },

        formattedLockedAt() {
            return this.formatTimestamp(this.state.lockedAt);
        },
    },

    methods: {
        formatTimestamp(value) {
            if (!value) {
                return '';
            }
            try {
                return Shopware.Utils.format.dateTime(new Date(value));
            } catch (e) {
                return value;
            }
        },

        emitLock() {
            this.$emit('lock');
        },

        emitUnlock() {
            this.$emit('unlock');
        },

        emitForceUnlock() {
            this.$emit('force-unlock');
        },

        emitRefresh() {
            this.$emit('refresh');
        },
    },
});
