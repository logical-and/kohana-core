<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Contains the most low-level helpers methods in Kohana:
 *
 * - Environment initialization
 * - Locating files within the cascading filesystem
 * - Auto-loading and transparent extension of classes
 * - Variable and path debugging
 *
 * @package    Kohana
 * @author     Kohana Team
 * @copyright  (c) 2008-2009 Kohana Team
 * @license    http://kohanaphp.com/license.html
 */
class Kohana_Core {

	// Release version and codename
	const VERSION  = '3.0';
	const CODENAME = 'renaissance';

	// Log message types
	const ERROR = 'ERROR';
	const DEBUG = 'DEBUG';
	const INFO  = 'INFO';

	// Security check that is added to all generated PHP files
	const FILE_SECURITY = '<?php defined(\'SYSPATH\') or die(\'No direct script access.\');';

	// Format of cache files: header, cache name, and data
	const FILE_CACHE = ":header \n\n// :name\n\n:data\n";

	/**
	 * @var  array  PHP error code => human readable name
	 */
	public static $php_errors = array(
		E_ERROR              => 'Fatal Error',
		E_USER_ERROR         => 'User Error',
		E_PARSE              => 'Parse Error',
		E_WARNING            => 'Warning',
		E_USER_WARNING       => 'User Warning',
		E_STRICT             => 'Strict',
		E_NOTICE             => 'Notice',
		E_RECOVERABLE_ERROR  => 'Recoverable Error',
	);

	/**
	 * @var  string  current environment name
	 */
	public static $environment = 'development';

	/**
	 * @var  boolean  command line environment?
	 */
	public static $is_cli = FALSE;

	/**
	 * @var  boolean  Windows environment?
	 */
	public static $is_windows = FALSE;

	/**
	 * @var  boolean  magic quotes enabled?
	 */
	public static $magic_quotes = FALSE;

	/**
	 * @var  boolean  log errors and exceptions?
	 */
	public static $log_errors = FALSE;

	/**
	 * @var  string  character set of input and output
	 */
	public static $charset = 'utf-8';

	/**
	 * @var  string  base URL to the application
	 */
	public static $base_url = '/';

	/**
	 * @var  string  application index file
	 */
	public static $index_file = 'index.php';

	/**
	 * @var  string  cache directory
	 */
	public static $cache_dir;

	/**
	 * @var  boolean  enabling internal caching?
	 */
	public static $caching = FALSE;

	/**
	 * @var  boolean  enable core profiling?
	 */
	public static $profiling = TRUE;

	/**
	 * @var  boolean  enable error handling?
	 */
	public static $errors = TRUE;

	/**
	 * @var  object  logging object
	 */
	public static $log;

	/**
	 * @var  object  config object
	 */
	public static $config;

	// Currently active modules
	private static $_modules = array();

	// Include paths that are used to find files
	private static $_paths = array(APPPATH, SYSPATH);

	// File path cache
	private static $_files = array();

	// Has the file cache changed?
	private static $_files_changed = FALSE;

