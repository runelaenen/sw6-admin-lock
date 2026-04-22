/**
 * Registers two custom privileges so they are visible in the role-management UI.
 *
 *   lae_admin_lock.viewer        - can open /sw/settings/lae-admin-lock overview
 *   lae_admin_lock.force_unlock  - can break a foreign lock via the bar / overview
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'system',
    key: 'lae_admin_lock',
    roles: {
        viewer: {
            privileges: [
                'lae_admin_lock.viewer',
            ],
            dependencies: [],
        },
        force_unlock: {
            privileges: [
                'lae_admin_lock.force_unlock',
            ],
            dependencies: [
                'lae_admin_lock.viewer',
            ],
        },
    },
});
