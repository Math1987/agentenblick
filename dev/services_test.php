<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Disposable bench only. APP_ENV=test enables our fictitious catalogue, but
// Shopware's internal DAL fixtures are not real shop entities and break its
// administration schema (e.g. WriteProtectedRelationDefinition.system_wp_id).
// Keep the actual storefront/admin services and exclude only those fixtures.
return static function (ContainerConfigurator $config, ContainerBuilder $container): void {
    $container->addCompilerPass(new class implements CompilerPassInterface {
        public function process(ContainerBuilder $container): void
        {
            foreach ($container->findTaggedServiceIds('shopware.entity.definition') as $id => $tags) {
                if (str_starts_with($id, 'Shopware\\Core\\Framework\\Test\\')) {
                    $container->getDefinition($id)->clearTag('shopware.entity.definition');
                }
            }
        }
    }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
};