	/**
	 * Initializes the environment:
	 *
	 * - Disables register_globals and magic_quotes_gpc
	 * - Determines the current environment
	 * - Set global settings
	 * - Sanitizes GET, POST, and COOKIE variables
	 * - Converts GET, POST, and COOKIE variables to the global character set
	 *
	 * Any of the global settings can be set here:
	 *
	 * > boolean "errors"      : use internal error and exception handling?
	 * > boolean "profile"     : do internal benchmarking?
	 * > boolean "caching"     : cache the location of files between requests?
	 * > string  "charset"     : character set used for all input and output
	 * > string  "base_url"    : set the base URL for the application
	 * > string  "index_file"  : set the index.php file name
	 * > string  "cache_dir"   : set the cache directory path
	 *
	 * @param   array   global settings
	 * @return  void
	 */
	public static function init(array $settings = NULL)
	{
		static $run;

		// This function can only be run once
		if ($run === TRUE) return;

		// The system is now ready
		$run = TRUE;

		if (isset($settings['profile']))
		{
			// Enable profiling
			self::$profiling = (bool) $settings['profile'];
		}

		if (self::$profiling === TRUE)
		{
			// Start a new benchmark
			$benchmark = Profiler::start(__CLASS__, __FUNCTION__);
		}

		// Start an output buffer
		ob_start();

		if (isset($settings['errors']))
		{
			// Enable error handling
			Kohana::$errors = (bool) $settings['errors'];
		}

		if (Kohana::$errors === TRUE)
		{
			// Enable the Kohana shutdown handler, which catches E_FATAL errors.
			register_shutdown_function(array('Kohana', 'shutdown_handler'));

			// Enable Kohana exception handling, adds stack traces and error source.
			set_exception_handler(array('Kohana', 'exception_handler'));

			// Enable Kohana error handling, converts all PHP errors to exceptions.
			set_error_handler(array('Kohana', 'error_handler'));
		}

		if (ini_get('register_globals'))
		{
			if (isset($_REQUEST['GLOBALS']))
			{
				// Prevent malicious GLOBALS overload attack
				echo "Global variable overload attack detected! Request aborted.\n";

				// Exit with an error status
				exit(1);
			}

			// Get the variable names of all globals
			$global_variables = array_keys($GLOBALS);

			// Remove the standard global variables from the list
			$global_variables = array_diff($global_variables,
				array('GLOBALS', '_REQUEST', '_GET', '_POST', '_FILES', '_COOKIE', '_SERVER', '_ENV', '_SESSION'));

			foreach ($global_variables as $name)
			{
				// Retrieve the global variable and make it null
				global $$name;
				$$name = NULL;

				// Unset the global variable, effectively disabling register_globals
				unset($GLOBALS[$name], $$name);
			}
		}

		// Determine if we are running in a command line environment
		self::$is_cli = (PHP_SAPI === 'cli');

		// Determine if we are running in a Windows environment
		self::$is_windows = (DIRECTORY_SEPARATOR === '\\');

		if (isset($settings['cache_dir']))
		{
			// Set the cache directory path
			self::$cache_dir = realpath($settings['cache_dir']);
		}
		else
		{
			// Use the default cache directory
			self::$cache_dir = APPPATH.'cache';
		}

		if ( ! is_writable(self::$cache_dir))
		{
			throw new Kohana_Exception('Directory :dir must be writable',
				array(':dir' => Kohana::debug_path(self::$cache_dir)));
		}

		if (isset($settings['caching']))
		{
			// Enable or disable internal caching
			self::$caching = (bool) $settings['caching'];
		}

		if (self::$caching === TRUE)
		{
			// Load the file path cache
			self::$_files = Kohana::cache('Kohana::find_file()');
		}

		if (isset($settings['charset']))
		{
			// Set the system character set
			self::$charset = strtolower($settings['charset']);
		}

		if (isset($settings['base_url']))
		{
			// Set the base URL
			self::$base_url = rtrim($settings['base_url'], '/').'/';
		}

		if (isset($settings['index_file']))
		{
			// Set the index file
			self::$index_file = trim($settings['index_file'], '/');
		}

		// Determine if the extremely evil magic quotes are enabled
		self::$magic_quotes = (bool) get_magic_quotes_gpc();

		// Sanitize all request variables
		$_GET    = self::sanitize($_GET);
		$_POST   = self::sanitize($_POST);
		$_COOKIE = self::sanitize($_COOKIE);

		// Load the logger
		self::$log = Kohana_Log::instance();

		// Load the config
		self::$config = Kohana_Config::instance();

		if (isset($benchmark))
		{
			// Stop benchmarking
			Profiler::stop($benchmark);
		}
	}

	/**
	 * Recursively sanitizes an input variable:
	 *
	 * - Strips slashes if magic quotes are enabled
	 * - Normalizes all newlines to LF
	 *
	 * @param   mixed  any variable
	 * @return  mixed  sanitized variable
	 */
	public static function sanitize($value)
	{
		if (is_array($value) OR is_object($value))
		{
			foreach ($value as $key => $val)
			{
				// Recursively clean each value
				$value[$key] = self::sanitize($val);
			}
		}
		elseif (is_string($value))
		{
			if (self::$magic_quotes === TRUE)
			{
				// Remove slashes added by magic quotes
				$value = stripslashes($value);
			}

			if (strpos($value, "\r") !== FALSE)
			{
				// Standardize newlines
				$value = str_replace(array("\r\n", "\r"), "\n", $value);
			}
		}

		return $value;
	}

