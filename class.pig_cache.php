<?php

require_once 'pig.hostname.php';
require_once 'aws.constants.php';


/**
 * Amazon ElastiCcache Auto Discovery feature.
 */

/* Configuration endpoint to use to initialize memcached client. */
// $server_endpoint = AWS_ELASTIC_CACHE_ENDPOINT;
/* Port for connecting to the ElastiCache cluster. */
// $server_port = AWS_ELASTIC_CACHE_PORT;

/**
 * The following will initialize a Memcached client to utilize the Auto Discovery feature.
 *
 * By configuring the client with the Dynamic client mode with single endpoint, the
 * client will periodically use the configuration endpoint to retrieve the current cache
 * cluster configuration. This allows scaling the cache cluster up or down in number of nodes
 * without requiring any changes to the PHP application.
 */


/*
 * The below new play pig_cached is a wrapper of standard Memcached class
 * 
 * We wrap a up level wrapper outside the real data adding some config settings.
 * Benefit:
 * 1. Has a standard rules with the cache variable name. Don't worry about the cache name conflict between apps.
 * 2. Has a strong level to control which kind of cache need to reload from DB at once.
 * 3. Has a clear view to see how many kind of app cache running in our cache server now.
 * 4. Developer don't need to make any special codes change, same as a standard memcached class.
 * 5. Exactly same as standard memcached class if you not set any variables when you start to create a object at begain. Like $pig_cache = new pig_cached();
 * 
 * Extra:
 * The cache data structure see below
 * Global lock cache list
 * 'PLAYIT_PIG_CACHE_CONTROL_LIST' => Array
(
    [PROD|API|12345|LOCK_KEY] => API NEWS
    [PROD|ADMIN|6891|LOCK_KEY] => FANTASY ENGINE ADMIN
    [UAT|ADMIN|6891|LOCK_KEY] => FANTASY ENGINE ADMIN
    [UAT|API|12345|LOCK_KEY] => API NEWS
    [UAT|API|10001|LOCK_KEY] => FANTASY ENGINE
    [PROD|API|10001|LOCK_KEY] => FANTASY ENGINE
    [UAT|API|10000|LOCK_KEY] => API FOOTBALL
    [PROD|API|10000|LOCK_KEY] => API FOOTBALL
    [UAT|API|20000|LOCK_KEY] => API CRICKET
    [DEV|API|10002|LOCK_KEY] => API NOTIFICATION
)
   
 * One app lock cache
 * 'DEV|API|10002|LOCK_KEY' => Array
(
    [lock_timestamp] => 0
    [update_timestamp] => 0
)
 * Individual cache data 
 * 'KEY' => Array ('GEN_TIMESTAMP' => time(), 
					'APP_TYPE'      => $this->app_type, 
					'APP_NAME'      => $this->app_name, 
					'APP_ID'        => $this->app_id,  
					'ENV'           => PLAY_ENVIRONMENT,
				    'RELYING_KEY'   => $relying_key, 
					'DATA'          => ''
			);
 */

class pig_cached {
	private $pig_cache  = NULL;
	public  $app_name   = '';
	public  $app_id     = 0;
	public  $app_type   = '';
	public  $cache_host = '';
	private $version    = '1.0.0';
	private $playit_global_cache_list_key = 'PLAYIT_PIG_CACHE_CONTROL_LIST'; //This is a global and const cache to let us can force app to truncate all cache to reload the data from DB.

