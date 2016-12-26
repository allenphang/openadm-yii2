<?php
/**
 * 插件管理者
 * 插件id,插件目录，必须为小写
 * @author xiongchuan <xiongchuan@luxtonenet.com>
 */
namespace app\models;
use yii;
use app\common\SystemConfig;
use yii\helpers\FileHelper;
use yii\base\ErrorException;
use yii\helpers\Json;
use yii\base\InvalidParamException;
class PluginManager
{
	const STATUS_SUCCESS = 1;
	const STATUS_ERROR   = 0;
	const ERROR_NEEDED   = 110;
	const ERROR_NOTATLOCAL = 120;
	
	const PLUGIN_TYPE_ADMIN = "ADMIN";
	const PLUGIN_TYPE_API   = "API";
	const PLUGIN_TYPE_HOME  = "HOME";

    const PLUGIN_CONFIG_ID_RECORD_KEY = "PLUGIN_CONFIG_IDS";
	
	static private $_plugins = array();
	static private $_setupedplugins = array();
	static private $_valid_menu_cfgnames = [
	    SystemConfig::TOPMENU_KEY,
        SystemConfig::LEFTMENU_KEY,
        SystemConfig::INNERMENU_KEY,
    ];
	
	/**
	 * 获取已经安装的插件
	 */
	static public function GetSetupedPlugins()
	{
		if(empty(self::$_setupedplugins)){
			$plugins = SystemConfig::Get('',null,SystemConfig::CONFIG_TYPE_PLUGIN);
            foreach ($plugins as $plugin){
                try{
                    self::$_setupedplugins[$plugin['cfg_name']] = Json::decode($plugin['cfg_value'],true);
                }catch (InvalidParamException $e){
                    self::$_setupedplugins[$plugin['cfg_name']] = $plugin['cfg_value'];
                }
            }
		}
		return self::$_setupedplugins;
	}
	
	static public function PluginSetupedCompleted($pluginid,array $config)
	{
        $cfg_value = Json::encode(array_merge($config,[self::PLUGIN_CONFIG_ID_RECORD_KEY=>self::$_plugins[$pluginid][self::PLUGIN_CONFIG_ID_RECORD_KEY]]));
		$params = array(
			'cfg_value'   => $cfg_value,
			'cfg_comment' => $config['name'],
            'cfg_type'    =>SystemConfig::CONFIG_TYPE_PLUGIN
		);
		SystemConfig::Set($pluginid,$params);
		return true;
	}
	
	
	/**
	 * 获取单个plugin的config
	 * @param $pluginid string
	 * @param $cache 是否缓存
	 * @param $dir string  实时获取配置
	 * @param $checkDependency 是否检查依赖插件
	 */
	static public function GetPluginConfig($pluginid,$cache=true,$dir=null,$checkDependency = true)
	{
		$dir = $dir ? $dir : self::GetPluginPath($pluginid);
		$config = array(
			'setup'  => self::IsSetuped($pluginid),
			'update' => self::hasUpdate($pluginid),
			'config' => false
		);
		$pluginconfigfile = $dir ."/config.php";
		if(is_file($pluginconfigfile)){
			if(!self::ParsePluginConfig($pluginid))return false;
			$config['config'] = require $pluginconfigfile;
			//检查依赖插件
			if($checkDependency)self::CheckDependency($config['config']);
		}
		if($cache){
			self::$_plugins[$pluginid] = $config;
		}
		return $config;
	}
	
	/**
	 * 
	 * 获取本地的全部插件
	 * 支持分页显示
	 * @param $type string  all:全部,setuped:安装的,new:新的
	 * @param $page int
	 * @param $pageSize int
	 * @return array|boolean
	 */
	static public function GetPlugins($type="all",$page=1,$pageSize=20)
	{
		//获取数据源
		$setupedplugins = self::GetSetupedPlugins();
		//var_dump($setupedplugins);exit;
		if("setuped"==$type){
			$fileArray = array_map('strtolower',array_keys($setupedplugins));
		}else{
			$pluginDir = Yii::getAlias('@plugins');
			$fileArray = array_slice(scandir($pluginDir,0),2);//过滤掉.|..目录
//			var_dump($fileArray);exit;
			//改写fileArray
			if("new" == $type){
				$setuped = array_map('strtolower',array_keys($setupedplugins));
				$fileArray = array_diff($fileArray, $setuped);
			}
		}//获取数据源结束
		
		//对分页进行边界判断
		if($pageSize <=0) $pageSize = 20;
		$total = count($fileArray);
		$pages = ceil($total/$pageSize);
		if($page<=0) $page = 1;
		if($page>=$pages) $page = $pages;
		//分页判断结束
		$start = ($page-1)*$pageSize;
		$fileArraySlice = array_slice($fileArray, $start,$pageSize);
		
		if(!empty($fileArraySlice)){
			foreach($fileArraySlice as $pluginid){
				//过滤不合格的plugin
				if(!self::ParsePluginConfig($pluginid))continue;
				self::$_plugins[$pluginid] = array(
					'setup'  => self::IsSetuped($pluginid),
					'update' => self::hasUpdate($pluginid),
					'config' => false
				);
				$pluginconfigfile = self::GetPluginPath($pluginid)."/config.php";
				if(is_file($pluginconfigfile)){
					self::$_plugins[$pluginid]['config'] = require $pluginconfigfile;
					//检查依赖插件
					self::CheckDependency(self::$_plugins[$pluginid]['config']);
				}
			}
			$result = array(
				'page' => $page,
				'pageSize' => $pageSize,
				'total' => $total,
				'pages' => $pages,
				'data'  => self::$_plugins
			);
			return $result;
		}
		return false;
	}
	
	
	/**
	 * 获取插件路径
	 */
	static public function GetPluginPath($pluginid)
	{
		return Yii::getAlias('@plugins').DIRECTORY_SEPARATOR.strtolower($pluginid).DIRECTORY_SEPARATOR;
	}
	