	/**
	 * Provides auto-loading support of Kohana classes, as well as transparent
	 * extension of classes that have a _Core suffix.
	 *
	 * Class names are converted to file names by making the class name
	 * lowercase and converting underscores to slashes:
	 *
	 *     // Loads classes/my/class/name.php
	 *     Kohana::auto_load('My_Class_Name');
	 *
	 * @param   string   class name
	 * @return  boolean
	 */
	public static function auto_load($class)
	{
		// Transform the class name into a path
		$file = str_replace('_', '/', strtolower($class));

		if ($path = self::find_file('classes', $file))
		{
			// Load the class file
			require $path;

			// Class has been found
			return TRUE;
		}

		// Class is not in the filesystem
		return FALSE;
	}

	/**
	 * Changes the currently enabled modules. Module paths may be relative
	 * or absolute, but must point to a directory:
	 *
	 *     Kohana::modules(array('modules/foo', MODPATH.'bar'));
	 *
	 * @param   array  list of module paths
	 * @return  array  enabled modules
	 */
	public static function modules(array $modules = NULL)
	{
		if ($modules === NULL)
			return self::$_modules;

		if (self::$profiling === TRUE)
		{
			// Start a new benchmark
			$benchmark = Profiler::start(__CLASS__, __FUNCTION__);
		}

		// Start a new list of include paths, APPPATH first
		$paths = array(APPPATH);

		foreach ($modules as $name => $path)
		{
			if (is_dir($path))
			{
				// Add the module to include paths
				$paths[] = realpath($path).DIRECTORY_SEPARATOR;
			}
			else
			{
				// This module is invalid, remove it
				unset($modules[$name]);
			}
		}

		// Finish the include paths by adding SYSPATH
		$paths[] = SYSPATH;

		// Set the new include paths
		self::$_paths = $paths;

		// Set the current module list
		self::$_modules = $modules;

		foreach (self::$_modules as $path)
		{
			if (is_file($path.'/init'.EXT))
			{
				// Include the module initialization file once
				require_once $path.'/init'.EXT;
			}
		}

		if (isset($benchmark))
		{
			// Stop the benchmark
			Profiler::stop($benchmark);
		}

		return self::$_modules;
	}

	/**
	 * Returns the the currently active include paths, including the
	 * application and system paths.
	 *
	 * @return  array
	 */
	public static function include_paths()
	{
		return self::$_paths;
	}

	/**
	 * Finds the path of a file by directory, filename, and extension.
	 * If no extension is given, the default EXT extension will be used.
	 *
	 * When searching the "config" or "i18n" directory, an array of files
	 * will be returned. These files will return arrays which must be
	 * merged together.
	 *
	 *     // Returns an absolute path to views/template.php
	 *     Kohana::find_file('views', 'template');
	 *
	 *     // Returns an absolute path to media/css/style.css
	 *     Kohana::find_file('media', 'css/style', 'css');
	 *
	 *     // Returns an array of all the "mimes" configuration file
	 *     Kohana::find_file('config', 'mimes');
	 *
	 * @param   string   directory name (views, i18n, classes, extensions, etc.)
	 * @param   string   filename with subdirectory
	 * @param   string   extension to search for
	 * @return  array    file list from the "config" or "i18n" directories
	 * @return  string   single file path
	 */
	public static function find_file($dir, $file, $ext = NULL)
	{
		// Use the defined extension by default
		$ext = ($ext === NULL) ? EXT : '.'.$ext;

		// Create a partial path of the filename
		$path = $dir.'/'.$file.$ext;

		if (self::$caching === TRUE AND isset(self::$_files[$path]))
		{
			// This path has been cached
			return self::$_files[$path];
		}

		if (self::$profiling === TRUE AND class_exists('Profiler', FALSE))
		{
			// Start a new benchmark
			$benchmark = Profiler::start(__CLASS__, __FUNCTION__);
		}

		if ($dir === 'config' OR $dir === 'i18n' OR $dir === 'messages')
		{
			// Include paths must be searched in reverse
			$paths = array_reverse(self::$_paths);

			// Array of files that have been found
			$found = array();

			foreach ($paths as $dir)
			{
				if (is_file($dir.$path))
				{
					// This path has a file, add it to the list
					$found[] = $dir.$path;
				}
			}
		}
		else
		{
			// The file has not been found yet
			$found = FALSE;

			foreach (self::$_paths as $dir)
			{
				if (is_file($dir.$path))
				{
					// A path has been found
					$found = $dir.$path;

					// Stop searching
					break;
				}
			}
		}

		if (self::$caching === TRUE)
		{
			// Add the path to the cache
			self::$_files[$path] = $found;

			// Files have been changed
			self::$_files_changed = TRUE;
		}

		if (isset($benchmark))
		{
			// Stop the benchmark
			Profiler::stop($benchmark);
		}

		return $found;
	}