	public function __construct($app_type = 'APPS', $app_name = 'PLAY_IT', $app_id = 0, $cach_host = ''){		
		$init = TRUE;
		
		if (($app_type === 'APPS')&&($app_name === 'PLAY_IT')&&($app_id === 0)){//old version
			$this->version = '';
		}
		else{//new version
			$err_msg = '';
			if ($this->init_cache($app_type, $app_name, $app_id, $cach_host, $err_msg) != TRUE){
				error_log('ERROR: '.__METHOD__.": failed to init play cache object with error [$err_msg]");
				$init = FALSE;
			}
		}
		if ($init === TRUE){//set object
			$this->pig_cache = new Memcached();
			$this->pig_cache->setOption(Memcached::OPT_CLIENT_MODE, Memcached::DYNAMIC_CLIENT_MODE);
			$this->pig_cache->addServer(AWS_ELASTIC_CACHE_ENDPOINT, AWS_ELASTIC_CACHE_PORT);
						
			//setup play cache wrapper
			if ($this->version != ''){//update the global cache control
				$pig_cache_gbl  = $this->get_cache_ctl_list();
				$cache_lock_key = $this->gen_cache_lock_name();
				$app_name       = strtoupper($app_name);	

				if (empty($pig_cache_gbl)){
					$pig_cache_gbl[$cache_lock_key] = $app_name;
					$this->pig_cache->set($this->playit_global_cache_list_key, $pig_cache_gbl, 0);
				}
				else{
					/*
					//parameters all same as before if they registered already?
					foreach ($pig_cache_gbl as $ca_n => $ca_v){
						list($env, $ty, $id, $re) = explode('|', $ca_n);
						
						if (($this->app_id == $id) && ($this->app_type == $ty)){
							if ($app_name != $ca_v){
								error_log('ERROR: '.__METHOD__.": App ID = [$id] and App Type = [$ty] have been used by different app name = [$ca_v]. Please double check your parameters again and you can manually click the button 'REMOVE LOCK' to remvoe the exists cache info from Admin Console if you confidently still to use this App ID and App Type. Otherwise please change to new ID and Type.");
								unset($this->pig_cache);
								$this->pig_cache = NULL;
								break;
							}
						}
					}
					*/

					if ($this->cache_object_check()){
						if (!isset($pig_cache_gbl[$cache_lock_key])){
							$pig_cache_gbl[$cache_lock_key] = $app_name;
							$this->pig_cache->replace($this->playit_global_cache_list_key, $pig_cache_gbl, 0);
						}
					}
										
				}
			}
		}	
	}
	
	/**
	 *
	 * Get cache from server
	 *
	 * @access	public
	 * @param	string
	 * @return	boolean
	 */
	public function get($key = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return '';
		}
		
