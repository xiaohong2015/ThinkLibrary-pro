<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2020 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: https://gitee.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | gitee 代码仓库：https://gitee.com/zoujingli/ThinkLibrary
// | github 代码仓库：https://github.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------

namespace think\admin\service;

use think\admin\Service;
use ZipArchive;

/**
 * 应用模块安装服务
 * Class ModuleService
 * @package think\admin\service
 */
class ModuleService extends Service
{

    /**
     * 安装应用模块
     * @param string $name 模块名称
     * @param ZipArchive $file 安装包
     * @return array
     */
    public function install($name, ZipArchive $file)
    {
        // 安装包检查
        list($state, $message) = $this->checkInstall($name, $file);
        if (empty($state)) return [$state, $message];
        // 执行文件安装
        if ($file->extractTo($this->app->getBasePath() . $name)) {
            return [1, '模块安装包安装成功'];
        } else {
            return [0, '模块安装包安装失败'];
        }
    }

    /**
     * 移除应用模块
     * @param string $name
     */
    public function remove($name)
    {
        $directory = $this->app->getBasePath() . $name;
        $this->forceRemove($directory);
    }

    /**
     * 检测安装包是否正常
     * @param string $name 模块名称
     * @param ZipArchive $file 安装包
     * @return array
     */
    private function checkInstall($name, ZipArchive $file)
    {
        $directory = "{$file->filename}.files";
        file_exists($directory) || mkdir($directory, 0755, true);
        // 尝试解压应用安装包
        if ($file->extractTo($directory) === false) {
            return [0, '应用模块ZIP文件解压失败'];
        }
        // 检测应用模块配置文件
        $info = @include($directory . DIRECTORY_SEPARATOR . 'app.php');
        $this->forceRemove($directory);
        // 返回应用模块检查结果
        if (empty($info)) return [0, '未获取到应用模块配置信息'];
        if ($info['name'] !== $name) return [0, '应用模块名称与注册名称不一致'];
        return [1, '应用模块基础检查通过'];
    }

    /**
     * 强制删除指定的目录
     * @param string $directory
     */
    private function forceRemove($directory)
    {
        if (file_exists($directory) && is_dir($directory) && $handle = opendir($directory)) {
            while (false !== ($item = readdir($handle))) if (!in_array($item, ['.', '..'])) {
                $this->forceRemove("{$directory}/{$item}");
            }
            [closedir($handle), rmdir($directory)];
        } else {
            file_exists($directory) && is_file($directory) && @unlink($directory);
        }
    }

}