	/**
	 * Recursively finds all of the files in the specified directory.
	 *
	 *     $views = Kohana::list_files('views');
	 *
	 * @param   string  directory name
	 * @return  array
	 */
	public static function list_files($directory = NULL, array $paths = NULL)
	{
		if ($directory !== NULL)
		{
			// Add the directory separator
			$directory .= DIRECTORY_SEPARATOR;
		}

		if ($paths === NULL)
		{
			// Use the default paths
			$paths = self::$_paths;
		}

		// Create an array for the files
		$found = array();

		foreach ($paths as $path)
		{
			if (is_dir($path.$directory))
			{
				// Create a new directory iterator
				$dir = new DirectoryIterator($path.$directory);

				foreach ($dir as $file)
				{
					// Get the file name
					$filename = $file->getFilename();

					if ($filename[0] === '.')
					{
						// Skip all hidden files
						continue;
					}

					// Relative filename is the array key
					$key = $directory.$filename;

					if ($file->isDir())
					{
						if ($sub_dir = self::list_files($key))
						{
							if (isset($found[$key]))
							{
								// Append the sub-directory list
								$found[$key] += $sub_dir;
							}
							else
							{
								// Create a new sub-directory list
								$found[$key] = $sub_dir;
							}
						}
					}
					else
					{
						if ( ! isset($found[$key]))
						{
							// Add new files to the list
							$found[$key] = realpath($file->getPathName());
						}
					}
				}
			}
		}

		// Sort the results alphabetically
		ksort($found);

		return $found;
	}

	/**
	 * Loads a file within a totally empty scope and returns the output:
	 *
	 *     $foo = Kohana::load('foo.php');
	 *
	 * @param   string
	 * @return  mixed
	 */
	public static function load($file)
	{
		return include $file;
	}

	/**
	 * Creates a new configuration object for the requested group.
	 *
	 * @param   string   group name
	 * @param   boolean  enable caching
	 * @return  Kohana_Config
	 */
	public static function config($group)
	{
		static $config;

		if (strpos($group, '.') !== FALSE)
		{
			// Split the config group and path
			list ($group, $path) = explode('.', $group, 2);
		}

		if ( ! isset($config[$group]))
		{
			// Load the config group into the cache
			$config[$group] = Kohana::$config->load($group);
		}

		if (isset($path))
		{
			return Arr::path($config[$group], $path);
		}
		else
		{
			return $config[$group];
		}
	}

