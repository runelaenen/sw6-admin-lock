Shopware.Component.override(
    'sw-customer-detail',
    () => import('./page/sw-customer-detail'),
);

Shopware.Component.override(
    'sw-customer-imitate-customer-modal',
    () => import('./component/sw-customer-imitate-customer-modal'),
);
