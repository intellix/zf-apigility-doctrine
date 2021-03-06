<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2013 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\Apigility\Doctrine\Admin\Model;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Filter\FilterChain;
use Zend\View\Model\ViewModel;
use Zend\View\Renderer\PhpRenderer;
use Zend\View\Resolver;
use ZF\Apigility\Admin\Exception;
use ZF\Configuration\ConfigResource;
use ZF\Configuration\ModuleUtils;
use ZF\Rest\Exception\CreationException;
use Zf\Apigility\Admin\Model\ModuleEntity;
use Zend\ServiceManager\ServiceManager;
use Zend\ServiceManager\ServiceManagerAwareInterface;
use ZF\ApiProblem\ApiProblem;

class DoctrineRestServiceModel implements EventManagerAwareInterface, ServiceManagerAwareInterface
{
    /**
     * @var ConfigResource
     */
    protected $configResource;

    /**
     * @var EventManagerInterface
     */
    protected $events;

    /**
     * @var string
     */
    protected $module;

    /**
     * @var ModuleEntity
     */
    protected $moduleEntity;

    /**
     * @var string
     */
    protected $modulePath;

    /**
     * @var ModuleUtils
     */
    protected $modules;

    /**
     * @var PhpRenderer
     */
    protected $renderer;

    /**
     * Allowed REST update options that are scalars
     *
     * @var array
     */
    protected $restScalarUpdateOptions = array(
        'pageSize'                 => 'page_size',
        'pageSizeParam'            => 'page_size_param',
        'entityClass'              => 'entity_class',
        'collectionClass'          => 'collection_class',
    );

    /**
     * Allowed REST update options that are arrays
     *
     * @var array
     */
    protected $restArrayUpdateOptions = array(
        'collectionHttpMethods'    => 'collection_http_methods',
        'collectionQueryWhitelist' => 'collection_query_whitelist',
        'entityHttpMethods'      => 'entity_http_methods',
    );

    /**
     * @var FilterChain
     */
    protected $routeNameFilter;

    /**
     * @param ModuleEntity   $moduleEntity
     * @param ModuleUtils    $modules
     * @param ConfigResource $config
     */
    public function __construct(ModuleEntity $moduleEntity, ModuleUtils $modules, ConfigResource $config)
    {
        $this->module         = $moduleEntity->getName();
        $this->moduleEntity   = $moduleEntity;
        $this->modules        = $modules;
        $this->configResource = $config;
        $this->modulePath     = $modules->getModulePath($this->module);
    }

    /**
     * Determine if the given entity is doctrine-connected, and, if so, recast to a DoctrineRestServiceEntity
     *
     * @param  \Zend\EventManager\Event       $e
     * @return null|DoctrineRestServiceEntity
     */
    // @codeCoverageIgnoreStart

    public static function onFetch($e)
    {
        $entity = $e->getParam('entity', false);
        if (!$entity) {
            // No entity; nothing to do
            return;
        }

        $config = $e->getParam('config', array());
        if (!isset($config['zf-apigility'])
            || !isset($config['zf-apigility']['doctrine-connected'])
            || !isset($config['zf-apigility']['doctrine-connected'][$entity->resourceClass])
        ) {
            // No DB-connected configuration for this service; nothing to do
            return;
        }
        $config = $config['zf-apigility']['doctrine-connected'][$entity->resourceClass];

        $doctrineEntity = new DoctrineRestServiceEntity();
        $doctrineEntity->exchangeArray(array_merge($entity->getArrayCopy(), $config));

        return $doctrineEntity;
    }

    /**
     * Allow read-only access to properties
     *
     * @param  string               $name
     * @return mixed
     * @throws \OutOfRangeException
     */
    public function __get($name)
    {
        if (!isset($this->{$name})) {
            throw new \OutOfRangeException(sprintf(
                'Cannot locate property by name of "%s"',
                $name
            ));
        }

        return $this->{$name};
    }

    // @codeCoverageIgnoreEnd

    protected $serviceManager;

    public function setServiceManager(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;

        return $this;
    }

    public function getServiceManager()
    {
        return $this->serviceManager;
    }