	/**
	 * Provides simple file-based caching for strings and arrays:
	 *
	 *     // Set the "foo" cache
	 *     Kohana::cache('foo', 'hello, world');
	 *
	 *     // Get the "foo" cache
	 *     $foo = Kohana::cache('foo');
	 *
	 * All caches are stored as PHP code, generated with [var_export][ref-var].
	 * Caching objects may not work as expected. Storing references or an
	 * object or array that has recursion will cause an E_FATAL.
	 *
	 * [ref-var]: http://php.net/var_export
	 *
	 * @param   string   name of the cache
	 * @param   mixed    data to cache
	 * @param   integer  number of seconds the cache is valid for
	 * @return  mixed    for getting
	 * @return  boolean  for setting
	 */
	public static function cache($name, $data = NULL, $lifetime = 60)
	{
		// Cache file is a hash of the name
		$file = sha1($name).EXT;

		// Cache directories are split by keys to prevent filesystem overload
		$dir = self::$cache_dir."/{$file[0]}{$file[1]}/";

		try
		{
			if ($data === NULL)
			{
				if (is_file($dir.$file))
				{
					if ((time() - filemtime($dir.$file)) < $lifetime)
					{
						// Return the cache
						return include $dir.$file;
					}
					else
					{
						// Cache has expired
						unlink($dir.$file);
					}
				}

				// Cache not found
				return NULL;
			}

			if ( ! is_dir($dir))
			{
				// Create the cache directory
				mkdir($dir, 0777, TRUE);

				// Set permissions (must be manually set to fix umask issues)
				chmod($dir, 0777);
			}

			// Write the cache
			return (bool) file_put_contents($dir.$file, strtr(self::FILE_CACHE, array
			(
				':header' => self::FILE_SECURITY,
				':name'   => $name,
				':data'   => 'return '.var_export($data, TRUE).';',
			)));
		}
		catch (Exception $e)
		{
			throw new Kohana_Exception('Cache directory :dir is corrupt, unable to write :file',
				array(':dir' => Kohana::debug_path(self::$cache_dir), ':file' => Kohana::debug_path($dir.$file)));
		}
	}

	/**
	 * Get a message from a file. Messages are arbitary strings that are stored
	 * in the messages/ directory and reference by a key. Translation is not
	 * performed on the returned values.
	 *
	 *     // Get "username" from messages/text.php
	 *     $username = Kohana::message('text', 'username');
	 *
	 * @uses  Arr::merge
	 * @uses  Arr::path
	 *
	 * @param   string  file name
	 * @param   string  key path to get
	 * @param   mixed   default value if the path does not exist
	 * @return  string  message string for the given path
	 * @return  array   complete message list, when no path is specified
	 */
	public static function message($file, $path = NULL, $default = NULL)
	{
		static $messages;

		if ( ! isset($messages[$file]))
		{
			// Create a new message list
			$messages[$file] = array();

			if ($files = Kohana::find_file('messages', $file))
			{
				foreach ($files as $f)
				{
					// Combine all the messages recursively
					$messages[$file] = Arr::merge($messages[$file], self::load($f));
				}
			}
		}

		if ($path === NULL)
		{
			// Return all of the messages
			return $messages[$file];
		}
		else
		{
			// Get a message using the path
			return Arr::path($messages[$file], $path, $default);
		}
	}

	/**
	 * PHP error handler, converts all errors into ErrorExceptions. This handler
	 * respects error_reporting settings.
	 *
	 * @throws  ErrorException
	 * @return  TRUE
	 */
	public static function error_handler($code, $error, $file = NULL, $line = NULL)
	{
		if ((error_reporting() & $code) !== 0)
		{
			// This error is not suppressed by current error reporting settings
			// Convert the error into an ErrorException
			throw new ErrorException($error, $code, 0, $file, $line);
		}

		// Do not execute the PHP error handler
		return TRUE;
	}

	/**
	 * Inline exception handler, displays the error message, source of the
	 * exception, and the stack trace of the error.
	 *
	 * @uses    Kohana::$php_errors
	 * @uses    Kohana::exception_text()
	 * @param   object   exception object
	 * @return  boolean
	 */
	public static function exception_handler(Exception $e)
	{
		try
		{
			// Get the exception information
			$type    = get_class($e);
			$code    = $e->getCode();
			$message = $e->getMessage();
			$file    = $e->getFile();
			$line    = $e->getLine();

			// Create a text version of the exception
			$error = self::exception_text($e);

			if (is_object(self::$log))
			{
				// Add this exception to the log
				self::$log->add(Kohana::ERROR, $error);
			}

			if (Kohana::$is_cli)
			{
				// Just display the text of the exception
				echo "\n{$error}\n";

				return TRUE;
			}

			// Get the exception backtrace
			$trace = $e->getTrace();

			if ($e instanceof ErrorException)
			{
				if (isset(self::$php_errors[$code]))
				{
					// Use the human-readable error name
					$code = self::$php_errors[$code];
				}

				if (version_compare(PHP_VERSION, '5.3', '<'))
				{
					// Workaround for a bug in ErrorException::getTrace() that exists in
					// all PHP 5.2 versions. @see http://bugs.php.net/bug.php?id=45895
					for ($i = count($trace) - 1; $i > 0; --$i)
					{
						if (isset($trace[$i - 1]['args']))
						{
							// Re-position the args
							$trace[$i]['args'] = $trace[$i - 1]['args'];

							// Remove the args
							unset($trace[$i - 1]['args']);
						}
					}
				}
			}

			if ( ! headers_sent())
			{
				// Make sure the proper content type is sent with a 500 status
				header('Content-Type: text/html; charset='.Kohana::$charset, TRUE, 500);
			}

			// Start an output buffer
			ob_start();

			// Include the exception HTML
			include self::find_file('views', 'kohana/error');

			// Display the contents of the output buffer
			echo ob_get_clean();

			return TRUE;
		}
		catch (Exception $e)
		{
			// Clean the output buffer if one exists
			ob_get_level() and ob_clean();

			// Display the exception text
			echo Kohana::exception_text($e), "\n";

			// Exit with an error status
			exit(1);
		}
	}

