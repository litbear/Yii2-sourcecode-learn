<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\web;

use Yii;
use yii\base\InvalidRouteException;

/**
 * Application is the base class for all web application classes.
 *
 * @property string $homeUrl The homepage URL.
 * @property Session $session The session component. This property is read-only.
 * @property User $user The user component. This property is read-only.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Application extends \yii\base\Application
{
    /**
     * @var string the default route of this application. Defaults to 'site'.
     */
    public $defaultRoute = 'site';
    /**
     * @var array the configuration specifying a controller action which should handle
     * all user requests. This is mainly used when the application is in maintenance mode
     * and needs to handle all incoming requests via a single action.
     * The configuration is an array whose first element specifies the route of the action.
     * The rest of the array elements (key-value pairs) specify the parameters to be bound
     * to the action. For example,
     * 数组，由用于拦截所有用户请求的路由组成的配置数组。主要在维护模式中使用，会将所有的请求交给
     * 一个动作处理。
     * 配置数组的第一个元素是控制器方法的路由。其余元素是以键值对形式组成的将会绑定到动作上的参数。
     * 例如：
     *
     * ~~~
     * [
     *     'offline/notice',
     *     'param1' => 'value1',
     *     'param2' => 'value2',
     * ]
     * ~~~
     *
     * Defaults to null, meaning catch-all is not used.
     * 默认为空，意味着维护模式不启用。
     */
    public $catchAll;
    /**
     * @var Controller the currently active controller instance
     */
    public $controller;


    /**
     * @inheritdoc
     */
    protected function bootstrap()
    {
        $request = $this->getRequest();
        Yii::setAlias('@webroot', dirname($request->getScriptFile()));
        Yii::setAlias('@web', $request->getBaseUrl());

        parent::bootstrap();
    }

    /**
     * Handles the specified request.
     * 操作指定的请求
     * @param Request $request the request to be handled
     * @return Response the resulting response
     * @throws NotFoundHttpException if the requested route is invalid
     */
    public function handleRequest($request)
    {
        /**
         * $this->catchAll为空就从请求对象解析路由，否则从$this->catchAll
         * 处解析路由
         */
        if (empty($this->catchAll)) {
            list ($route, $params) = $request->resolve();
        } else {
            $route = $this->catchAll[0];
            $params = $this->catchAll;
            unset($params[0]);
        }
        try {
            Yii::trace("Route requested: '$route'", __METHOD__);
            $this->requestedRoute = $route;
            // runAction()的返回值类型为 Response 或 Response->data属性的值
            $result = $this->runAction($route, $params);
            if ($result instanceof Response) {
                return $result;
            } else {
                $response = $this->getResponse();
                if ($result !== null) {
                    $response->data = $result;
                }

                return $response;
            }
        } catch (InvalidRouteException $e) {
            throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'), $e->getCode(), $e);
        }
    }

    private $_homeUrl;

    /**
     * @return string the homepage URL
     */
    public function getHomeUrl()
    {
        if ($this->_homeUrl === null) {
            if ($this->getUrlManager()->showScriptName) {
                return $this->getRequest()->getScriptUrl();
            } else {
                return $this->getRequest()->getBaseUrl() . '/';
            }
        } else {
            return $this->_homeUrl;
        }
    }

    /**
     * @param string $value the homepage URL
     */
    public function setHomeUrl($value)
    {
        $this->_homeUrl = $value;
    }

    /**
     * Returns the session component.
     * @return Session the session component.
     */
    public function getSession()
    {
        return $this->get('session');
    }

    /**
     * Returns the user component.
     * @return User the user component.
     */
    public function getUser()
    {
        return $this->get('user');
    }

    /**
     * @inheritdoc
     * 返回核心应用组件的配置
     * 使用他们，就可以在应用中以\Yii::$app->log的形式获取log实例了
     */
    public function coreComponents()
    {
        return array_merge(parent::coreComponents(), [
            'request' => ['class' => 'yii\web\Request'],
            'response' => ['class' => 'yii\web\Response'],
            'session' => ['class' => 'yii\web\Session'],
            'user' => ['class' => 'yii\web\User'],
            'errorHandler' => ['class' => 'yii\web\ErrorHandler'],
        ]);
    }
}