    /**
     * Set the EventManager instance
     *
     * @param  EventManagerInterface $events
     * @return self
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_class($this),
        ));
        $this->events = $events;

        return $this;
    }

    /**
     * Retrieve the EventManager instance
     *
     * Lazy instantiates one if none currently registered
     *
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (!$this->events) {
            $this->setEventManager(new EventManager());
        }

        return $this->events;
    }

    /**
     * @param  string                  $controllerService
     * @return RestServiceEntity|false
     */
    public function fetch($controllerService)
    {
        $config = $this->configResource->fetch(true);

        if (!isset($config['zf-rest'])
            || !isset($config['zf-rest'][$controllerService])
        ) {
            // @codeCoverageIgnoreStart
            throw new Exception\RuntimeException(sprintf(
                'Could not find REST resource by name of %s',
                $controllerService
            ), 404);
            // @codeCoverageIgnoreEnd
        }

        $restConfig = $config['zf-rest'][$controllerService];

        $restConfig['controllerServiceName'] = $controllerService;
        $restConfig['module']                = $this->module;
        $restConfig['resource_class']        = $restConfig['listener'];
        unset($restConfig['listener']);

        $entity = new DoctrineRestServiceEntity();
        $entity->exchangeArray($restConfig);

        $this->getRouteInfo($entity, $config);
        $this->mergeContentNegotiationConfig($controllerService, $entity, $config);
        $this->mergeHalConfig($controllerService, $entity, $config);

        // Trigger an event, allowing a listener to alter the entity and/or
        // curry a new one.
        // @codeCoverageIgnoreStart
        $eventResults = $this->getEventManager()->trigger(__FUNCTION__, $this, array(
            'entity' => $entity,
            'config' => $config,
        ), function ($r) {
            return ($r instanceof DoctrineRestServiceEntity);
        });
        if ($eventResults->stopped()) {
            return $eventResults->last();
        }

        // @codeCoverageIgnoreEnd
        return $entity;
    }

    /**
     * Fetch all services
     *
     * @return RestServiceEntity[]
     */
    public function fetchAll($version = null)
    {
        $config = $this->configResource->fetch(true);
        if (!isset($config['zf-rest'])) {
            // @codeCoverageIgnoreStart
            return array();
            // @codeCoverageIgnoreEnd
        }

        $services = array();
        $pattern  = false;

        // Initialize pattern if a version was passed and it's valid
        if (null !== $version) {
            $version = (int) $version;
            if (!in_array($version, $this->moduleEntity->getVersions(), true)) {
                // @codeCoverageIgnoreStart
                throw new Exception\RuntimeException(sprintf(
                    'Invalid version "%s" provided',
                    $version
                ), 400);
                // @codeCoverageIgnoreEnd
            }
            $namespaceSep = preg_quote('\\');
            $pattern = sprintf(
                '#%s%sV%s#',
                $this->module,
                $namespaceSep,
                $version
            );
        }

        foreach (array_keys($config['zf-rest']) as $controllerService) {
        // @codeCoverageIgnoreStart
        // Because a verion is always supplied this check may not be necessary
            if (!$pattern) {
                $services[] = $this->fetch($controllerService);
                continue;
            }
        // @codeCoverageIgnoreEnd

            if (preg_match($pattern, $controllerService)) {
                $services[] = $this->fetch($controllerService);
                continue;
            }
        }

        return $services;
    }

    /**
     * Create a default hydrator name
     *
     * @param  string $resourceName
     * @return string
     */
    public function createHydratorName($resourceName)
    {
        return sprintf(
            '%s\\V%s\\Rest\\%s\\%sHydrator',
            $this->module,
            $this->moduleEntity->getLatestVersion(),
            $resourceName,
            $resourceName
        );
    }

