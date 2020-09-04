<?php

// +----------------------------------------------------------------------
// | Library for ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2020 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: https://gitee.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | gitee 仓库地址 ：https://gitee.com/zoujingli/ThinkLibrary
// | github 仓库地址 ：https://github.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------

namespace think\admin\service;

use think\admin\extend\HttpExtend;
use think\admin\extend\Parsedown;
use think\admin\Library;
use think\admin\Service;

/**
 * 系统模块管理
 * Class ModuleService
 * @package think\admin\service
 */
class ModuleService extends Service
{
    /**
     * 代码根目录
     * @var string
     */
    protected $root;

    /**
     * 官方应用地址
     * @var string
     */
    protected $server;

    /**
     * 官方应用版本
     * @var string
     */
    protected $version;

    /**
     * 模块服务初始化
     */
    public function initialize()
    {
        $this->root = $this->app->getRootPath();
        $this->version = trim(Library::VERSION, 'v');
        $maxver = strstr($this->version, '.', true);
        $this->server = "https://v{$maxver}.thinkadmin.top";
    }

    /**
     * 获取服务端地址
     * @return string
     */
    public function getServer()
    {
        return $this->server;
    }

    /**
     * 获取版本号信息
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * 获取模块变更
     * @return array
     */
    public function change(): array
    {
        [$online, $locals] = [$this->online(), $this->getModules()];
        foreach ($online as &$item) if (isset($locals[$item['name']])) {
            $item['local'] = $locals[$item['name']];
            if ($item['local']['version'] < $item['version']) {
                $item['type_code'] = 2;
                $item['type_desc'] = '需要更新';
            } else {
                $item['type_code'] = 3;
                $item['type_desc'] = '无需更新';
            }
        } else {
            $item['type_code'] = 1;
            $item['type_desc'] = '未安装';
        }
        return $online;
    }


    /**
     * 获取线上模块数据
     * @return array
     */
    public function online(): array
    {
        $data = $this->app->cache->get('moduleOnlineData', []);
        if (!empty($data)) return $data;
        $result = json_decode(HttpExtend::get($this->server . '/admin/api.update/version'), true);
        if (isset($result['code']) && $result['code'] > 0 && isset($result['data']) && is_array($result['data'])) {
            $this->app->cache->set('moduleOnlineData', $result['data'], 30);
            return $result['data'];
        } else {
            return [];
        }
    }

    /**
     * 安装或更新模块
     * @param string $name 模块名称
     * @return array
     */
    public function install($name): array
    {
        $this->app->cache->set('moduleOnlineData', []);
        $data = $this->grenerateDifference(['app' . '/' . $name]);
        if (empty($data)) return [0, '没有需要安装的文件', []];
        $lines = [];
        foreach ($data as $file) {
            [$state, $mode, $name] = $this->updateFileByDownload($file);
            if ($state) {
                if ($mode === 'add') $lines[] = "add {$name} successed";
                if ($mode === 'mod') $lines[] = "modify {$name} successed";
                if ($mode === 'del') $lines[] = "deleted {$name} successed";
            } else {
                if ($mode === 'add') $lines[] = "add {$name} failed";
                if ($mode === 'mod') $lines[] = "modify {$name} failed";
                if ($mode === 'del') $lines[] = "deleted {$name} failed";
            }
        }
        return [1, '模块安装成功', $lines];
    }

    /**
     * 获取系统模块信息
     * @param array $data
     * @return array
     */
    public function getModules(array $data = []): array
    {
        $service = NodeService::instance();
        foreach ($service->getModules() as $name) {
            $vars = $this->_getModuleVersion($name);
            if (is_array($vars) && isset($vars['version']) && preg_match('|^\d{4}\.\d{2}\.\d{2}\.\d{2}$|', $vars['version'])) {
                $data[$name] = array_merge($vars, ['change' => []]);
                foreach ($service->scanDirectory($this->_getModuleInfoPath($name) . 'change', [], 'md') as $file) {
                    $data[$name]['change'][pathinfo($file, PATHINFO_FILENAME)] = Parsedown::instance()->parse(file_get_contents($file));
                }
            }
        }
        return $data;
    }

    /**
     * 获取文件信息列表
     * @param array $rules 文件规则
     * @param array $ignore 忽略规则
     * @param array $data 扫描结果列表
     * @return array
     */
    public function getChanges(array $rules, array $ignore = [], array $data = []): array
    {
        // 扫描规则文件
        foreach ($rules as $key => $rule) {
            $path = $this->root . strtr(trim($rule, '\\/'), '\\', '/');
            $data = array_merge($data, $this->_scanLocalFileHashList($path));
        }
        // 清除忽略文件
        foreach ($data as $key => $item) foreach ($ignore as $ign) {
            if (stripos($item['name'], $ign) === 0) unset($data[$key]);
        }
        // 返回文件数据
        return ['rules' => $rules, 'ignore' => $ignore, 'list' => $data];
    }

    /**
     * 检查文件是否可下载
     * @param string $name 文件名称
     * @return boolean
     */
    public function checkAllowDownload(string $name): bool
    {
        // 禁止目录级别上跳
        if (stripos($name, '../') !== false) {
            return false;
        }
        // 禁止下载数据库配置文件
        if (stripos(strtr($name, '\\', '/'), 'config/database') !== false) {
            return false;
        }
        // 禁止非官方演示项目下载
        if (stripos($this->app->request->domain(), 'thinkadmin.top') === false) {
            return false;
        }
        // 检查允许下载的文件规则
        foreach ($this->_getAllowDownloadRule() as $rule) {
            if (stripos($name, $rule) === 0) return true;
        }
        // 不在允许下载的文件规则
        return false;
    }

