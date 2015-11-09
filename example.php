<?php

include_once(dirname(__FILE__).'/class.pig_cache.php');

//create the object for NEWS API
$pig_cache = new pig_cached('API', 'API NEWS', NEWS_API_APP_ID);

//set a cache data
$cache_key = 'news_list_key';
$news_data = array(); //get data from DB
$pig_cache->set($cache_key, $news_data, 60 * 30, 'GLOBAL_NEWS_DATA_LOCK');//set the timeout after 30 minutes and also set the cache key 'GLOBAL_NEWS_DATA_LOCK'

//get the cache data
$news_data = $pig_cache->get($cache_key);


//if the news data in DB has been changed, you need to send a notice to cache class.
$pig_cache->set_relying_key_lock('GLOBAL_NEWS_DATA_LOCK');



//don't need a global cache key, which rely on user?
$cache_key = 'user_info_'.$user_id;
$user_info = array('Uid' => 12, 'Real_Name' => 'David'); //get data from DB
$pig_cache->set($cache_key, $user_info, 60 * 30, 'USER_INFO_LOCK_'.$user_id);


//update the data via API
$user_info = array('Uid' => 12, 'Real_Name' => 'Joe');
//update the data in DB
//for exampel $db->query("update register_user set real_name = ?  where uid = ?", array( (string) $user_info['Real_Name'], (int) $user_info['Uid'] ))
//then you need to send notice to cache class
$pig_cache->set_relying_key_lock('USER_INFO_LOCK_'.$user_id);



//you will get empty cache data when you try to get cache. So you need to reload the data from DB and write back to cache
$user_info = $pig_cache->get($cache_key);//empty