	/**
	 * Catches errors that are not caught by the error handler, such as E_PARSE.
	 *
	 * @uses    Kohana::exception_handler()
	 * @return  void
	 */
	public static function shutdown_handler()
	{
		try
		{
			if (self::$caching === TRUE AND self::$_files_changed === TRUE)
			{
				// Write the file path cache
				Kohana::cache('Kohana::find_file()', self::$_files);
			}
		}
		catch (Exception $e)
		{
			// Pass the exception to the handler
			Kohana::exception_handler($e);
		}

		if ($error = error_get_last())
		{
			// If an output buffer exists, clear it
			ob_get_level() and ob_clean();

			// Fake an exception for nice debugging
			Kohana::exception_handler(new ErrorException($error['message'], $error['type'], 0, $error['file'], $error['line']));
		}
	}

	/**
	 * Get a single line of text representing the exception:
	 *
	 * Error [ Code ]: Message ~ File [ Line ]
	 *
	 * @param   object  Exception
	 * @return  string
	 */
	public static function exception_text(Exception $e)
	{
		return sprintf('%s [ %s ]: %s ~ %s [ %d ]',
			get_class($e), $e->getCode(), strip_tags($e->getMessage()), Kohana::debug_path($e->getFile()), $e->getLine());
	}

	/**
	 * Returns an HTML string of debugging information about any number of
	 * variables, each wrapped in a <pre> tag:
	 *
	 *     // Displays the type and value of each variable
	 *     echo Kohana::debug($foo, $bar, $baz);
	 *
	 * @param   mixed   variable to debug
	 * @param   ...
	 * @return  string
	 */
	public static function debug()
	{
		if (func_num_args() === 0)
			return;

		// Get all passed variables
		$variables = func_get_args();

		$output = array();
		foreach ($variables as $var)
		{
			$output[] = self::_dump($var);
		}

		return '<pre class="debug">'.implode("\n", $output).'</pre>';
	}

	/**
	 * Returns an HTML string of information about a single variable.
	 *
	 * Borrows heavily on concepts from the Debug class of {@link http://nettephp.com/ Nette}.
	 *
	 * @param   mixed    variable to dump
	 * @return  string
	 */
	public static function dump($value)
	{
		return self::_dump($value);
	}