	/**
	 * 删除静态变量数组里面的值
	 */
	static public function PluginDeleteStaticVar($pluginid)
	{
		if(!empty(self::$_setupedplugins)){
			unset(self::$_setupedplugins[$pluginid]);
		}
	}
	
	/**
	 * 判断是否已经安装
	 */
	static public function IsSetuped($pluginid)
	{
		if(empty(self::$_setupedplugins)){
			self::GetSetupedPlugins();
		}
		return isset(self::$_setupedplugins[$pluginid]) ? 1 : 0;
	}
	
	/**
	 * 检查更新:本地检查
	 */
	static public function hasUpdate($pluginid)
	{
		$updateDir = self::GetPluginPath($pluginid)."/update";
		return is_dir($updateDir) ? 1 : 0;
	}
	
	/**
	 * 检测依赖关系
	 */
	static public function CheckDependency(array &$config)
	{
		$unsetuped = array();
		if(is_array($config)){
			$dependencies = isset($config['dependencies']) ? $config['dependencies'] : '';
			$array = explode(",", $dependencies);
			if(!empty($array)){
				foreach($array as $pluginid){
					if(0 == self::IsSetuped($pluginid)){
						$unsetuped[] = $pluginid;
					}
				}
			}
		}
		$config['needed'] = join(",",$unsetuped);
	}
	
	/**
	 * 检查menu的cfg_name是否合法
	 * @param $cfg_name string
	 * @return boolean
	 */
	static public function CheckMenuCfgName($cfg_name)
	{
		return in_array($cfg_name,self::$_valid_menu_cfgnames);
	}
	
	/**
	 * 插件注入route
	 */
	static public function PluginInjectRoute(array $conf)
	{
		if(isset($conf['route']) && !empty($conf['route']) && is_array($conf['route'])){
			foreach($conf['route'] as $rule){
				$params = [
					'cfg_value'   => $rule,
					'cfg_comment' => $conf['id'],
					'cfg_pid'     => 0,
					'cfg_order'   => 0,
					'cfg_type'    => 'ROUTE'
				];
				$cfg_name = strtoupper("plugin_{$conf['id']}_route");
                $lastid = SystemConfig::Set($cfg_name,$params);
                self::RecordPluginConfigId($conf['id'],$lastid);
			}
		}
	}

    /**
     * 把config注入到system_config
     * @param array $conf
     */
	static public function PluginInjectConfig(array $conf)
    {
        if(isset($conf['config']) && !empty($conf['config']) && is_array($conf['config'])){
            foreach ($conf['config'] as $config){
                if(isset($config['cfg_name']) && !empty($config['cfg_name'])){
                    $params = [
                        'cfg_name'  => $config['cfg_name'],
                        'cfg_value' => isset($config['cfg_value']) ? $config['cfg_value'] : '',
                        'cfg_comment' => isset($config['cfg_comment']) ? $config['cfg_comment'] : '',
                    ];
                    $lastid = SystemConfig::Set($config['cfg_name'],$params);
                    self::RecordPluginConfigId($conf['id'],$lastid);
                }
            }
        }
    }

    /**
     * 安装过程中,记录_pluings[pluginId] = ['config_ids'=>[]]
     * @param $pluginId plugin id
     * @param $configId system_config id
     */
	static public function RecordPluginConfigId($pluginId,$configId)
    {
        if( $configId>0){
            if(!isset(self::$_plugins[$pluginId])){
                self::$_plugins[$pluginId] = [];
            }
            if(!isset(self::$_plugins[$pluginId][self::PLUGIN_CONFIG_ID_RECORD_KEY])){
                self::$_plugins[$pluginId][self::PLUGIN_CONFIG_ID_RECORD_KEY] = [];
            }
             array_push(self::$_plugins[$pluginId][self::PLUGIN_CONFIG_ID_RECORD_KEY],$configId);
        }
    }

