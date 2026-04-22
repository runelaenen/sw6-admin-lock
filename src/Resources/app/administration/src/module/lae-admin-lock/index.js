import './acl';
import './api';
import './component/lae-lock-bar';
import './mixin/entity-lock.mixin';
import './page/lae-admin-lock-list';

const { Module } = Shopware;

Module.register('lae-admin-lock', {
    type: 'plugin',
    name: 'lae-admin-lock',
    title: 'lae-admin-lock.module.title',
    description: 'lae-admin-lock.module.description',
    color: '#62cccc',
    icon: 'regular-lock',

    routes: {
        index: {
            component: 'lae-admin-lock-list',
            path: 'index',
            meta: {
                privilege: 'lae_admin_lock.viewer',
            },
        },
    },

    settingsItem: [{
        group: 'system',
        to: 'lae.admin.lock.index',
        icon: 'regular-lock',
        privilege: 'lae_admin_lock.viewer',
        label: 'lae-admin-lock.module.title',
    }],
});