    /**
     * 获取文件差异数据
     * @param array $rules 文件规则
     * @param array $ignore 忽略规则
     * @return array
     */
    public function grenerateDifference(array $rules = [], array $ignore = []): array
    {
        [$rules1, $ignore1, $data] = [$rules, $ignore, []];
        $result = json_decode(HttpExtend::post($this->server . '/admin/api.update/node', [
            'rules' => json_encode($rules1), 'ignore' => json_encode($ignore1),
        ]), true);
        if (!empty($result['code'])) {
            $new = $this->getChanges($result['data']['rules'], $result['data']['ignore']);
            foreach ($this->_grenerateDifferenceContrast($result['data']['list'], $new['list']) as $file) {
                if (in_array($file['type'], ['add', 'del', 'mod'])) foreach ($rules1 as $rule) {
                    if (stripos($file['name'], $rule) === 0) $data[] = $file;
                }
            }
        }
        return $data;
    }

    /**
     * 尝试下载并更新文件
     * @param array $file 文件信息
     * @return array
     */
    public function updateFileByDownload(array $file): array
    {
        if (in_array($file['type'], ['add', 'mod'])) {
            if ($this->_downloadUpdateFile(encode($file['name']))) {
                return [true, $file['type'], $file['name']];
            } else {
                return [false, $file['type'], $file['name']];
            }
        } elseif (in_array($file['type'], ['del'])) {
            $real = $this->root . $file['name'];
            if (is_file($real) && unlink($real)) {
                $this->_removeEmptyDirectory(dirname($real));
                return [true, $file['type'], $file['name']];
            } else {
                return [false, $file['type'], $file['name']];
            }
        }
    }

    /**
     * 获取允许下载的规则
     * @return array
     */
    private function _getAllowDownloadRule(): array
    {
        $data = $this->app->cache->get('moduleAllowDownloadRule', []);
        if (is_array($data) && count($data) > 0) return $data;
        $data = ['think', 'config', 'public/static', 'public/router.php', 'public/index.php'];
        foreach (array_keys($this->getModules()) as $name) $data[] = 'app/' . $name;
        $this->app->cache->set('moduleAllowDownloadRule', $data, 30);
        return $data;
    }

    /**
     * 获取模块版本信息
     * @param string $name 模块名称
     * @return bool|array|null
     */
    private function _getModuleVersion(string $name)
    {
        $filename = $this->_getModuleInfoPath($name) . 'module.json';
        if (file_exists($filename) && is_file($filename) && is_readable($filename)) {
            $vars = json_decode(file_get_contents($filename), true);
            return isset($vars['name']) && isset($vars['version']) ? $vars : null;
        } else {
            return false;
        }
    }

    /**
     * 下载更新文件内容
     * @param string $encode
     * @return boolean|integer
     */
    private function _downloadUpdateFile(string $encode)
    {
        $source = $this->server . '/admin/api.update/get?encode=' . $encode;
        $result = json_decode(HttpExtend::get($source), true);
        if (empty($result['code'])) return false;
        $filename = $this->root . decode($encode);
        file_exists(dirname($filename)) || mkdir(dirname($filename), 0755, true);
        return file_put_contents($filename, base64_decode($result['data']['content']));
    }

    /**
     * 清理空目录
     * @param string $path
     */
    private function _removeEmptyDirectory(string $path)
    {
        if (is_dir($path) && count(scandir($path)) === 2 && rmdir($path)) {
            $this->_removeEmptyDirectory(dirname($path));
        }
    }

    /**
     * 获取模块信息路径
     * @param string $name 模块名称
     * @return string
     */
    private function _getModuleInfoPath(string $name): string
    {
        $appdir = $this->app->getBasePath() . $name;
        return $appdir . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR;
    }

    /**
     * 根据线上线下生成操作数组
     * @param array $serve 线上文件列表信息
     * @param array $local 本地文件列表信息
     * @return array
     */
    private function _grenerateDifferenceContrast(array $serve = [], array $local = []): array
    {
        // 数据扁平化
        [$_serve, $_local, $_diffy] = [[], [], []];
        foreach ($serve as $t) $_serve[$t['name']] = $t;
        foreach ($local as $t) $_local[$t['name']] = $t;
        unset($serve, $local);
        // 线上数据差异计算
        foreach ($_serve as $t) isset($_local[$t['name']]) ? array_push($_diffy, [
            'type' => $t['hash'] === $_local[$t['name']]['hash'] ? null : 'mod', 'name' => $t['name'],
        ]) : array_push($_diffy, ['type' => 'add', 'name' => $t['name']]);
        // 本地数据增量计算
        foreach ($_local as $t) if (!isset($_serve[$t['name']])) array_push($_diffy, ['type' => 'del', 'name' => $t['name']]);
        unset($_serve, $_local);
        usort($_diffy, function ($a, $b) {
            return $a['name'] !== $b['name'] ? ($a['name'] > $b['name'] ? 1 : -1) : 0;
        });
        return $_diffy;
    }

    /**
     * 获取目录文件列表
     * @param mixed $path 扫描目录
     * @param array $data 扫描结果
     * @return array
     */
    private function _scanLocalFileHashList(string $path, array $data = []): array
    {
        foreach (NodeService::instance()->scanDirectory($path, [], null) as $file) {
            if ($this->checkAllowDownload($name = str_replace(strtr($this->root, '\\', '/'), '', $file))) {
                $data[] = ['name' => $name, 'hash' => md5(preg_replace('/\s+/', '', file_get_contents($file)))];
            }
        }
        return $data;
    }
}