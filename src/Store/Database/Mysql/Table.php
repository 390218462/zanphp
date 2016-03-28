<?php
/**
 * Created by PhpStorm.
 * User: xiaoniu
 * Date: 16/3/25
 * Time: 下午5:47
 */
namespace Zan\Framework\Store\Database\Mysql;
use Zan\Framework\Foundation\Core\Path;
use Zan\Framework\Store\Database\Mysql\Exception as MysqlException;
use Zan\Framework\Utilities\DesignPattern\Singleton;

class Table
{
    use Singleton;
    private $tables = [];

    private function __construct()
    {
        $this->init();
    }

    public function getDatabase($table)
    {
        if (!isset($this->tables[$table])) {
            throw new MysqlException('无法获取数'.$table.'表所在的数据库配置');
        }
        return $this->tables[$table];
    }

    private function init()
    {
        if ([] == $this->tables) {
            $this->tables = $this->parseFile();
        }
        return;
    }

    private function parseFile()
    {
        $tables = [];
        $config = $this->loadFile();
        if (null == $config || [] == $config) {
            return [];
        }
        foreach ($config as $db => $list) {
            foreach ($list as $table) {
                $tables[$table] = $db;
            }
        }
        return $tables;
    }

    private function loadFile()
    {
        return require Path::getSqlPath() . 'table.php';
    }
}