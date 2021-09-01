<?php
/**
 * Element API plugin for Craft CMS 3.x
 * Create a JSON API for your elements in Craft.
 *
 * @link https://pixelandtonic.com/
 * @copyright Copyright (c) 2017 Pixel &amp; Tonic
 */

namespace craft\elementapi\controllers;

use Craft;
use craft\elementapi\DataEvent;
use craft\elementapi\JsonFeedV1Serializer;
use craft\elementapi\Plugin;
use craft\helpers\ArrayHelper;
use craft\helpers\ConfigHelper;
use craft\web\Controller;
use League\Fractal\Manager;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\Serializer\DataArraySerializer;
use League\Fractal\Serializer\JsonApiSerializer;
use League\Fractal\Serializer\SerializerAbstract;
use ReflectionFunction;
use yii\base\InvalidConfigException;
use yii\web\HttpException;
use yii\web\JsonResponseFormatter;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Element API controller.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class DefaultController extends Controller
{
    /**
     * @event DataEvent The event that is triggered before sending the response data
     */
    const EVENT_BEFORE_SEND_DATA = 'beforeSendData';

    /**
     * @inheritdoc
     */
    protected $allowAnonymous = true;

    /**
     * Returns the requested elements as JSON.
     *
     * @param string $pattern The endpoint URL pattern that was matched
     * @return Response
     * @throws InvalidConfigException
     * @throws NotFoundHttpException
     */
    public function actionIndex(string $pattern): Response
    {
        $callback = null;
        $jsonOptions = null;
        $pretty = false;
        /** @var mixed $cache */
        $cache = true;
        $statusCode = 200;
        $statusText = null;

        try {
            $plugin = Plugin::getInstance();
            $config = $plugin->getEndpoint($pattern);
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;

            if (is_callable($config)) {
                /** @phpstan-ignore-next-line */
                $params = Craft::$app->getUrlManager()->getRouteParams();
                try {
                    $config = $this->_callWithParams($config, $params);
                } catch (InvalidConfigException $e) {
                    Craft::warning("Unable to resolve Element API route: {$e->getMessage()}", __METHOD__);
                    Craft::$app->getErrorHandler()->logException($e);
                    throw new NotFoundHttpException('Page not found', 0, $e);
                }
            }

            if (is_array($config)) {
                // Merge in the defaults
                $config = array_merge($plugin->getDefaultResourceAdapterConfig(), $config);
            }

            // Prevent API endpoints from getting indexed
            $this->response->getHeaders()->setDefault('X-Robots-Tag', 'none');

            // Before anything else, check the cache
            $cache = (
                ArrayHelper::remove($config, 'cache', true) &&
                !($this->request->getIsPreview() || $this->request->getIsLivePreview())
            );

            if ($cache) {
                $cacheKey = 'elementapi:' . $siteId . ':' . $this->request->getPathInfo() . ':' . $this->request->getQueryStringWithoutPath();
                $cacheService = Craft::$app->getCache();

                if (($cachedContent = $cacheService->get($cacheKey)) !== false) {
                    // Set the JSON headers
                    (new JsonResponseFormatter())->format($this->response);

                    // Set the cached JSON on the response and return
                    $this->response->format = Response::FORMAT_RAW;
                    $this->response->content = $cachedContent;
                    return $this->response;
                }

                $elementsService = Craft::$app->getElements();
                $elementsService->startCollectingCacheTags();
            }

            // Extract config settings that aren't meant for createResource()
            $serializer = ArrayHelper::remove($config, 'serializer');
            $callback = ArrayHelper::remove($config, 'callback');
            $jsonOptions = ArrayHelper::remove($config, 'jsonOptions', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $pretty = ArrayHelper::remove($config, 'pretty', false);
            $includes = ArrayHelper::remove($config, 'includes', []);
            $excludes = ArrayHelper::remove($config, 'excludes', []);

            // Generate all transforms immediately
            Craft::$app->getConfig()->getGeneral()->generateTransformsBeforePageLoad = true;

            // Get the data resource
            try {
                $resource = $plugin->createResource($config);
            } catch (\Throwable $e) {
                throw new NotFoundHttpException($e->getMessage() ?: Craft::t('element-api', 'Resource not found'), 0, $e);
            }

            // Load Fractal
            $fractal = new Manager();

            // Serialize the data
            if (!$serializer instanceof SerializerAbstract) {
                switch ($serializer) {
                    case 'dataArray':
                        $serializer = new DataArraySerializer();
                        break;
                    case 'jsonApi':
                        $serializer = new JsonApiSerializer();
                        break;
                    case 'jsonFeed':
                        $serializer = new JsonFeedV1Serializer();
                        break;
                    default:
                        $serializer = new ArraySerializer();
                }
            }

            $fractal->setSerializer($serializer);

            // Parse includes/excludes
            $fractal->parseIncludes($includes);
            $fractal->parseExcludes($excludes);

            $data = $fractal->createData($resource);

            // Fire a 'beforeSendData' event
            if ($this->hasEventHandlers(self::EVENT_BEFORE_SEND_DATA)) {
                $this->trigger(self::EVENT_BEFORE_SEND_DATA, new DataEvent([
                    'data' => $data,
                ]));
            }

            $data = $data->toArray();
        } catch (\Throwable $e) {
            $data = [
                'error' => [
                    'code' => $e instanceof HttpException ? $e->statusCode : $e->getCode(),
                    'message' => $e->getMessage(),
                ],
            ];
            $statusCode = $e instanceof HttpException ? $e->statusCode : 500;
            $statusText = $e->getMessage();

            // Log the exception
            Craft::error('Error resolving Element API endpoint: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
        }

        // Create a JSON response formatter with custom options
        $formatter = new JsonResponseFormatter([
            'useJsonp' => $callback !== null,
            'encodeOptions' => $jsonOptions,
            'prettyPrint' => $pretty,
        ]);

        // Manually format the response ahead of time, so we can access and cache the JSON
        if ($callback !== null) {
            $this->response->data = [
                'data' => $data,
                'callback' => $callback,
            ];
        } else {
            $this->response->data = $data;
        }

        $formatter->format($this->response);
        $this->response->data = null;
        $this->response->format = Response::FORMAT_RAW;

        // Cache it?
        if ($statusCode !== 200) {
            $cache = false;
        }
        if ($cache) {
            if ($cache !== true) {
                $expire = ConfigHelper::durationInSeconds($cache);
            } else {
                $expire = null;
            }

            /** @phpstan-ignore-next-line */
            $dep = $elementsService->stopCollectingCacheTags();
            $dep->tags[] = 'element-api';
            /** @phpstan-ignore-next-line */
            $cacheService->set($cacheKey, $this->response->content, $expire, $dep);
        }

        // Don't double-encode the data
        $this->response->format = Response::FORMAT_RAW;
        $this->response->setStatusCode($statusCode, $statusText);
        return $this->response;
    }

    /**
     * Calls a given function. If any params are given, they will be mapped to the function's arguments.
     *
     * @param callable $func The function to call
     * @param array $params Any params that should be mapped to function arguments
     * @return mixed The result of the function
     * @throws InvalidConfigException
     */
    private function _callWithParams($func, $params)
    {
        if (empty($params)) {
            return $func();
        }

        $ref = new ReflectionFunction($func);
        $args = [];

        foreach ($ref->getParameters() as $param) {
            $name = $param->getName();

            if (isset($params[$name])) {
                if ($param->isArray()) {
                    $args[] = is_array($params[$name]) ? $params[$name] : [$params[$name]];
                } else if (!is_array($params[$name])) {
                    $args[] = $params[$name];
                } else {
                    throw new InvalidConfigException("Unable to resolve $name param");
                }
            } else if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new InvalidConfigException("Unable to resolve $name param");
            }
        }

        return $ref->invokeArgs($args);
    }
}
