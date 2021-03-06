<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/28
 * Time: 下午6:07
 */

namespace Zor;

use Lib\Component\Di;
use Lib\Component\Singleton;
use Zor\AbstractInterface\Event;
use Zor\Console\TcpService;
use Zor\Crontab\Crontab;
use Zor\FastCache\Cache;
use Zor\Swoole\EventHelper;
use Zor\Swoole\EventRegister;
use Zor\Swoole\Task\QuickTaskInterface;
use Lib\Http\Dispatcher;
use Lib\Http\Message\Status;
use Lib\Http\Request;
use Lib\Http\Response;
use Lib\Trace\Bean\Location;
use Zor\Swoole\PipeMessage\Message;
use Zor\Swoole\PipeMessage\OnCommand;
use Zor\Swoole\Task\AbstractAsyncTask;
use Zor\Swoole\Task\SuperClosure;
use Lib\Data\Pool\MysqlPool;
use Lib\Data\Pool\RedisPool;
use Lib\Component\Pool\PoolManager;

class Core
{
    use Singleton;

    private $isDev = true;

    function __construct()
    {
        defined('SWOOLE_VERSION') or define('SWOOLE_VERSION',intval(phpversion('swoole')));
        defined('ROOT_PATH') or define('ROOT_PATH',realpath(getcwd()));
    }

    function initialize()
    {
        //检查全局文件是否存在.
        $file = ROOT_PATH . '/EventFire.php';
        if(file_exists($file)){
            require_once $file;
            try{
                $ref = new \ReflectionClass('Zor\EventFire');
                if(!$ref->implementsInterface(Event::class)){
                    die('global file for Event is not compatible for Zor\EventFire');
                }
                unset($ref);
            }catch (\Throwable $throwable){
                die($throwable->getMessage());
            }
        }else{
            die('global event file missing');
        }
        //执行框架初始化事件
//        EventFire::initialize();
        date_default_timezone_set('Asia/Shanghai');
        $this->initDataService();
        //临时文件和Log目录初始化
        $this->sysDirectoryInit();
        //注册错误回调
        $this->registerErrorHandler();
        return $this;
    }

    private function initDataService()
    {
        // 注册redis连接池
        $redisPoolNum = $GLOBALS['conf']->getConf('REDIS.POOL_MAX_NUM');
        if ($redisPoolNum)
            PoolManager::getInstance()->register(RedisPool::class, $redisPoolNum);

        // 注册mysql数据库连接池
        $mysqlPoolNum = $GLOBALS['conf']->getConf('MYSQL.POOL_MAX_NUM');
        if ($mysqlPoolNum)
            PoolManager::getInstance()->register(MysqlPool::class, $mysqlPoolNum);
    }

    function createServer()
    {
        $conf = $GLOBALS['conf']->getConf('MAIN_SERVER');
        ServerManager::getInstance()->createSwooleServer(
            $conf['PORT'],$conf['SERVER_TYPE'],$conf['LISTEN_ADDRESS'],$conf['SETTING'],$conf['RUN_MODEL'],$conf['SOCK_TYPE']
        );
        $this->registerDefaultCallBack(ServerManager::getInstance()->getSwooleServer(),$conf['SERVER_TYPE']);
//        EventFire::mainServerCreate(ServerManager::getInstance()->getMainEventRegister());
        //创建主服务后，创建Tcp子服务
//        (new TcpService($GLOBALS['conf']->getConf('CONSOLE')));
        return $this;
    }

    function start()
    {
        //给主进程也命名
        if(PHP_OS != 'Darwin'){
            $name = $GLOBALS['conf']->getConf('SERVER_NAME');
            cli_set_process_title($name);
        }
        //注册fastCache进程
//        Cache::getInstance()->__run();
        ServerManager::getInstance()->start();
    }

    private function sysDirectoryInit():void
    {
        //创建临时目录    请以绝对路径，不然守护模式运行会有问题
        $tempDir = $GLOBALS['conf']->getConf('TEMP_DIR');
        if(empty($tempDir)){
            $tempDir = ROOT_PATH.'/temp';
            $GLOBALS['conf']->setConf('TEMP_DIR',$tempDir);
        }
        if(!is_dir($tempDir)){
            mkdir($tempDir);
        }
        defined('TEMP_DIR') or define('TEMP_DIR',$tempDir);

        $logDir = $GLOBALS['conf']->getConf('LOG_DIR');
        if(empty($logDir)){
            $logDir = ROOT_PATH.'/log';
            $GLOBALS['conf']->setConf('LOG_DIR',$logDir);
        }
        if(!is_dir($logDir)){
            mkdir($logDir);
        }
        defined('LOG_DIR') or define('LOG_DIR',$logDir);

        //设置默认文件目录值
        $GLOBALS['conf']->setConf('MAIN_SERVER.SETTING.pid_file',$tempDir.'/pid.pid');
        $GLOBALS['conf']->setConf('MAIN_SERVER.SETTING.log_file',$logDir.'/swoole.log');
        //设置目录
        Logger::getInstance($logDir);
    }