    /**
     * Create a new service using the details provided
     *
     * @param  NewDoctrineServiceEntity $details
     * @return RestServiceEntity
     */
    public function createService(NewDoctrineServiceEntity $details)
    {
        $resourceName = ucfirst($details->serviceName);

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\\\[a-zA-Z][a-zA-Z0-9_]*)*$/', $resourceName)) {
            // @codeCoverageIgnoreStart
            throw new CreationException('Invalid resource name; must be a valid PHP namespace name.');
            // @codeCoverageIgnoreEnd
        }

        $entity       = new DoctrineRestServiceEntity();
        $entity->exchangeArray($details->getArrayCopy());

        $mediaType = $this->createMediaType();
        $resourceClass = ($details->resourceClass) ?: $this->createResourceClass($resourceName, $details);
        $collectionClass = ($details->collectionClass) ?: $this->createCollectionClass($resourceName);
        if (!$entityClass = $details->entityClass or !class_exists($details->entityClass)) {
            // @codeCoverageIgnoreStart
            throw new \Exception('entityClass is required and must exist');
            // @codeCoverageIgnoreEnd
        }
        $module = ($details->module) ?: $this->module;

        $controllerService = ($details->controllerServiceName) ?: $this->createControllerServiceName($resourceName);
        $routeName = ($details->routeName) ?: $this->createRoute($resourceName, $details->routeMatch, $details->routeIdentifierName, $controllerService);
        $hydratorName = ($details->hydratorName) ?: $this->createHydratorName($resourceName);
        $objectManager = ($details->objectManager) ?: 'doctrine.entitymanager.orm_default';

        $entity->exchangeArray(array(
            'collection_class'        => $collectionClass,
            'controller_service_name' => $controllerService,
            'entity_class'            => $entityClass,
            'hydrator_name'           => $hydratorName,
            'module'                  => $module,
            'resource_class'          => $resourceClass,
            'route_name'              => $routeName,
            'accept_whitelist'        => array(
                $mediaType,
                'application/hal+json',
                'application/json',
            ),
            'content_type_whitelist'  => array(
                $mediaType,
                'application/json',
            ),
            'object_manager' => $objectManager,
        ));

        $this->createRestConfig($entity, $controllerService, $resourceClass, $routeName);
        $this->createContentNegotiationConfig($entity, $controllerService);
        $this->createHalConfig($entity, $entityClass, $collectionClass, $routeName);
        $this->createDoctrineConfig($entity, $entityClass, $collectionClass, $routeName);

        $this->getEventManager()->trigger(
            __FUNCTION__,
            $this,
            array(
                'entity' => $entity,
                'configResource' => $this->configResource,
            )
        );

        return $entity;
    }

    /**
     * Update an existing service
     *
     * @param  DoctrineRestServiceEntity $update
     * @return DoctrineRestServiceEntity
     */
    public function updateService(DoctrineRestServiceEntity $update)
    {
        $controllerService = $update->controllerServiceName;

        try {
            $original = $this->fetch($controllerService);
        } catch (Exception\RuntimeException $e) {
            // @codeCoverageIgnoreStart
            throw new Exception\RuntimeException(sprintf(
                'Cannot update REST service "%s"; not found',
                $controllerService
            ), 404);
        }
            // @codeCoverageIgnoreEnd

        $this->updateRoute($original, $update);
        $this->updateRestConfig($original, $update);
        $this->updateContentNegotiationConfig($original, $update);

        return $this->fetch($controllerService);
    }

    /**
     * Delete a named service
     *
     * @todo   Remove content-negotiation and/or HAL configuration?
     * @param  string $controllerService
     * @return true
     */
    public function deleteService($controllerService, $deleteFiles = true)
    {
        try {
            $service = $this->fetch($controllerService);
        } catch (Exception\RuntimeException $e) {
            // @codeCoverageIgnoreStart
            throw new Exception\RuntimeException(sprintf(
                'Cannot delete REST service "%s"; not found',
                $controllerService
            ), 404);
            // @codeCoverageIgnoreEnd
        }

        if ($deleteFiles) {
            $this->deleteFiles($service);
        }
        $this->deleteRoute($service);
        $response = $this->deleteDoctrineRestConfig($service);

        if ($response instanceof ApiProblem) {
        // @codeCoverageIgnoreStart
            return $response;
        }

        return true;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Generate the controller service name from the module and resource name
     *
     * @param  string $module
     * @param  string $resourceName
     * @return string
     */
    public function createControllerServiceName($resourceName)
    {
        return sprintf(
            '%s\\V%s\\Rest\\%s\\Controller',
            $this->module,
            $this->moduleEntity->getLatestVersion(),
            $resourceName
        );
    }

    /**
     * Creates a new resource class based on the specified resource name
     *
     * @param  string $resourceName
     * @return string The name of the newly created class
     */
    public function createResourceClass($resourceName, NewDoctrineServiceEntity $details)
    {
        $module  = $this->module;
        $srcPath = $this->getSourcePath($resourceName);

        $className = sprintf('%sResource', $resourceName);
        $classPath = sprintf('%s/%s.php', $srcPath, $className);

        if (file_exists($classPath)) {
            // @codeCoverageIgnoreStart
            throw new Exception\RuntimeException(sprintf(
                'The resource "%s" already exists',
                $className
            ));
            // @codeCoverageIgnoreEnd
        }

        $view = new ViewModel(array(
            'module'    => $module,
            'resource'  => $resourceName,
            'classname' => $className,
            'details'   => $details,
            'version'   => $this->moduleEntity->getLatestVersion(),
        ));
        if (!$this->createClassFile($view, 'resource', $classPath)) {
            // @codeCoverageIgnoreStart
            throw new Exception\RuntimeException(sprintf(
                'Unable to create resource "%s"; unable to write file',
                $className
            ));
            // @codeCoverageIgnoreEnd
        }

        $fullClassName = sprintf(
            '%s\\V%s\\Rest\\%s\\%s',
            $module,
            $this->moduleEntity->getLatestVersion(),
            $resourceName,
            $className
        );

        return $fullClassName;
    }

    /**
     * Create a collection class for the resource
     *
     * @param  string $resourceName
     * @return string The name of the newly created collection class
     */
    public function createCollectionClass($resourceName)
    {
        $module     = $this->module;
        $srcPath    = $this->getSourcePath($resourceName);

        $className = sprintf('%sCollection', $resourceName);
        $classPath = sprintf('%s/%s.php', $srcPath, $className);

        if (file_exists($classPath)) {
            // @codeCoverageIgnoreStart
            throw new Exception\RuntimeException(sprintf(
                'The collection "%s" already exists',
                $className
            ));
            // @codeCoverageIgnoreEnd
        }

        $view = new ViewModel(array(
            'module'    => $module,
            'resource'  => $resourceName,
            'classname' => $className,
            'version'   => $this->moduleEntity->getLatestVersion(),
        ));
        if (!$this->createClassFile($view, 'collection', $classPath)) {
            // @codeCoverageIgnoreStart
            throw new Exception\RuntimeException(sprintf(
                'Unable to create entity "%s"; unable to write file',
                $className
            ));
            // @codeCoverageIgnoreEnd
        }

        $fullClassName = sprintf(
            '%s\\V%s\\Rest\\%s\\%s',
            $module,
            $this->moduleEntity->getLatestVersion(),
            $resourceName,
            $className
        );

        return $fullClassName;
    }

    /**
     * Create the route configuration
     *
     * @param  string $resourceName
     * @param  string $route
     * @param  string $identifier
     * @param  string $controllerService
     * @return string
     */
    public function createRoute($resourceName, $route, $identifier, $controllerService)
    {
        $filter    = $this->getRouteNameFilter();
        $routeName = sprintf(
            '%s.rest.doctrine.%s',
            $filter->filter($this->module),
            $filter->filter($resourceName)
        );

        $config = array(
            'router' => array(
                'routes' => array(
                    $routeName => array(
                        'type' => 'Segment',
                        'options' => array(
                            'route' => sprintf('%s[/:%s]', $route, $identifier),
                            'defaults' => array(
                                'controller' => $controllerService,
                            ),
                        ),
                    ),
                )
            ),
            'zf-versioning' => array(
                'uri' => array(
                    $routeName
                )
            )
        );
        $this->configResource->patch($config, true);

        return $routeName;
    }

    /**
     * Create the mediatype for this
     *
     * Based on the module and the latest module version.
     *
     * @return string
     */
    public function createMediaType()
    {
        $filter = $this->getRouteNameFilter();

        return sprintf(
            'application/vnd.%s.v%s+json',
            $filter->filter($this->module),
            $this->moduleEntity->getLatestVersion()
        );
    }

    /**
     * Creates REST configuration
     *
     * @param RestServiceEntity $details
     * @param string            $controllerService
     * @param string            $resourceClass
     * @param string            $routeName
     */
    public function createRestConfig(DoctrineRestServiceEntity $details, $controllerService, $resourceClass, $routeName)
    {
        $config = array('zf-rest' => array(
            $controllerService => array(
                'listener'                   => $resourceClass,
                'route_name'                 => $routeName,
                'route_identifier_name'      => $details->routeIdentifierName,
                'entity_identifier_name'     => $details->entityIdentifierName,
                'collection_name'            => $details->collectionName,
                'entity_http_methods'        => $details->entityHttpMethods,
                'collection_http_methods'    => $details->collectionHttpMethods,
                'collection_query_whitelist' => $details->collectionQueryWhitelist,
                'page_size'                  => $details->pageSize,
                'page_size_param'            => $details->pageSizeParam,
                'entity_class'               => $details->entityClass,
                'collection_class'           => $details->collectionClass,
            ),
        ));
        $this->configResource->patch($config, true);
    }

    /**
     * Create content negotiation configuration based on payload and discovered
     * controller service name
     *
     * @param RestServiceEntity $details
     * @param string            $controllerService
     */
    public function createContentNegotiationConfig(DoctrineRestServiceEntity $details, $controllerService)
    {
        $config = array(
            'controllers' => array(
                $controllerService => $details->selector,
            ),
        );
        $whitelist = $details->acceptWhitelist;
        if (!empty($whitelist)) {
            $config['accept-whitelist'] = array($controllerService => $whitelist);
        }
        $whitelist = $details->contentTypeWhitelist;
        if (!empty($whitelist)) {
            $config['content-type-whitelist'] = array($controllerService => $whitelist);
        }
        $config = array('zf-content-negotiation' => $config);
        $this->configResource->patch($config, true);
    }

    /**
     * Create Doctrine configuration
     *
     * @param RestServiceEntity $details
     * @param string            $entityClass
     * @param string            $collectionClass
     * @param string            $routeName
     */
    public function createDoctrineConfig(DoctrineRestServiceEntity $details, $entityClass, $collectionClass, $routeName)
    {
        $entityValue = $details->getArrayCopy();
        $objectManager = $this->getServiceManager()->get($details->objectManager);
        $hydratorStrategies = array();

        // Add all ORM collections to Hydrator Strategies
        if ($objectManager instanceof \Doctrine\ORM\EntityManager) {
            $collectionStrategyName = 'ZF\Apigility\Doctrine\Server\Hydrator\Strategy\CollectionLink';
            $metadataFactory = $objectManager->getMetadataFactory();
            $metadata = $metadataFactory->getMetadataFor($entityClass);

            foreach ($metadata->associationMappings as $relationName => $relationMapping) {
                switch ($relationMapping['type']) {
                    case 4:
                        $hydratorStrategies[$relationName] = $collectionStrategyName;
                        break;

                    // @codeCoverageIgnoreStart
                    default:
                        break;
                    // @codeCoverageIgnoreEnd

                }
            }
        }

        // The abstract_factories key is set to the value so these factories do not get duplicaed with each resource
        $config = array(
            'doctrine-hydrator' => array(
                $details->hydratorName => array(
                    'entity_class' => $entityClass,
                    'object_manager' => $details->objectManager,
                    'by_value' => $entityValue['hydrate_by_value'],
                    'strategies' => $hydratorStrategies,
                ),
            ),
            'zf-apigility' => array(
                'doctrine-connected' => array(
                    $details->resourceClass => array(
                        'object_manager' => $details->objectManager,
                        'hydrator' => $details->hydratorName,
                    ),
                ),
            ),
        );

        $this->configResource->patch($config, true);
    }

    /**
     * Create HAL configuration
     *
     * @param RestServiceEntity $details
     * @param string            $entityClass
     * @param string            $collectionClass
     * @param string            $routeName
     */
    public function createHalConfig(DoctrineRestServiceEntity $details, $entityClass, $collectionClass, $routeName)
    {
        $config = array('zf-hal' => array('metadata_map' => array(
            $entityClass => array(
                'route_identifier_name' => $details->routeIdentifierName,
                'entity_identifier_name' => $details->entityIdentifierName,
                'route_name'      => $routeName,
            ),
            $collectionClass => array(
                'entity_identifier_name' => $details->entityIdentifierName,
                'route_name'      => $routeName,
                'is_collection'   => true,
            ),
        )));
        if (isset($details->hydratorName)) {
            $config['zf-hal']['metadata_map'][$entityClass]['hydrator'] = $details->hydratorName;
        }
        $this->configResource->patch($config, true);
    }

    /**
     * Update the route for an existing service
     *
     * @param DoctrineRestServiceEntity $original
     * @param DoctrineRestServiceEntity $update
     */
    public function updateRoute(DoctrineRestServiceEntity $original, DoctrineRestServiceEntity $update)
    {
        $route = $update->routeMatch;
        if (!$route) {
            // @codeCoverageIgnoreStart
            return;
        }
            // @codeCoverageIgnoreEnd

        $routeName = $original->routeName;
        $config    = array('router' => array('routes' => array(
            $routeName => array('options' => array(
                'route' => $route,
            ))
        )));
        $this->configResource->patch($config, true);
    }

    /**
     * Update REST configuration
     *
     * @param DoctrineRestServiceEntity $original
     * @param DoctrineRestServiceEntity $update
     */
    public function updateRestConfig(DoctrineRestServiceEntity $original, DoctrineRestServiceEntity $update)
    {
        $patch = array();
        foreach ($this->restScalarUpdateOptions as $property => $configKey) {
            if (!$update->$property) {
                continue;
            }
            $patch[$configKey] = $update->$property;
        }

        if (empty($patch)) {
            // @codeCoverageIgnoreStart
            goto updateArrayOptions;
        }
            // @codeCoverageIgnoreEnd

        $config = array('zf-rest' => array(
            $original->controllerServiceName => $patch,
        ));
        $this->configResource->patch($config, true);

        updateArrayOptions:

        foreach ($this->restArrayUpdateOptions as $property => $configKey) {
            if (!$update->$property) {
                continue;
            }
            $key = sprintf('zf-rest.%s.%s', $original->controllerServiceName, $configKey);
            $this->configResource->patchKey($key, $update->$property);
        }
    }

    /**
     * Update the content negotiation configuration for the service
     *
     * @param DoctrineRestServiceEntity $original
     * @param DoctrineRestServiceEntity $update
     */
    public function updateContentNegotiationConfig(DoctrineRestServiceEntity $original, DoctrineRestServiceEntity $update)
    {
        $baseKey = 'zf-content-negotiation.';
        $service = $original->controllerServiceName;

        if ($update->selector) {
            $key = $baseKey . 'controllers.' . $service;
            $this->configResource->patchKey($key, $update->selector);
        }

        // Array dereferencing is a PITA
        $acceptWhitelist = $update->acceptWhitelist;
        if (is_array($acceptWhitelist)
            && !empty($acceptWhitelist)
        ) {
            $key = $baseKey . 'accept-whitelist.' . $service;
            $this->configResource->patchKey($key, $acceptWhitelist);
        }

        $contentTypeWhitelist = $update->contentTypeWhitelist;
        if (is_array($contentTypeWhitelist)
            && !empty($contentTypeWhitelist)
        ) {
            $key = $baseKey . 'content-type-whitelist.' . $service;
            $this->configResource->patchKey($key, $contentTypeWhitelist);
        }
    }

    /**
     * Delete the files which were automatically created
     *
     * @param DoctrineRestServiceEntity $entity
     */
    public function deleteFiles(DoctrineRestServiceEntity $entity)
    {
        $config = $this->configResource->fetch(true);

        $restResourceClass = $config['zf-rest'][$entity->controllerServiceName]['listener'];
        $restCollectionClass = $config['zf-rest'][$entity->controllerServiceName]['collection_class'];

        $reflector = new \ReflectionClass($restResourceClass);
        unlink($reflector->getFileName());

        $reflector = new \ReflectionClass($restCollectionClass);
        unlink($reflector->getFileName());
    }

    /**
     * Delete the route associated with the given service
     *
     * @param DoctrineRestServiceEntity $entity
     */
    public function deleteRoute(DoctrineRestServiceEntity $entity)
    {
        $config = $this->configResource->fetch(true);

        $route = $entity->routeName;
        $key   = array('router', 'routes', $route);
        $this->configResource->deleteKey($key);

        $uriKey = array_search($route, $config['zf-versioning']['uri']);
        if ($uriKey !== false) {
            $key = array('zf-versioning', 'uri', $uriKey);
            $this->configResource->deleteKey($key);
        }
    }

    /**
     * Delete the REST configuration associated with the given
     * service
     *
     * @param DoctrineRestServiceEntity $entity
     */
    public function deleteDoctrineRestConfig(DoctrineRestServiceEntity $entity)
    {
         // Get hydrator name
         $config = $this->configResource->fetch(true);
         $hydratorName = $config['zf-hal']['metadata_map'][$entity->entityClass]['hydrator'];
         $objectManagerClass = $config['doctrine-hydrator'][$hydratorName]['object_manager'];

         $key = array('doctrine-hydrator', $hydratorName);
         $this->configResource->deleteKey($key);

         $key = array('zf-apigility', 'doctrine-connected', $entity->resourceClass);
         $this->configResource->deleteKey($key);

        $key = array('zf-rest', $entity->controllerServiceName);
        $this->configResource->deleteKey($key);

        $key = array('zf-content-negotiation', 'controllers', $entity->controllerServiceName);
        $this->configResource->deleteKey($key);

        $key = array('zf-content-negotiation', 'accept-whitelist', $entity->controllerServiceName);
        $this->configResource->deleteKey($key);

        $key = array('zf-content-negotiation', 'content-type-whitelist', $entity->controllerServiceName);
        $this->configResource->deleteKey($key);

        $key = array('zf-hal', 'metadata_map', $entity->collectionClass);
        $this->configResource->deleteKey($key);

        $key = array('zf-hal', 'metadata_map', $entity->entityClass);
        $this->configResource->deleteKey($key);
    }

    /**
     * Create a class file
     *
     * Creates a class file based on the view model passed, the type of resource,
     * and writes it to the path provided.
     *
     * @param  ViewModel $model
     * @param  string    $type
     * @param  string    $classPath
     * @return bool
     */
    protected function createClassFile(ViewModel $model, $type, $classPath)
    {
        $renderer = $this->getRenderer();
        $template = $this->injectResolver($renderer, $type);
        $model->setTemplate($template);

        if (file_put_contents(
            $classPath,
            '<' . "?php\n" . $renderer->render($model)
        )) {
            // @codeCoverageIgnoreStart
            return true;
        }

        return false;
    }
        // @codeCoverageIgnoreEnd

    /**
     * Get a renderer instance
     *
     * @return PhpRenderer
     */
    protected function getRenderer()
    {
        if ($this->renderer instanceof PhpRenderer) {
            return $this->renderer;
        }

        $this->renderer = new PhpRenderer();

        return $this->renderer;
    }

    /**
     * Inject the renderer with a resolver
     *
     * Seed the resolver with a template name and path based on the $type passed, and inject it
     * into the renderer.
     *
     * @param  PhpRenderer $renderer
     * @param  string      $type
     * @return string      Template name
     */
    protected function injectResolver(PhpRenderer $renderer, $type)
    {
        $template = sprintf('doctrine/rest-', $type);
        $path     = sprintf('%s/../../../view/doctrine/rest-%s.phtml', __DIR__, $type);
        $resolver = new Resolver\TemplateMapResolver(array(
            $template => $path,
        ));
        $renderer->setResolver($resolver);

        return $template;
    }

    /**
     * Get the source path for the module
     *
     * @param  string $resourceName
     * @return string
     */
    protected function getSourcePath($resourceName)
    {
        $sourcePath = sprintf(
            '%s/src/%s/V%s/Rest/%s',
            $this->modulePath,
            str_replace('\\', '/', $this->module),
            $this->moduleEntity->getLatestVersion(),
            $resourceName
        );

        // @codeCoverageIgnoreStart
        if (!file_exists($sourcePath)) {
            mkdir($sourcePath, 0777, true);
        }
        // @codeCoverageIgnoreEnd
        return $sourcePath;
    }

    /**
     * Retrieve the filter chain for generating the route name
     *
     * @return FilterChain
     */
    protected function getRouteNameFilter()
    {
        if ($this->routeNameFilter instanceof FilterChain) {
            return $this->routeNameFilter;
        }

        $this->routeNameFilter = new FilterChain();
        $this->routeNameFilter->attachByName('Word\CamelCaseToDash')
            ->attachByName('StringToLower');

        return $this->routeNameFilter;
    }

    /**
     * Retrieve route information for a given service based on the configuration available
     *
     * @param DoctrineRestServiceEntity $metadata
     * @param array                     $config
     */
    protected function getRouteInfo(DoctrineRestServiceEntity $metadata, array $config)
    {
        $routeName = $metadata->routeName;
        if (!$routeName
            || !isset($config['router'])
            || !isset($config['router']['routes'])
            || !isset($config['router']['routes'][$routeName])
            || !isset($config['router']['routes'][$routeName]['options'])
            || !isset($config['router']['routes'][$routeName]['options']['route'])
        ) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
        $metadata->exchangeArray(array(
            'route_match' => $config['router']['routes'][$routeName]['options']['route'],
        ));
    }

    /**
     * Merge the content negotiation configuration for the given controller
     * service into the REST metadata
     *
     * @param string                    $controllerServiceName
     * @param DoctrineRestServiceEntity $metadata
     * @param array                     $config
     */
    protected function mergeContentNegotiationConfig($controllerServiceName, DoctrineRestServiceEntity $metadata, array $config)
    {
        // @codeCoverageIgnoreStart
        if (!isset($config['zf-content-negotiation'])) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $config = $config['zf-content-negotiation'];

        if (isset($config['controllers'])
            && isset($config['controllers'][$controllerServiceName])
        ) {
            $metadata->exchangeArray(array(
                'selector' => $config['controllers'][$controllerServiceName],
            ));
        }

        if (isset($config['accept-whitelist'])
            && isset($config['accept-whitelist'][$controllerServiceName])
        ) {
            $metadata->exchangeArray(array(
                'accept_whitelist' => $config['accept-whitelist'][$controllerServiceName],
            ));
        }

        if (isset($config['content-type-whitelist'])
            && isset($config['content-type-whitelist'][$controllerServiceName])
        ) {
            $metadata->exchangeArray(array(
                'content-type-whitelist' => $config['content-type-whitelist'][$controllerServiceName],
            ));
        }
    }

    /**
     * Merge entity and collection class into metadata, if found
     *
     * @param string                    $controllerServiceName
     * @param DoctrineRestServiceEntity $metadata
     * @param array                     $config
     */
    protected function mergeHalConfig($controllerServiceName, DoctrineRestServiceEntity $metadata, array $config)
    {
        if (!isset($config['zf-hal'])
            || !isset($config['zf-hal']['metadata_map'])
        ) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $config = $config['zf-hal']['metadata_map'];

        $entityClass     = $this->deriveEntityClass($controllerServiceName, $metadata, $config);
        $collectionClass = $this->deriveCollectionClass($controllerServiceName, $metadata, $config);
        $merge           = array();

        // @codeCoverageIgnoreStart
        if (isset($config[$entityClass])) {
            $merge['entity_class'] = $entityClass;
        }

        if (isset($config[$collectionClass])) {
            $merge['collection_class'] = $collectionClass;
        }
        // @codeCoverageIgnoreEnd

        $metadata->exchangeArray($merge);
    }

    /**
     * Derive the name of the entity class from the controller service name
     *
     * @param  string                    $controllerServiceName
     * @param  DoctrineRestServiceEntity $metadata
     * @param  array                     $config
     * @return string
     */
    protected function deriveEntityClass($controllerServiceName, DoctrineRestServiceEntity $metadata, array $config)
    {
        if (isset($config['zf-rest'])
            && isset($config['zf-rest'][$controllerServiceName])
            && isset($config['zf-rest'][$controllerServiceName]['entity_class'])
        ) {
            // @codeCoverageIgnoreStart
            return $config['zf-rest'][$controllerServiceName]['entity_class'];
            // @codeCoverageIgnoreEnd
        }

        $module = ($metadata->module == $this->module) ? $this->module : $metadata->module;
        if (!preg_match('#' . preg_quote($module . '\\Rest\\') . '(?P<service>[^\\\\]+)' . preg_quote('\\Controller') . '#', $controllerServiceName, $matches)) {
            return null;
        }

        // @codeCoverageIgnoreStart
        return sprintf('%s\\Rest\\%s\\%sEntity', $module, $matches['service'], $matches['service']);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Derive the name of the collection class from the controller service name
     *
     * @param  string                    $controllerServiceName
     * @param  DoctrineRestServiceEntity $metadata
     * @param  array                     $config
     * @return string
     */
    protected function deriveCollectionClass($controllerServiceName, DoctrineRestServiceEntity $metadata, array $config)
    {
        if (isset($config['zf-rest'])
            && isset($config['zf-rest'][$controllerServiceName])
            && isset($config['zf-rest'][$controllerServiceName]['collection_class'])
        ) {
            // @codeCoverageIgnoreStart
            return $config['zf-rest'][$controllerServiceName]['collection_class'];
            // @codeCoverageIgnoreEnd
        }

        $module = ($metadata->module == $this->module) ? $this->module : $metadata->module;
        if (!preg_match('#' . preg_quote($module . '\\Rest\\') . '(?P<service>[^\\\\]+)' . preg_quote('\\Controller') . '#', $controllerServiceName, $matches)) {
            return null;
        }

        // @codeCoverageIgnoreStart
        return sprintf('%s\\Rest\\%s\\%sCollection', $module, $matches['service'], $matches['service']);
        // @codeCoverageIgnoreEnd
    }
}
