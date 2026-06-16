<?php

declare(strict_types=1);

use LiquidRazor\DtoApiBundle\Command\GenerateTsTypesCommand;
use LiquidRazor\DtoApiBundle\Controller\ApiDocsController;
use LiquidRazor\DtoApiBundle\Controller\OpenApiController;
use LiquidRazor\DtoApiBundle\EventSubscriber\ExceptionSubscriber;
use LiquidRazor\DtoApiBundle\EventSubscriber\RequestDtoSubscriber;
use LiquidRazor\DtoApiBundle\EventSubscriber\ResponseDtoSubscriber;
use LiquidRazor\DtoApiBundle\Lib\Mapper\DefaultDtoMapper;
use LiquidRazor\DtoApiBundle\Lib\Response\ResponseMappingResolver;
use LiquidRazor\DtoApiBundle\Lib\Streaming\NdjsonStreamer;
use LiquidRazor\DtoApiBundle\Lib\Streaming\SseStreamer;
use LiquidRazor\DtoApiBundle\OpenApi\OpenApiBuilder;
use LiquidRazor\DtoApiBundle\OpenApi\Schema\DtoSchemaFactory;
use LiquidRazor\DtoApiBundle\OpenApi\Schema\DtoSchemaRegistry;
use LiquidRazor\DtoApiBundle\OpenApi\TypeScript\TsTypeGenerator;
use LiquidRazor\DtoApiBundle\Profiler\DtoApiCollector;
use LiquidRazor\DtoApiBundle\Resolver\DtoApiRequestResolver;
use LiquidRazor\DtoApiBundle\Validation\Constraints\UniqueItemsValidator;
use LiquidRazor\DtoApiBundle\Validation\DtoApiConstraintLoader;
use LiquidRazor\DtoApiBundle\Validation\PropertyConstraintMapper;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $config): void {
    $services = $config->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    // Core utils
    $services->set(DefaultDtoMapper::class);

    $services->set(DtoApiConstraintLoader::class);

    $services->set(RequestDtoSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(ExceptionSubscriber::class)
        ->arg('$debug', '%kernel.debug%')
        ->tag('kernel.event_subscriber');

    $services->set(DtoApiRequestResolver::class)
        ->tag('controller.argument_value_resolver', ['priority' => 100]);

    $services->set(NdjsonStreamer::class)
        ->arg('$heartbeatSeconds', 15);

    $services->set(SseStreamer::class)
        ->arg('$heartbeatSeconds', 15);

    $services->set(ResponseDtoSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(PropertyConstraintMapper::class);

    $services->set(UniqueItemsValidator::class)
        ->tag('validator.constraint_validator', ['alias' => 'dtoapi.unique_items']);

    // Profiler data collector
    $services->set(DtoApiCollector::class)
        ->tag('data_collector', [
            'id' => 'dto_api',
            'template' => '@DtoApi/Collector/dto_api.html.twig',
            'priority' => 255,
        ]);

    $services->set(ApiDocsController::class)
        ->arg('$schemaRoute', 'dtoapi_openapi_json')      // if you changed the JSON route name, update here
        ->arg('$title', 'LiquidRazor DTO API Docs')
        ->tag('controller.service_arguments');

    $services->set(DtoSchemaFactory::class);
    $services->set(DtoSchemaRegistry::class);
    $services->set(ResponseMappingResolver::class)
        ->arg('$globalDefaults', '%liquidrazor_dto_api.default_responses%');

    $services->set(OpenApiBuilder::class)
        ->arg('$title', 'LiquidRazor DTO API')
        ->arg('$version', '0.1.0');

    $services->set(OpenApiController::class)
        ->tag('controller.service_arguments')
        ->public();

    // TypeScript codegen: pure generator + the console command that drives it.
    $services->set(TsTypeGenerator::class);

    $services->set(GenerateTsTypesCommand::class)
        ->tag('console.command');
};
