<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/10/24
 * Time: 下午2:25
 */

namespace Zor\Console;

use Lib\Component\Singleton;
use Lib\Socket\Bean\Caller;
use Lib\Socket\Bean\Response;

class CommandContainer
{
    use Singleton;

    private $container = [];

    public function set($key, CommandInterface $command)
    {
        $this->container[$key] = $command;
    }

    function get($key): ?CommandInterface
    {
        if (isset($this->container[$key])) {
            return $this->container[$key];
        } else {
            return null;
        }
    }

    /**
     * 获取当前已注册的全部命令
     * @return array
     * @author: eValor < master@evalor.cn >
     */
    function getCommandList()
    {
        return array_keys($this->container);
    }

    /**
     * 调度到某控制器方法执行操作
     * @param $actionName
     * @param Caller $caller
     * @param Response $response
     * @author: eValor < master@evalor.cn >
     */
    function hook($actionName, Caller $caller, Response $response)
    {
        $call = CommandContainer::getInstance()->get($actionName);
        if ($call instanceof CommandInterface) {
            $call->exec($caller, $response);
        } else {
            $response->setMessage("action {$actionName} miss");
        }
    }
}