    private function registerErrorHandler()
    {
        ini_set("display_errors", "On");
        error_reporting(E_ALL | E_STRICT);
        $userHandler = Di::getInstance()->get(SysConst::ERROR_HANDLER);
        if(!is_callable($userHandler)){
            $userHandler = function($errorCode, $description, $file = null, $line = null){
                $l = new Location();
                $l->setFile($file);
                $l->setLine($line);
                Trigger::getInstance()->error($description,$l);
            };
        }
        set_error_handler($userHandler);

        $func = Di::getInstance()->get(SysConst::SHUTDOWN_FUNCTION);
        if(!is_callable($func)){
            $func = function (){
                $error = error_get_last();
                if(!empty($error)){
                    $l = new Location();
                    $l->setFile($error['file']);
                    $l->setLine($error['line']);
                    Trigger::getInstance()->error($error['message'],$l);
                }
            };
        }
        register_shutdown_function($func);
    }

    private function registerDefaultCallBack(\swoole_server $server,string $serverType)
    {
        //如果主服务仅仅是swoole server，那么设置默认onReceive为全局的onReceive
//        if($serverType === ServerManager::TYPE_SERVER){
//            $server->on(EventRegister::onReceive,function (\swoole_server $server, int $fd, int $reactor_id, string $data){
//                EventFire::onReceive($server,$fd,$reactor_id,$data);
//            });
//        }else{
            $module = $GLOBALS['conf']->getConf('MODULE_DIR') ?: 'App';
            $controller = Di::getInstance()->get(SysConst::HTTP_CONTROLLER_NAMESPACE) ?: 'Controller';
            $namespace = $module . '\\' . $controller . '\\';
            defined('MODULE_DIR') or define('MODULE_DIR',$module);
            $depth = intval($GLOBALS['conf']->getConf('HTTP_CONTROLLER_MAX_DEPTH'));
            $depth = $depth > 5 ? $depth : 5;
            $max = intval($GLOBALS['conf']->getConf('HTTP_CONTROLLER_POOL_MAX_NUM')) ?: 15;
            $waitTime = intval($GLOBALS['conf']->getConf('HTTP_CONTROLLER_POOL_WAIT_TIME')) ?: 5;

            $dispatcher = new Dispatcher($namespace,$depth,$max);
            $dispatcher->setControllerPoolWaitTime($waitTime);
            $httpExceptionHandler = Di::getInstance()->get(SysConst::HTTP_EXCEPTION_HANDLER);
            if(!is_callable($httpExceptionHandler)){
                $httpExceptionHandler = function ($throwable,$request,$response){
                    $response->withStatus(Status::CODE_INTERNAL_SERVER_ERROR);
                    $response->write(nl2br($throwable->getMessage()."\n".$throwable->getTraceAsString()));
                    Trigger::getInstance()->throwable($throwable);
                };
                Di::getInstance()->set(SysConst::HTTP_EXCEPTION_HANDLER,$httpExceptionHandler);
            }
            $dispatcher->setHttpExceptionHandler($httpExceptionHandler);

            EventHelper::on($server,EventRegister::onRequest,function (\swoole_http_request $request,\swoole_http_response $response)use($dispatcher){
                $request_psr = new Request($request);
                $response_psr = new Response($response);
                try{
                    if(EventFire::onRequest($request_psr,$response_psr)){
                        $dispatcher->dispatch($request_psr,$response_psr);
                    }
                }catch (\Throwable $throwable){
                    call_user_func(Di::getInstance()->get(SysConst::HTTP_EXCEPTION_HANDLER),$throwable,$request_psr,$response_psr);
                }finally{
                    try{
                        EventFire::afterRequest($request_psr,$response_psr);
                    }catch (\Throwable $throwable){
                        call_user_func(Di::getInstance()->get(SysConst::HTTP_EXCEPTION_HANDLER),$throwable,$request_psr,$response_psr);
                    }
                }
                $response_psr->__response();
            });
//        }
        //注册默认的on task,finish  不经过 event register。因为on task需要返回值。不建议重写onTask,否则es自带的异步任务事件失效
        EventHelper::on($server,EventRegister::onTask,function (\swoole_server $server, $taskId, $fromWorkerId,$taskObj){
            if(is_string($taskObj) && class_exists($taskObj)){
                $ref = new \ReflectionClass($taskObj);
                if($ref->implementsInterface(QuickTaskInterface::class)){
                    try{
                        $taskObj::run($server,$taskId,$fromWorkerId);
                    }catch (\Throwable $throwable){
                        Trigger::getInstance()->throwable($throwable);
                    }
                    return;
                }else if($ref->isSubclassOf(AbstractAsyncTask::class)){
                    $taskObj = new $taskObj;
                }
            }
            if($taskObj instanceof AbstractAsyncTask){
                try{
                    $ret =  $taskObj->run($taskObj->getData(),$taskId,$fromWorkerId);
                    //在有return或者设置了结果的时候  说明需要执行结束回调
                    $ret = is_null($ret) ? $taskObj->getResult() : $ret;
                    if(!is_null($ret)){
                        $taskObj->setResult($ret);
                        return $taskObj;
                    }
                }catch (\Throwable $throwable){
                    $taskObj->onException($throwable);
                }
            }else if($taskObj instanceof SuperClosure){
                try{
                    return $taskObj( $server, $taskId, $fromWorkerId);
                }catch (\Throwable $throwable){
                    Trigger::getInstance()->throwable($throwable);
                }
            }else if(is_callable($taskObj)){
                try{
                    call_user_func($taskObj,$server,$taskId,$fromWorkerId);
                }catch (\Throwable $throwable){
                    Trigger::getInstance()->throwable($throwable);
                }
            }
            return null;
        });
        EventHelper::on($server,EventRegister::onFinish,function (\swoole_server $server, $taskId, $taskObj){
            //finish 在仅仅对AbstractAsyncTask做处理，其余处理无意义。
            if($taskObj instanceof AbstractAsyncTask){
                try{
                    $taskObj->finish($taskObj->getResult(),$taskId);
                }catch (\Throwable $throwable){
                    $taskObj->onException($throwable);
                }
            }
        });

        //注册默认的pipe通讯
        //通过pipe通讯，也就是processAsync投递的闭包任务，是没有taskId信息的，因此参数传递默认-1
        OnCommand::getInstance()->set('TASK',function (\swoole_server $server,$taskObj,$fromWorkerId){
            if(is_string($taskObj) && class_exists($taskObj)){
                $ref = new \ReflectionClass($taskObj);
                if($ref->implementsInterface(QuickTaskInterface::class)){
                    try{
                        $taskObj::run($server,-1,$fromWorkerId);
                    }catch (\Throwable $throwable){
                        Trigger::getInstance()->throwable($throwable);
                    }
                    return;
                }else if($ref->isSubclassOf(AbstractAsyncTask::class)){
                    $taskObj = new $taskObj;
                }
            }
            if($taskObj instanceof AbstractAsyncTask){
                try{
                    $ret =  $taskObj->run($taskObj->getData(),-1,$fromWorkerId);
                    //在有return或者设置了结果的时候  说明需要执行结束回调
                    $ret = is_null($ret) ? $taskObj->getResult() : $ret;
                    if(!is_null($ret)){
                        $taskObj->setResult($ret);
                        return $taskObj;
                    }
                }catch (\Throwable $throwable){
                    $taskObj->onException($throwable);
                }
            }else if($taskObj instanceof SuperClosure){
                try{
                    return $taskObj( $server, -1, $fromWorkerId);
                }catch (\Throwable $throwable){
                    Trigger::getInstance()->throwable($throwable);
                }
            }else if(is_callable($taskObj)){
                try{
                    call_user_func($taskObj,$server,-1,$fromWorkerId);
                }catch (\Throwable $throwable){
                    Trigger::getInstance()->throwable($throwable);
                }
            }
        });

        EventHelper::on($server,EventRegister::onPipeMessage,function (\swoole_server $server,$fromWorkerId,$data){
            $message = unserialize($data);
            if($message instanceof Message){
                OnCommand::getInstance()->hook($message->getCommand(),$server,$message->getData(),$fromWorkerId);
            }else{
                Trigger::getInstance()->error("data :{$data} not packet as an Message Instance");
            }
        });

        //注册默认的worker start
        EventHelper::registerWithAdd(ServerManager::getInstance()->getMainEventRegister(),EventRegister::onWorkerStart,function (\swoole_server $server,$workerId){
            if(PHP_OS != 'Darwin'){
                $name = $GLOBALS['conf']->getConf('SERVER_NAME');
                if(($workerId < $GLOBALS['conf']->getConf('MAIN_SERVER.SETTING.worker_num')) && $workerId >= 0){
                    $type = 'Worker';
                }else{
                    $type = 'TaskWorker';
                }
                cli_set_process_title("{$name}.{$type}.{$workerId}");
            }
        });
    }
}