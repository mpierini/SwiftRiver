<?php defined('SYSPATH') or die('No direct script access');
/**
 * Initializes the SwiftRiver environment
 *
 * PHP version 5
 * LICENSE: This source file is subject to the AGPL license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/licenses/agpl.html
 * @author     Ushahidi Team <team@ushahidi.com> 
 * @package    SwiftRiver - https://github.com/ushahidi/SwiftRiver
 * @subpackage Cookie config
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license    http://www.gnu.org/licenses/agpl.html GNU Affero General Public License (AGPL)
 */

class Swiftriver {

	/**
	 * Default salt value to add to the cookies
	 */
	const DEFAULT_COOKIE_SALT = 'cZjO0Lgfv7QrRGiG3XZJZ7fXuPz0vfcL';

	// Cookie name constants
	const COOKIE_SEARCH_SCOPE = "search_scope";
	const COOKIE_PREVIOUS_SEARCH_SCOPE = "previous_search_scope";
	const COOKIE_SEARCH_ITEM_ID = "search_item_id";
	
	// Crawl mutex
	const CRAWL_MUTEX = 'SwiftRiver_Crawler';

	/**
	 * Application initialization
	 *     - Loads the plugins
	 *     - Sets the cookie configuration
	 */
	public static function init()
	{
		// Set defaule cache configuration
		Cache::$default = Kohana::$config->load('site')->get('default_cache');
		
		try
		{
			$cache = Cache::instance()->get('dummy'.rand(0,99));
		}
		catch (Exception $e)
		{
			// Use the dummy driver
			Cache::$default = 'dummy';
		}
		
		
		// Load the plugins
		Swiftriver_Plugins::load();

		// Add the current default theme to the list of modules
		$theme = Swiftriver::get_setting('site_theme');

		if (isset($theme) AND $theme != "default")
		{
			Kohana::modules(array_merge(
				array('themes/'.$theme->value => THEMEPATH.$theme->value),
				Kohana::modules()
			));
		}

		// Clean up
		unset ($active_plugins, $theme);

		// Load the cookie configuration
		$cookie_config = Kohana::$config->load('cookie');
		Cookie::$httponly = TRUE;
		Cookie::$salt = $cookie_config->get('salt', Swiftriver::DEFAULT_COOKIE_SALT);
		Cookie::$domain = $cookie_config->get('domain') OR '';
		Cookie::$secure = $cookie_config->get('secure') OR FALSE;
		Cookie::$expiration = $cookie_config->get('expiration') OR 0;

		// Set the default site locale
		I18n::$lang = Swiftriver::get_setting('site_locale');
	}
	
	/**
	 * Returns the CDN url for $file
	 *
	 * @param   string   file name
	 * @return  string
	 */
	public static function get_cdn_url($file)
	{
		$cdn_url = Kohana::$config->load('site')->get('cdn_url');
		if (isset($cdn_url))
		{
			$cdn_dirs = Kohana::$config->load('site')->get('cdn_directories');
			foreach ($cdn_dirs as $dir)
			{
				$file = preg_replace('|^('.$dir.')|', $cdn_url.'/$1', $file);
			}
		}
		
		return $file;
	}
	
	/**
	 * Forks of the callback into a separate process.
	 * The parent process exits immediately completing the HTTP request.
	 * and maintain a mutex preventing other instances of this class
	 * from running before the callback completes.
	 * Double fork is done to allow the callback to obtain another
	 * mutex if need be.
	 */	   
	public static function do_fork($callback, $mutex=NULL)
	{
		// The signals used below require cli mode
		if (php_sapi_name() != 'cli')
		{
		    Kohana::$log->add(Log::ERROR, "CLI mode is required");
			return;
		}
		
		// Fork process to do the crawl if pcntl is installed
		if ( ! function_exists('pcntl_fork'))
		{
			Kohana::$log->add(Log::ERROR, "PCNTL is required");
			return;
		}

		$pid = pcntl_fork();		
		if ($pid == -1)
		{
			 Kohana::$log->add(Log::ERROR, "Forking failed.");
		}
		elseif ($pid == 0)
		{
			// Fork again
			// This second parent will hold the crawl mutex
			// so that child processes can other locks

			// Install signal handlers
			declare(ticks = 1); // How often to check for signals
			// Run callable where OK received from parent
			pcntl_signal(SIGUSR1, $callback);
			// Exit when NACK received from parent.
			pcntl_signal(SIGUSR2, function($signo) { exit; } );
						
			// Force reconnection. Both parent and child
			// processes will open their own conneciton
			// once they start.
			Database::instance()->disconnect();
			
			$pid = pcntl_fork();
			
			if ($pid == -1)
			{
				 Kohana::$log->add(Log::ERROR, "Second fork failed.");
			}
			elseif ($pid == 0)
			{
				// Second child
				
				// Wait for signal from parent to proceed
				while (TRUE)
					sleep(60);
			}
			else
			{
				// Second parent
				try
				{
					if ($mutex) 
					{
						Swiftriver_Mutex::obtain($mutex);
					}
					
					// Signal child to proceed
					Kohana::$log->write();
					posix_kill($pid, SIGUSR1);
				}
				catch (SwiftRiver_Exception_Mutex $e)
				{
					// Signal child to exit
					Kohana::$log->add(Log::ERROR, "Unable to obtain mutex");
					posix_kill($pid, SIGUSR2);
					exit;
				}
				pcntl_wait($status);
				if ($mutex) 
				{
					Swiftriver_Mutex::release($mutex);
				}
			}
		}
	}
    
	/**
	 * Get a single setting value
	 *
	 * @param string $key
	 * @return string Value for the key
	 */
	public static function get_setting($key)
	{
		$value = NULL;
		$cache_key = 'site_setting_'.$key;
		if ( ! ($value = Cache::instance()->get($cache_key, FALSE)))
		{
			$value = Kohana::$config->load('site')->get($key);
			
			Cache::instance()->set($cache_key, $value, 86400 + rand(0,86400));
		}
			
		return $value;
	}

	/**
	 * Given an array of keys, returns an an array of the key-value pairs from the db
	 *
	 * @param array $setting_keys Array of keys to be fetched
	 * @return Array hash of the key value pairs from the db
	 */
	public static function get_settings($setting_keys)
	{
		if (empty($setting_keys) OR ! is_array($setting_keys))
			return NULL;
        
        $settings_array = array();
		foreach ($setting_keys as $key)
		{
			$settings_array[$key] = get_setting($key);
		}
		
		return $settings_array;
	}

	/**
	 * Creates and returns the base view for rendering error pages
	 * Error handlers that use this method must set the $content
	 * property of the view
	 *
	 * @return    View
	 */
	public static function get_base_error_view()
	{
		$view = View::factory('template/layout')
			->set('footer', View::factory('template/footer'))
			->bind('header', $header);
		
		// Header
		// Params for the <head> section
		$dashboard_url =  URL::site('/', TRUE);
		$_head_params = array(
			'meta' => "",
			'js'=> "",
			'css' => "",
			'messages' => json_encode(array()),
			'dashboard_url' => $dashboard_url,
		);
		
		$header = View::factory('template/header')
			->set('show_nav', TRUE)
			->set('site_name', Swiftriver::get_setting('site_name'))
			->set($_head_params)
			->bind('nav_header', $nav_header);
		
		// Navigation header
		$nav_header = View::factory('template/nav/header')
			->set('user', NULL)
			->set('anonymous', FALSE)
			->set('dashboard_url', $dashboard_url);
		
		return $view;
	}

}