		if ($this->version == ''){//old version
			return $this->pig_cache->get($key);
		}
		else{			
			//get cache lock control
			$cache_lock_var = $this->get_cache_lock();
			
			//get app data now
			$cache_key      = $this->gen_cache_name($key);
			$app_data       = $this->pig_cache->get($cache_key);
					
			
			$return = '';
			if (isset($app_data['DATA'])){//has data set in cache			
				if ($cache_lock_var['lock_timestamp'] == 0){//no lock to ask cache to reload
					$return = $app_data['DATA'];
				}
				else{
					if (isset($app_data['GEN_TIMESTAMP'])){
						if ($cache_lock_var['lock_timestamp'] > $app_data['GEN_TIMESTAMP']){//return empty to force app to reload data from DB
							$this->delete($key);
							return '';
						}
						else{
							//update latest timestamp to mark we have new data already.
							$this->update_cache_lock();
							$return = $app_data['DATA'];
						}
					}
					else{//impossible
						$return = $app_data['DATA'];
					}
				}
			}
			else{//no data set in cache
				return '';
			}

						
			if ($return != ''){
				//get the relying on key's timestamp, if this timestamp changed, it means the cache data of the relying key changed, then the cache which rely on it must be reload from DB.
				$relying_key_update_timestamp = 0;
				if (isset($app_data['RELYING_KEY']) && !empty($app_data['RELYING_KEY'])){		
					$relying_cache_data = $this->pig_cache->get($app_data['RELYING_KEY']);
					
					if (isset($relying_cache_data['update_timestamp']) && ($relying_cache_data['update_timestamp'] > 0)){
						if (isset($app_data['GEN_TIMESTAMP'])){
							if ($relying_cache_data['update_timestamp'] > $app_data['GEN_TIMESTAMP']){
								$this->delete($key);
								return '';
							}
							else{//cache data is reloaded
								return $app_data['DATA'];;
							}
						}
						else{//impossible
							return $app_data['DATA'];
						}
					}
					else{//relying key timestamp not set or no data changed
						return $app_data['DATA'];
					}
				}
				else{//not set relying key
					return $app_data['DATA'];
				}				
			}
			else{//no data set in cache
				return '';
			}
		}
		return '';
	}
	
	/**
	 *
	 * Set cache in server
	 *
	 * @access	public
	 * @param	string
	 * @param	string
	 * @param	int
	 * @param	string
	 * @return	boolean
	 */
	public function set($key = '', $value = '', $ttl = -1, $relying_key = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
		
		$ttl = ($ttl === -1)? time() : $ttl;
		
		if (! empty($relying_key)){
			$relying_key = str_replace(' ', '-', $relying_key);
			$relying_key = strtolower($relying_key);
		}
			
		if ($this->version == ''){//old version
			$this->pig_cache->delete($key);
			return $this->pig_cache->set($key, $value, $ttl);
		}
		else{//new version			
			$cache_key        = $this->gen_cache_name($key);
			$this->delete($cache_key);
			
			$data_tmp         = $this->gen_app_data($value, $relying_key);
			return $this->pig_cache->set($cache_key, $data_tmp, $ttl);
		}
		return FALSE;
	}
	
	/**
	 *
	 * Replace cache from server
	 *
	 * @access	public
	 * @param	string
	 * @param	string
	 * @param	int
	 * @param	string
	 * @return	boolean
	 */
	public function replace($key = '', $value = '', $ttl = -1, $relying_key = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
		
		$ttl = ($ttl === -1)? time() : $ttl;
		
		return $this->set($key, $value, $ttl, $relying_key);
	}
	
	/**
	 *
	 * Delete cache from server
	 *
	 * @access	public
	 * @param	string	 
	 * @return	boolean
	 */
	public function delete($key = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
		
		if ($this->version == ''){//old version
			return $this->pig_cache->delete($key);
		}
		else{//new version
			$cache_key = $this->gen_cache_name($key);
			return $this->pig_cache->delete($cache_key);
		}
		return FALSE;	
	}

	/**
	 *
	 * Clear/remove the specified app lock cache
	 *
	 * @access	public
	 * @param	string
	 * @param   string
	 * @param   int
	 * @return	boolean
	 */
	public function clear_cache_lock($env = '', $type = '', $id = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
				
		//clear the cache lock
		$cache_lock_key = $this->gen_cache_lock_name($env, $type, $id);
		
		return $this->pig_cache->delete($cache_lock_key);
	}
	
	/**
	 *
	 * Remove the cache lock
	 *
	 * @access	public
	 * @param	string
	 * @param   string
	 * @param   int
	 * @return	boolean
	 */
	public function remove_cache_lock($env = '', $type = '', $id = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
				
		//clear the cache lock
		$this->clear_cache_lock($env, $type, $id);
		
		//remove the ctl from list
		$cache_lock_key = $this->gen_cache_lock_name($env, $type, $id);
		$pig_cache_gbl  = $this->get_cache_ctl_list();
		if (isset($pig_cache_gbl[$cache_lock_key])){
			unset($pig_cache_gbl[$cache_lock_key]);
			return $this->pig_cache->replace($this->playit_global_cache_list_key, $pig_cache_gbl, 0);
		}
		
		return FALSE;
	}

	/**
	 *
	 * Set a app lock cache
	 *
	 * @access	public
	 * @param	string
	 * @param   string
	 * @param   int
	 * @return	boolean
	 */
	public function set_cache_lock($env = '', $type = '', $id = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
		
		$this->clear_cache_lock($env, $type, $id);
		
		$cache_lock_var['lock_timestamp']   = time();
		$cache_lock_var['update_timestamp'] = 0;

		$cache_lock_key = $this->gen_cache_lock_name($env, $type, $id);	
		return $this->pig_cache->set($cache_lock_key, $cache_lock_var, 0);
	}
	
	/**
	 *
	 * Get the global lock cache list
	 *
	 * @access	public
	 * @param	NULL
	 * @return	array
	 */
	public function get_cache_ctl_list(){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return '';
		}
		
		return $this->pig_cache->get($this->playit_global_cache_list_key);
	}

	/**
	 *
	 * Reset the global lock cache
	 *
	 * @access	public
	 * @param	NULL
	 * @return	boolean
	 */
	public function get_cache_lock($env = '', $type = '', $id = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
		
		
		$cache_lock_key = $this->gen_cache_lock_name($env, $type, $id);
		$cache_lock_var = $this->pig_cache->get($cache_lock_key);

		$reset = FALSE;
		if (!isset($cache_lock_var['lock_timestamp'])){
			$cache_lock_var['lock_timestamp'] = 0;
			$reset = TRUE;
		}
		if (!isset($cache_lock_var['update_timestamp'])){
			$cache_lock_var['update_timestamp'] = 0;
			$reset = TRUE;
		}
		
		if ($reset === TRUE){
			$this->pig_cache->set($cache_lock_key, $cache_lock_var, 0);
		}
		
		return $cache_lock_var;
	}
	
	/** 
	 *
	 * Reset the global lock cache
	 *
	 * @access	public
	 * @param	NULL
	 * @return	boolean
	 */
	public function reset_ctl_list(){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
		
		
		$pig_cache_gbl = array();
		
		if ($this->pig_cache->delete($this->playit_global_cache_list_key)){
			return $this->pig_cache->set($this->playit_global_cache_list_key, $pig_cache_gbl, 0);
		}
		else{
			return FALSE;
		}		
	}
	
	/**
	 *
	 * Set one relying key lock cache
	 *
	 * @access	public
	 * @param	string
	 * @return	boolean
	 */
	public function set_relying_key_lock($relying_key = ''){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
		
		
		if (! empty($relying_key)){
			$relying_key = str_replace(' ', '-', $relying_key);
			$relying_key = strtolower($relying_key);
		}
		
		
		$relying_cache_key  = $this->gen_cache_relying_name($relying_key);
		$this->pig_cache->delete($relying_cache_key);
		
		$relying_cache_data = array();		
		$relying_cache_data['update_timestamp'] = time();
		$sec = 3600 * 24 * 7;//set this lock for 7 days
		$this->pig_cache->set($relying_cache_key, $relying_cache_data, $sec);		
	}
	
	
