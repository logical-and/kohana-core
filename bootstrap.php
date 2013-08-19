<?php

// Required by a lot of scripts (<?php defined('SYSPATH') OR die('No direct script access.');)
define('SYSPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Other constants, obviously it can be overridden
if (! defined('APPPATH')) define('APPPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (! defined('EXT')) define('EXT', '.php');

// Config reader
Kohana::$config = new Kohana_Config;
Kohana::$config->attach(new Kohana_Config_File);

// Imitate request, to make possible use user_agent, accept_lang etc
// Used only if in environment doesn't used another Request class (collision)
if (! class_exists('Request', FALSE)) Request::factory();