<?php

//  Version
define('VERSION', '1.7.4');
//  Configuration
if (is_file('config.php')) {
  require_once('config.php');
}

// Install
if (!defined('DIR_APPLICATION')) {
  header('Location: install/index.php');
  exit;
}

// Startup
require_once(DIR_SYSTEM . 'startup.php');
start('documentov');