//=================================================================================================================

	/** 
	 *
	 * The data format will keep some extral info
	 *
	 * @access	private
	 * @param	string
	 * @return	string
	 */
	private function update_cache_lock(){
		if (! $this->cache_object_check()){
			error_log('ERROR: '.__METHOD__.": Cache object is invalid.");
			return FALSE;
		}
		
		$cache_lock_var = $this->get_cache_lock();
		
		if (isset($cache_lock_var['lock_timestamp']) && ($cache_lock_var['lock_timestamp'] > 0)){//has lock
			$cache_lock_var['update_timestamp'] = isset($cache_lock_var['update_timestamp'])? $cache_lock_var['update_timestamp'] : 0;
			$check = time() - $cache_lock_var['update_timestamp'];
			if ($check > 300){//update for over 5 minutes
				$cache_lock_var['update_timestamp'] = time();
				$cache_lock_key = $this->gen_cache_lock_name();

				return $this->pig_cache->replace($cache_lock_key, $cache_lock_var, 0);
			}
		}
		
		return FALSE;
	}

	/**
	 * Initial a default data
	 *
	 * The data format will keep some extral info
	 *
	 * @access	private
	 * @param	string
	 * @return	string
	 */
	private function gen_app_data($data = '', $relying_key = ''){
		$relying_key = $this->gen_cache_relying_name($relying_key);
		return array('GEN_TIMESTAMP' => time(), 
					'APP_TYPE'      => $this->app_type, 
					'APP_NAME'      => $this->app_name, 
					'APP_ID'        => $this->app_id,  
					//'ENV'           => PLAY_ENVIRONMENT,
				    'ENV'           => $this->cache_host,
				    'RELYING_KEY'   => $relying_key, 
					'DATA'          => $data
			);
	}
	
	/**
	 * Generate Cache Name For Global Lock
	 *
	 *
	 * @access	private
	 * @param	string
	 * @param	string
	 * @param	int
	 * @return	string
	 */
	private function gen_cache_lock_name($env = '', $type = '', $id = ''){
		if ($env == '' && $type == '' && $id == ''){
			//return PLAY_ENVIRONMENT.'|'.$this->app_type.'|'.$this->app_id.'|LOCK_KEY';
			return $this->cache_host.'|'.$this->app_type.'|'.$this->app_id.'|LOCK_KEY';
		}
		else{
			return $env.'|'.$type.'|'.$id.'|LOCK_KEY';
		}
	}
	
	/**
	 * Generate Cache Name
	 *
	 * Generate the cache name follow the rules.
	 *
	 * @access	private
	 * @param	string 
	 * @return	string
	 */
	private function gen_cache_name($key = ''){
		//return PLAY_ENVIRONMENT.'|'.$this->version.'|'.$this->app_type.'|'.$this->app_id.'|'.$this->app_name.'|-|'.$key;
		return $this->cache_host.'|'.$this->version.'|'.$this->app_type.'|'.$this->app_id.'|'.$this->app_name.'|-|'.$key;
	}
	
	/**
	 * Generate Relying Key Name
	 * 
	 *
	 * @access	private
	 * @param	string
	 * @return	string
	 */
	private function gen_cache_relying_name($key = ''){
		//return PLAY_ENVIRONMENT.'|'.$this->version.'|'.$this->app_type.'|'.$this->app_id.'|'.$this->app_name.'|PLAY_IT_RELYING_KEY|'.$key;
		return $this->cache_host.'|'.$this->version.'|'.$this->app_type.'|'.$this->app_id.'|'.$this->app_name.'|PLAY_IT_RELYING_KEY|'.$key;
	}
	
	/**
	 * Cache object check
	 */
	private function cache_object_check(){
		if (is_object($this->pig_cache) && (get_class($this->pig_cache) === 'Memcached')){
			return TRUE;
		}
		return FALSE;
	}
	/**
	 * Initial
	 *
	 * Initial cache paramaters and data check.
	 *
	 * @access	private
	 * @param	string in array('APPS', 'API', 'ADMIN')
	 * @param 	string
	 * @param   int
	 * @param   string
	 * @return	boolean
	 */
	private function init_cache($app_type = '', $app_name = '', $app_id = 0, $cach_host = '', &$error){
		global $__ENVIRONMENT_DEFINE_ARRAY__;
		
		
		if (!defined('PLAY_ENVIRONMENT')){
			$error = "Could not get the const PLAY_ENVIRONMENT to generate cache name.";
			return FALSE;
		}
		
		if (empty($cach_host)){//could be DEV, UAT, STAG, PROD, If set cache on Jobs box and get cache on Pro box, you can set this variable to same name, like Jobs or Pro. Then you can comunication eache other between two environment.
			$this->cache_host = PLAY_ENVIRONMENT;
		}
		else{
			$cach_host = strtoupper($cach_host);
			if (isset($__ENVIRONMENT_DEFINE_ARRAY__[$cach_host])){
				$this->cache_host = $__ENVIRONMENT_DEFINE_ARRAY__[$cach_host][0];
			}
			else{
				$error = "Invalid cache hosted name. Check please.";
				return FALSE;
			}
		}
		
		if (!defined('AWS_ELASTIC_CACHE_ENDPOINT')){
			$error = "Could not get the const AWS_ELASTIC_CACHE_ENDPOINT .";
			return FALSE;
		}
		
		if (!defined('AWS_ELASTIC_CACHE_PORT')){
			$error = "Could not get the const AWS_ELASTIC_CACHE_PORT .";
			return FALSE;
		}
				
		$app_type = !(empty($app_type))? strtoupper($app_type) : $app_type;
		$app_name = str_replace(' ', '-', $app_name);
		$app_name = strtolower($app_name);
	
		if (empty($app_type ) || !in_array($app_type, array('APPS', 'API', 'ADMIN'))){
			$error = "Parameter app_type is invalid. Must be one of 'APPS', 'API', 'ADMIN'";
			return FALSE;
		}
	
		if (empty($app_name )){
			$error = "Parameter app_name is invalid.";
			return FALSE;
		}
	
		if (!($app_id > 0)){
			$error = "Parameter app_id is invalid.";
			return FALSE;
		}
	
		$this->app_name = $app_name;
		$this->app_id   = $app_id;
		$this->app_type = $app_type;
		return TRUE;
	}
	
}



