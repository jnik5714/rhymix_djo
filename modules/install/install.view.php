<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  installView
 * @author NAVER (developers@xpressengine.com)
 * @brief View class of install module
 */
class installView extends install
{
	public static $checkEnv = false;
	public static $rewriteCheckFilePath = 'files/cache/tmpRewriteCheck.txt';
	public static $rewriteCheckString = '';

	/**
	 * @brief Initialization
	 */
	function init()
	{
		// Stop if already installed.
		if (Context::isInstalled())
		{
			return $this->stop('msg_already_installed');
		}
		
		// Set the browser title.
		Context::setBrowserTitle(Context::getLang('introduce_title'));
		
		// Specify the template path.
		$this->setTemplatePath($this->module_path.'tpl');
		
		// Check the environment.
		$oInstallController = getController('install');
		self::$checkEnv = $oInstallController->checkInstallEnv();
		if (self::$checkEnv)
		{
			$oInstallController->makeDefaultDirectory();
		}
	}

	/**
	 * @brief Index page
	 */
	function dispInstallIndex()
	{
		// If there is an autoinstall config file, use it.
		if (file_exists(RX_BASEDIR . 'config/install.config.php'))
		{
			include RX_BASEDIR . 'config/install.config.php';
			
			if (isset($install_config) && is_array($install_config))
			{
				$oInstallController = getController('install');
				$output = $oInstallController->procInstall($install_config);
				if (!$output->toBool())
				{
					return $output;
				}
				else
				{
					header("location: ./");
					exit;
				}
			}
		}
		
		// Otherwise, display the license agreement screen.
		Context::set('lang_type', Context::getLangType());
		$this->setTemplateFile('license_agreement');
	}

	/**
	 * @brief Display messages about installation environment
	 */
	function dispInstallCheckEnv()
	{
		// Create a temporary file for mod_rewrite check.
		self::$rewriteCheckString = Password::createSecureSalt(32);
		FileHandler::writeFile(_XE_PATH_ . self::$rewriteCheckFilePath, self::$rewriteCheckString);;
		
		// Check if the web server is nginx.
		Context::set('use_nginx', stripos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false);
		$this->setTemplateFile('check_env');
	}

	/**
	 * @brief Configure the database
	 */
	function dispInstallDBConfig()
	{
		// Display check_env if it is not installable
		if(!self::$checkEnv)
		{
			return $this->dispInstallCheckEnv();
		}
		
		// Delete mod_rewrite check file
		FileHandler::removeFile(_XE_PATH_ . self::$rewriteCheckFilePath);
		
		// Save mod_rewrite check status.
		if(Context::get('rewrite') === 'Y')
		{
			Context::set('use_rewrite', $_SESSION['use_rewrite'] = 'Y');
		}
		
		// FTP config is disabled in Rhymix.
		/*
		if(ini_get('safe_mode') && !Context::isFTPRegisted())
		{
			Context::set('progressMenu', '3');
			Context::set('server_ip_address', $_SERVER['SERVER_ADDR']);
			Context::set('server_ftp_user', get_current_user());
			$this->setTemplateFile('ftp');
			return;
		}
		*/
		
		$defaultDatabase = 'mysqli_innodb';
		$disableList = DB::getDisableList();
		if(is_array($disableList))
		{
			foreach($disableList as $key => $value)
			{
				if($value->db_type == $defaultDatabase)
				{
					$defaultDatabase = 'mysqli';
					break;
				}
			}
		}
		Context::set('defaultDatabase', $defaultDatabase);
		
		Context::set('progressMenu', '4');
		Context::set('error_return_url', getNotEncodedUrl('', 'act', Context::get('act'), 'db_type', Context::get('db_type')));
		$this->setTemplateFile('db_config');
	}

	/**
	 * @brief Display a screen to enter DB and administrator's information
	 */
	function dispInstallOtherConfig()
	{
		// Display check_env if not installable
		if(!self::$checkEnv)
		{
			return $this->dispInstallCheckEnv();
		}
		
		Context::set('use_rewrite', $_SESSION['use_rewrite']);
		Context::set('use_ssl', RX_SSL ? 'always' : 'none');
		Context::set('time_zone', $GLOBALS['time_zone']);
		$this->setTemplateFile('other_config');
	}
}
/* End of file install.view.php */
/* Location: ./modules/install/install.view.php */
