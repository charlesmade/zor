<?php
/**
 * Created by PhpStorm.
 * User: cozz
 * Date: 2018/12/11
 * Time: 18:45
 */

namespace Lib\Data;

use Lib\Data\Pool\MysqlPool;
use Lib\Component\Pool\PoolManager;
use Lib\Data\Source\MysqlSource;
use Zor\Config;

class Mysql
{
    private $db;
    public $tableName;

    function __construct()
    {
        $db = $this->getDbSource();
        if ($db instanceof MysqlSource) {
            $this->db = $db;
            //指定表名
            if (is_null($this->tableName)) {
                $this->tableName = $this->getTableName(get_class($this));
            }
            $this->db->setTableName($this->tableName);
        } else {
            throw new \Exception('mysql pool is empty');
        }
    }

    //获取绑定了模型的对象
    function getDb():?MysqlSource
    {
        return $this->db;
    }

    private function getDbSource():?MysqlSource
    {
        return PoolManager::getInstance()->getPool(MysqlPool::class)->getObj($GLOBALS['conf']->getConf('MYSQL.POOL_TIME_OUT'));
    }

    //获取表名
    private function getTableName($class):?string
    {
        $class = explode('\\', $class);
        $class = $class[count($class) - 1];
        if (substr($class, -5) === 'Model' && strlen($class) > 5) {
            $table = '';
            $string_arr = str_split(lcfirst(strstr($class, 'Model', true)),1);
            foreach ($string_arr as $str) {
                $str_ascii = ord($str);
                if ($str_ascii < 91 && $str_ascii > 64) {
                    $table .= '_'.strtolower($str);
                } else {
                    $table .= $str;
                }
            }
            return $table;
        } else {
            throw new \Exception("$class is not a Model.");
        }
    }


    //销毁变量时 销毁调用的资源
    function __destruct()
    {
        // TODO: Implement __destruct() method.
        $this->db->objectRestore();
        PoolManager::getInstance()->getPool(MysqlPool::class)->recycleObj($this->db);
    }
}