<?php

// +----------------------------------------------------------------------
// | Library for ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2019 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: http://demo.thinkadmin.top
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | gitee 仓库地址 ：https://gitee.com/zoujingli/ThinkLibrary
// | github 仓库地址 ：https://github.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------

namespace think\admin\extend;

/**
 * 应用节点扫描器扩展
 * Class NodeExtend
 * @package think\admin\extend
 */
class NodeExtend
{

    /**
     * 控制器方法扫描处理
     * @return array
     * @throws \ReflectionException
     */
    public static function getMethods()
    {
        static $data = [];
        if (count($data) > 0) return $data;
        $data = app()->cache->get('system_auth_node', []);
        if (count($data) > 0) return $data;
        $ignores = get_class_methods('\think\admin\Controller');
        foreach (self::scanDirectory(app()->getAppPath()) as $file) {
            if (stripos($file, '/controller/') === false) continue;
            if (preg_match('|namespace\s+(.*?);.*?\s+class\s+(.*?)\s+|xi', strtr(file_get_contents($file), "\n", ' '), $mchs)) {
                $refection = new \ReflectionClass("{$mchs[1]}\\{$mchs[2]}");
                foreach ($refection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (in_array($method->getName(), $ignores)) continue;
                    list($prefix, $suffix) = explode('\\controller\\', $refection->getName());
                    $space = strtr("{$prefix}/" . self::classTolower($suffix) . "/{$method->getName()}", '\\', '/');
                    $comment = strtr($method->getDocComment(), "\n", ' ');
                    $data[substr($space, stripos($space, '/') + 1)][$method->getName()] = [
                        'title'  => preg_replace('/^\/\*\s*\*\s*\*\s*(.*?)\s*\*.*?$/', '$1', $comment) ?: $method->getName(),
                        'isauth' => intval(preg_match('/@auth\s*true/i', $comment)),
                        'ismenu' => intval(preg_match('/@menu\s*true/i', $comment)),
                    ];
                }
            }
        }
        app()->cache->set('system_auth_node', $data);
        return $data;
    }

    /**
     * 获取当前控制器
     * @return string
     * @todo
     */
    public static function current()
    {
        return app()->getNamespace() . '\\' . self::classTolower(app()->request->controller()) . '\\' . app()->request->action();
    }

    /**
     * 驼峰转下划线规则
     * @param string $name
     * @return string
     */
    public static function classTolower($name)
    {
        $dots = [];
        foreach (explode('\\', $name) as $dot) {
            $dots[] = trim(preg_replace("/[A-Z]/", "_\\0", $dot), "_");
        }
        return strtolower(join('.', $dots));
    }

    /**
     * 获取所有PHP文件列表
     * @param string $path 扫描目录
     * @param array $data 额外数据
     * @param string $ext 有文件后缀
     * @return array
     */
    private static function scanDirectory($path, $data = [], $ext = 'php')
    {
        foreach (glob("{$path}*") as $item) {
            if (is_dir($item)) {
                $data = array_merge($data, self::scanDirectory("{$item}/"));
            } elseif (is_file($item) && pathinfo($item, PATHINFO_EXTENSION) === $ext) {
                $data[] = strtr($item, '\\', '/');
            }
        }
        return $data;
    }

}