    /**
     * 实际注入方法
     * @param $pluginId
     * @param $cfg_name
     * @param array $menus
     */
	static public function _PluginInjectMenu($pluginId,$cfg_name,array $menus)
    {
        foreach ($menus as $menu){
            $params = array(
                'cfg_value'   => isset($menu['cfg_value']) ? $menu['cfg_value'] : '',
                'cfg_comment' => isset($menu['cfg_comment']) ? $menu['cfg_comment'] : '',
                'cfg_pid'     => isset($menu['cfg_pid']) ? $menu['cfg_pid'] : 0,
                'cfg_order'   => isset($menu['cfg_order']) ? $menu['cfg_order'] : 0
            );
            if(empty($params['cfg_value']) || empty($params['cfg_comment']))continue;
            //检查cfg_value是否为数组,并且有url,icon(可选)
            if(is_array($params['cfg_value']) && isset($params['cfg_value']['url'])){
                $params['cfg_value'] = Json::encode($params['cfg_value']);
            }else{
                continue;//不满条件,就继续foreach
            }
            //写入system_config表
            $lastPuginConfigId = SystemConfig::Set($cfg_name,$params);
            self::RecordPluginConfigId($pluginId,$lastPuginConfigId);

            $key = '';
            if($cfg_name == SystemConfig::TOPMENU_KEY){
                $key = SystemConfig::LEFTMENU_KEY;
            }else if($cfg_name == SystemConfig::LEFTMENU_KEY){
                $key = SystemConfig::INNERMENU_KEY;
            }
            if($lastPuginConfigId && isset($menu[$key]) && !empty($menu[$key])){
                self::_PluginInjectMenu($pluginId,$key,$menu[$key]);
            }
        }

    }
	
	/**
	 * 插件菜单注入
	 */
	static public function PluginInjectMenu(array $conf)
	{
		$pluginId = $conf['id'];
		if(isset($conf['menus']) && is_array($conf['menus']) && !empty($conf['menus']))foreach ($conf['menus'] as $cfg_name => $menus) {
			if(!self::CheckMenuCfgName($cfg_name)) continue;

			self::_PluginInjectMenu($pluginId,$cfg_name,$menus);
		}
	}
	
	/**
	 * 创建数据库表
	 */
	static public function PluginExecSQL(array $conf)
	{
		return true;
	}
	
	static public function SetupLocalPlugin($pluginName)
	{
		//解析配置
		$config = self::ParsePluginConfig($pluginName);
		//根据配置执行操作
		foreach ($config as $action => $conf) {
			if(method_exists(self, $action)){
				self::$action($conf);
			}
		}
	}
	
	/**
	 * 解析配置
	 */
	static public function ParsePluginConfig($pluginid,$conf=null)
	{
		if(is_array($conf)){
			$config = $conf;
		}else{
			$configfile = self::GetPluginPath($pluginid)."/config.php";
			if(!is_file($configfile))return false;
			$config = require $configfile;
		}
		//pluginidController的pluginid要和pluginid.php里面的id值相等
		if(!isset($config['id']) || $pluginid != $config['id']){
			return false;
		}
		if(!isset($config['version']) || 
		   !isset($config['name']) ||
		   !isset($config['type']) ||
			empty($config['version']) ||
			empty($config['name']) ||
			empty($config['type'])
			){
			return false;
		}
		return true;
	}
	
	/**
	 * 移除插件在system_config里面的配置
	 * @param $pluginid string
	 */
	static public function PluginDeleteDBConfig($pluginid)
	{
	    $plugins = SystemConfig::Get($pluginid,null,SystemConfig::CONFIG_TYPE_PLUGIN);
        if($plugins && is_array($plugins))foreach ($plugins as $plugin){
            try{
                $value = Json::decode($plugin['cfg_value']);
                $config_ids = isset($value[self::PLUGIN_CONFIG_ID_RECORD_KEY]) ? $value[self::PLUGIN_CONFIG_ID_RECORD_KEY] : [];
                if(is_array($config_ids) && !empty($config_ids))foreach ($config_ids as $id){
                    SystemConfig::Remove($id);
                }
            }catch (InvalidParamException $e){

            }
            //删除自己
            SystemConfig::Remove($plugin['id']);
        }
		return false;
	}
	
	static public function download()
	{
		
	}
	