	private static function _dump( & $var, $level = 0)
	{
		if ($var === NULL)
		{
			return '<small>NULL</small>';
		}
		elseif (is_bool($var))
		{
			return '<small>bool</small> '.($var ? 'TRUE' : 'FALSE');
		}
		elseif (is_float($var))
		{
			return '<small>float</small> '.$var;
		}
		elseif (is_resource($var))
		{
			if (($type = get_resource_type($var)) === 'stream' AND $meta = stream_get_meta_data($var))
			{
				$meta = stream_get_meta_data($var);

				if (isset($meta['uri']))
				{
					$file = $meta['uri'];

					if (function_exists('stream_is_local'))
					{
						// Only exists on PHP >= 5.2.4
						if (stream_is_local($file))
						{
							$file = self::debug_path($file);
						}
					}

					return '<small>resource</small><span>('.$type.')</span> '.htmlspecialchars($file, ENT_NOQUOTES, self::$charset);
				}
			}
			else
			{
				return '<small>resource</small><span>('.$type.')</span>';
			}
		}
		elseif (is_string($var))
		{
			if (strlen($var) > 128)
			{
				// Encode the truncated string
				$str = htmlspecialchars(substr($var, 0, 128), ENT_NOQUOTES, self::$charset).'&nbsp;&hellip;';
			}
			else
			{
				// Encode the string
				$str = htmlspecialchars($var, ENT_NOQUOTES, self::$charset);
			}

			return '<small>string</small><span>('.strlen($var).')</span> "'.$str.'"';
		}
		elseif (is_array($var))
		{
			$output = array();

			// Indentation for this variable
			$space = str_repeat($s = '    ', $level);

			static $marker;

			if ($marker === NULL)
			{
				// Make a unique marker
				$marker = uniqid("\x00");
			}

			if (empty($var))
			{
				// Do nothing
			}
			elseif (isset($var[$marker]))
			{
				$output[] = "(\n$space$s*RECURSION*\n$space)";
			}
			elseif ($level < 5)
			{
				$output[] = "<span>(";

				$var[$marker] = TRUE;
				foreach ($var as $key => & $val)
				{
					if ($key === $marker) continue;
					if ( ! is_int($key))
					{
						$key = '"'.$key.'"';
					}

					$output[] = "$space$s$key => ".self::_dump($val, $level + 1);
				}
				unset($var[$marker]);

				$output[] = "$space)</span>";
			}
			else
			{
				// Depth too great
				$output[] = "(\n$space$s...\n$space)";
			}

			return '<small>array</small><span>('.count($var).')</span> '.implode("\n", $output);
		}
		elseif (is_object($var))
		{
			// Copy the object as an array
			$array = (array) $var;

			$output = array();

			// Indentation for this variable
			$space = str_repeat($s = '    ', $level);

			$hash = spl_object_hash($var);

			// Objects that are being dumped
			static $objects = array();

			if (empty($var))
			{
				// Do nothing
			}
			elseif (isset($objects[$hash]))
			{
				$output[] = "{\n$space$s*RECURSION*\n$space}";
			}
			elseif ($level < 5)
			{
				$output[] = "<code>{";

				$objects[$hash] = TRUE;
				foreach ($array as $key => & $val)
				{
					if ($key[0] === "\x00")
					{
						// Determine if the access is private or protected
						$access = '<small>'.($key[1] === '*' ? 'protected' : 'private').'</small>';

						// Remove the access level from the variable name
						$key = substr($key, strrpos($key, "\x00") + 1);
					}
					else
					{
						$access = '<small>public</small>';
					}

					$output[] = "$space$s$access $key => ".self::_dump($val, $level + 1);
				}
				unset($objects[$hash]);

				$output[] = "$space}</code>";
			}
			else
			{
				// Depth too great
				$output[] = "{\n$space$s...\n$space}";
			}

			return '<small>object</small> <span>'.get_class($var).'('.count($array).')</span> '.implode("\n", $output);
		}
		else
		{
			return '<small>'.gettype($var).'</small> '.htmlspecialchars(print_r($var, TRUE), ENT_NOQUOTES, self::$charset);
		}
	}

	/**
	 * Removes application, system, modpath, or docroot from a filename,
	 * replacing them with the plain text equivalents. Useful for debugging
	 * when you want to display a shorter path.
	 *
	 *     // Displays SYSPATH/classes/kohana.php
	 *     echo Kohana::debug_path(Kohana::find_file('classes', 'kohana'));
	 *
	 * @param   string  path to debug
	 * @return  string
	 */
	public static function debug_path($file)
	{
		if (strpos($file, APPPATH) === 0)
		{
			$file = 'APPPATH/'.substr($file, strlen(APPPATH));
		}
		elseif (strpos($file, SYSPATH) === 0)
		{
			$file = 'SYSPATH/'.substr($file, strlen(SYSPATH));
		}
		elseif (strpos($file, MODPATH) === 0)
		{
			$file = 'MODPATH/'.substr($file, strlen(MODPATH));
		}
		elseif (strpos($file, DOCROOT) === 0)
		{
			$file = 'DOCROOT/'.substr($file, strlen(DOCROOT));
		}

		return $file;
	}

