import './acl';
import './page/agentenblick-index';
import AgentenblickApiService from './service/agentenblick.api.service';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

Shopware.Service().register('agentenblickApiService', () => new AgentenblickApiService(
    Shopware.Application.getContainer('init').httpClient,
    Shopware.Service('loginService'),
));

Shopware.Module.register('aithos-agentenblick', {
    type: 'plugin',
    routePrefixName: 'agentenblick',
    routePrefixPath: 'agentenblick',
    name: 'Agentenblick',
    title: 'agentenblick.general.title',
    description: 'agentenblick.general.description',
    color: '#147d75',
    icon: 'regular-chart-pie',
    snippets: { 'en-GB': enGB, 'de-DE': deDE },
    routes: {
        index: {
            component: 'agentenblick-index',
            path: 'index',
            meta: { privilege: 'agentenblick.viewer' },
        },
    },
    navigation: [
        {
            id: 'agentenblick',
            parent: 'sw-marketing',
            path: 'agentenblick.index',
            label: 'agentenblick.general.menu',
            color: '#147d75',
            icon: 'regular-chart-pie',
            position: 70,
            privilege: 'agentenblick.viewer',
        },
    ],
});