	static public function unzip()
	{
		
	}
	
	
	/**
	 * 安装插件
	 * @param $pluginid
	 */
	static public function setup($pluginid)
	{
		$data = array("status"=>self::STATUS_ERROR,'msg'=>'未知错误');
		//检查是否已经安装
		if( 0 == self::IsSetuped($pluginid)){
			$configRaw = self::GetPluginConfig($pluginid,false);
			$config = $configRaw['config'];
			if(isset($config['needed']) && !empty($config['needed'])){
				$data['status'] = self::STATUS_ERROR;
				$data['error_no'] = self::ERROR_NEEDED;
				$data['msg']      = "请先安装缺失的依赖插件，再安装此插件！";
				return $data;
			}
			if($config){
				//注入菜单
				self::PluginInjectMenu($config);
				//注入route
				self::PluginInjectRoute($config);
                //注入config
                self::PluginInjectConfig($config);
				//导入数据表
				self::PluginExecSQL($config);
				//完成最后操作
				self::PluginSetupedCompleted($pluginid,$config);
				$data['status'] = self::STATUS_SUCCESS;
				$data['msg'] = "安装成功";
				return $data;
			}else{
				//需要去插件商城下载
				$data['status'] = self::STATUS_ERROR;
				$data['error_no'] = self::ERROR_NOTATLOCAL;
				$data['msg']      = "插件在本地不存在，请去插件商城下载安装！";
				return $data;
			}
		}else{
			$data = array("status"=>self::STATUS_ERROR,'msg'=>'已经安装了');
		}
		return $data;
	}
	
	/**
	 * 卸载插件
	 * @param $pluginid
	 */
	static public function unsetup($pluginid)
	{
		self::PluginDeleteDBConfig($pluginid);
		self::PluginDeleteStaticVar($pluginid);
		$data = array("status"=>self::STATUS_SUCCESS,'msg'=>'卸载完成');
		return $data;
	}
	
	/**
	 * 删除插件
	 * @param $pluginid string
	 */
	static public function delete($pluginid)
	{
		try{
			$pluginDir = self::GetPluginPath($pluginid);
			FileHelper::removeDirectory($pluginDir);
			return ['status'=>self::STATUS_SUCCESS,'msg'=>'删除成功'];
		}catch(ErrorException $e){
			return ['status' => self::STATUS_ERROR,'msg' => "删除失败(没有权限)，请手动删除插件相关文件和目录！"];
		}
	}
	
	/**
	 * 更新插件
	 */
	static public function update($pluginid)
	{
		$data = array(
			'status' => self::STATUS_ERROR,
			'msg'    => '未知错误'
		);
		$updateDir = self::GetPluginPath($pluginid)."/update/";
		$configRaw = self::GetPluginConfig($pluginid,false,$updateDir);
		$config = $configRaw['config'];
		if($config){
				//更新config
				$oldVersionRows = SystemConfig::Get(strtoupper("PLUGIN_{$pluginid}_VERSION"),null,"USER");
				$oldVersionRow = array_shift($oldVersionRows);
				$oldVersion = !empty($oldVersionRow) ? floatval($oldVersionRow['cfg_value']) : 0;
				$newVersion = floatval($config['version']);
				if($newVersion > $oldVersion){
					try{
						//更新配置文件
						$conffile = $updateDir."config.php";
						$destconffile = self::GetPluginPath($pluginid)."/config.php";
						if(is_file($conffile)){
							copy($conffile,$destconffile);
						}
						//更新lib和views
						$libDir = $updateDir."lib";
						$viewsDir = $updateDir."views";
						if(is_dir($viewsDir)){
							FileHelper::copyDirectory($viewsDir,self::GetPluginPath($pluginid)."/views");
						}
						if(is_dir($libDir)){
							FileHelper::copyDirectory($libDir,self::GetPluginPath($pluginid)."/lib");
						}
					}catch(ErrorException $e){
						return ['status' => self::STATUS_ERROR,'msg' => "更新失败，没有权限移动升级文件，请修正权限"];
					}
					$data['status'] = self::STATUS_SUCCESS;
					$data['msg']    = "更新完成!";
					if(!$config['onlyupdatefiles']){
						//卸载 只删除数据库中菜单的配置
						self::unsetup($pluginid);
						//更新配置
						$_data = self::setup($pluginid);
						if(!$_data['status']){
							$data['msg'] .= ' '.$_data['msg'];
						}
					}else{
						//更新数据库的插件版本号
						if($oldVersionRow){
							$id = isset($oldVersionRow['id']) ? $oldVersionRow['id'] : 0;
							if($id>0){
								$params = $oldVersionRow;
								$params['cfg_value'] = $newVersion;
								SystemConfig::Update($id,$params);
							}
						}
					}
					//删除升级目录
					FileHelper::removeDirectory($updateDir);
				}else{
					$data['msg'] = '更新失败，版本号低于当前版本，禁止更新操作！';
				}
		}else{
			$data['msg'] = '更新失败，配置文件有误！';
		}
		return $data;
	}
}
