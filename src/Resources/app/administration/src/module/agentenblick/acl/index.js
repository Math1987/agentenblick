Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'marketing',
    key: 'agentenblick',
    roles: {
        viewer: {
            privileges: ['agentenblick.viewer'],
            dependencies: [],
        },
        editor: {
            privileges: ['agentenblick.editor'],
            dependencies: ['agentenblick.viewer'],
        },
    },
});
