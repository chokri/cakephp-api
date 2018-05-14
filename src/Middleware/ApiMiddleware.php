<?php
/**
 * Copyright 2016 - 2017, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2016 - 2017, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace CakeDC\Api\Middleware;

use CakeDC\Api\Service\ConfigReader;
use CakeDC\Api\Service\ServiceRegistry;
use Cake\Core\Configure;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Applies routing rules to the request and creates the controller
 * instance if possible.
 */
class ApiMiddleware
{

    /**
     * Api configuration. If the key has a `.` it will be treated as a plugin prefix.
     *
     * @var string
     */
    protected $configuration = '';

    protected $space = '';
    
    protected $prefix = 'api';

    /**
     * Constructor
     *
     * @param string|null $configuration Configuration.
     * @throws \InvalidArgumentException When invalid subject has been passed.
     */
    public function __construct($prefix, $space = null)
    {
        if ($space == null) {
            $this->space == '';
        } else {
            $this->space = $space;
        }
        $this->prefix = $prefix;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Message\ResponseInterface $response The response.
     * @param callable $next The next middleware to call.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, $next)
    {
        $existsApiConfig = Configure::read('Api');
        Configure::write('Api', []);
        if ($this->space == '') {
            $config = 'api';
        } else {
            $config = $this->space . '.api';
        }
        
        Configure::load($config);

        $prefix = Configure::read('Api.prefix');
        if (empty($prefix)) {
            $prefix = $this->prefix;
        }
        
        $useVersioning = Configure::read('Api.useVersioning');
        if ($useVersioning) {
            $versionPrefix = Configure::read('Api.versionPrefix');
            $expr = '#/' . $prefix . '/(?<version>' . $versionPrefix . '\d+)' . '/' . '(?<service>[^/?]+)' . '(?<base>/?.*)#';
        } else {
            $expr = '#/' . $prefix . '/' . '(?<service>[^/?]+)' . '(?<base>/?.*)#';
        }

        $path = $request->getUri()->getPath();
        if (preg_match($expr, $path, $matches)) {
            $version = isset($matches['version']) ? $matches['version'] : null;
            $serviceClass = $service = $matches['service'];
            if ($this->space !== '') {
                $serviceClass = $this->space . '.' . $service;
            }

            $url = '/' . $service;
            if (!empty($matches['base'])) {
                $url .= $matches['base'];
            }
            $options = [
                'service' => $service,
                'classPrefix' => $this->space,
                'version' => $version,
                'request' => $request,
                'response' => $response,
                'baseUrl' => $url,
            ];

            try {
                $options += (new ConfigReader())->serviceOptions($service, $version);
                $Service = ServiceRegistry::get($service, $options);
                $result = $Service->dispatch();

                $response = $Service->respond($result);
            } catch (Exception $e) {
                $response->withStatus(400);
                $response = $response->withStringBody($e->getMessage());
            }

            Configure::write('Api', $existsApiConfig);
            return $response;
        }

        return $next($request, $response);
    }
}