	/**
	 * Returns an HTML string, highlighting a specific line of a file, with some
	 * number of lines padded above and below.
	 *
	 *     // Highlights the current line of the current file
	 *     echo Kohana::debug_source(__FILE__, __LINE__);
	 *
	 * @param   string   file to open
	 * @param   integer  line number to highlight
	 * @param   integer  number of padding lines
	 * @return  string
	 */
	public static function debug_source($file, $line_number, $padding = 5)
	{
		// Open the file and set the line position
		$file = fopen($file, 'r');
		$line = 0;

		// Set the reading range
		$range = array('start' => $line_number - $padding, 'end' => $line_number + $padding);

		// Set the zero-padding amount for line numbers
		$format = '% '.strlen($range['end']).'d';

		$source = '';
		while (($row = fgets($file)) !== FALSE)
		{
			// Increment the line number
			if (++$line > $range['end'])
				break;

			if ($line >= $range['start'])
			{
				// Make the row safe for output
				$row = htmlspecialchars($row, ENT_NOQUOTES, self::$charset);

				// Trim whitespace and sanitize the row
				$row = '<span class="number">'.sprintf($format, $line).'</span> '.$row;

				if ($line === $line_number)
				{
					// Apply highlighting to this row
					$row = '<span class="line highlight">'.$row.'</span>';
				}
				else
				{
					$row = '<span class="line">'.$row.'</span>';
				}

				// Add to the captured source
				$source .= $row;
			}
		}

		// Close the file
		fclose($file);

		return '<pre class="source"><code>'.$source.'</code></pre>';
	}

	/**
	 * Returns an array of HTML strings that represent each step in the backtrace.
	 *
	 *     // Displays the entire current backtrace
	 *     echo implode('<br/>', Kohana::trace());
	 *
	 * @param   string  path to debug
	 * @return  string
	 */
	public static function trace(array $trace = NULL)
	{
		if ($trace === NULL)
		{
			// Start a new trace
			$trace = debug_backtrace();
		}

		// Non-standard function calls
		$statements = array('include', 'include_once', 'require', 'require_once');

		$output = array();
		foreach ($trace as $step)
		{
			if ( ! isset($step['function']))
			{
				// Invalid trace step
				continue;
			}

			if (isset($step['file']) AND isset($step['line']))
			{
				// Include the source of this step
				$source = self::debug_source($step['file'], $step['line']);
			}

			if (isset($step['file']))
			{
				$file = $step['file'];

				if (isset($step['line']))
				{
					$line = $step['line'];
				}
			}

			// function()
			$function = $step['function'];

			if (in_array($step['function'], $statements))
			{
				if (empty($step['args']))
				{
					// No arguments
					$args = array();
				}
				else
				{
					// Sanitize the file path
					$args = array($step['args'][0]);
				}
			}
			elseif (isset($step['args']))
			{
				if (isset($step['class']))
				{
					if (method_exists($step['class'], $step['function']))
					{
						$reflection = new ReflectionMethod($step['class'], $step['function']);
					}
					else
					{
						$reflection = new ReflectionMethod($step['class'], '__call');
					}
				}
				else
				{
					$reflection = new ReflectionFunction($step['function']);
				}

				// Get the function parameters
				$params = $reflection->getParameters();

				$args = array();

				foreach ($step['args'] as $i => $arg)
				{
					if (isset($params[$i]))
					{
						// Assign the argument by the parameter name
						$args[$params[$i]->name] = $arg;
					}
					else
					{
						// Assign the argument by number
						$args[$i] = $arg;
					}
				}
			}

			if (isset($step['class']))
			{
				// Class->method() or Class::method()
				$function = $step['class'].$step['type'].$step['function'];
			}

			$output[] = array(
				'function' => $function,
				'args'     => isset($args)   ? $args : NULL,
				'file'     => isset($file)   ? $file : NULL,
				'line'     => isset($line)   ? $line : NULL,
				'source'   => isset($source) ? $source : NULL,
			);

			unset($function, $args, $file, $line, $source);
		}

		return $output;
	}

	private function __construct()
	{
		// This is a static class
	}

} // End Kohana