<?php

ini_set('display_errors', 'On');

abstract class DevelInstall_InstallProfileable
{
	/**
	 * Install profile
	 * 
	 * @var DevelInstall_InstallProfile
	 */
	protected $installProfile;
	
	public function setInstallProfile(DevelInstall_InstallProfile $installProfile)
	{
		$this->installProfile = $installProfile;
	}
	
	public function getInstallProfile()
	{
		return $this->installProfile;
	}
}

class DevelInstall_InstallProfile
{
	protected $options = array();
	
	public function __construct($filename = 'devel_install_profile.ini')
	{
		if (file_exists($filename)) {
			$this->options = parse_ini_file($filename, true);
		}
	}
	
	public function getOption($section, $key)
	{
		if (! isset($this->options[$section][$key])) {
			return false;
		}
		
		return $this->options[$section][$key];
	}
}

abstract class DevelInstall_FormParam extends DevelInstall_InstallProfileable
{
	protected $key;
	protected $type;
	protected $value;
	
	abstract public function getSection();
	
	public function __construct($key, $type, $value, array $options=null, $description=null)
	{
		$this->key = $key;
		$this->type = $type;
		$this->value = $value;
		$this->options = $options;
		$this->description = $description;
	}
	
	public function getKey()
	{
		return $this->key;
	}
	
	public function geType()
	{
		return $this->key;
	}
	
	public function getValue()
	{
		/*
		 * Get from request
		 */
		if (isset($_REQUEST[$this->getKey()])) {
			return $_REQUEST[$this->getKey()];
		}
		
		/*
		 * Get from install profile
		 */
		if ($this->getInstallProfile()->getOption($this->getSection(), $this->getKey())) {
			return $this->getInstallProfile()->getOption($this->getSection(), $this->getKey());
		}
		
		/*
		 * Get default
		 */
		return $this->value;
	}
	
	public function hasOptions()
	{
		return is_array($this->options);
	}
	
	public function getOptions()
	{
		return $this->options;
	}
	
	public function getLabel()
	{
		$label = $this->getKey();
		$label = str_replace('_', ' ', $label);
		$label = ucwords($label);
		
		return $label;
	}
	
	public function hasDescription()
	{
		return (bool) $this->getDescription();
	}
	
	public function getDescription()
	{
		return $this->description;
	}
}

class DevelInstall_FormParam_Core extends DevelInstall_FormParam {
	public function getSection() { return 'core'; }
}

class DevelInstall_FormParam_Filesystem extends DevelInstall_FormParam {
	public function getSection() { return 'fs'; }
}

class DevelInstall_Filesystem extends DevelInstall_InstallProfileable
{
	public function getParams()
	{
		$params = array(
			new DevelInstall_FormParam_Filesystem('uid', 'text', ''),
			new DevelInstall_FormParam_Filesystem('gid', 'text', ''),
			new DevelInstall_FormParam_Filesystem('dirmod', 'mod', '0755'),
			new DevelInstall_FormParam_Filesystem('filemod', 'mod', '0644'),
			new DevelInstall_FormParam_Filesystem('php', 'path', '/usr/bin/php')
		);
		
		foreach($params as $param) {
			$param->setInstallProfile($this->getInstallProfile());
		}
		
		return $params;
	}
	
	public function getParam($key)
	{
		foreach($this->getParams() as $param) {
			if ($param->getKey() == $key) {
				return $param;
			}
		}
		
		return false;
	}
	
	public function chown()
	{
		$uid = $this->getParam('uid')->getValue();
		$gid = $this->getParam('gid')->getValue();
		
		$shellUid = fileowner('./shell');
		$shellGid = filegroup('./shell');
		
		exec("chown -R $uid:$gid ./");
		exec("chown -R $shellUid:$shellGid ./shell");
	}
	
	public function chmod()
	{
		$dirMod = $this->getParam('dirmod')->getValue();
		$fileMod = $this->getParam('filemod')->getValue();
		
		$d = new RecursiveDirectoryIterator('./');
		
		try {
			foreach (new RecursiveIteratorIterator($d, 1) as $path) {
				if ($path->isDir())
					exec("chmod $dirMod $path");
				elseif(is_file($path))
					exec("chmod $fileMod $path");
			}
		}
		catch(UnexpectedValueException $e) {
			//
		}
	}
	
	public function checkRoot()
	{
		$errors = array();
		
		$targetMod = $this->getParam('dirmod')->getValue();
		$targetUid = $this->getParam('uid')->getValue();
		$targetGid = $this->getParam('gid')->getValue();
		
		@chown('./', $targetUid);
		@chgrp('./', $targetGid);
		@chmod('./', $targetMod);
		
		$uid = fileowner('./');
		$gid = filegroup('./');
		$mod = substr(decoct( fileperms('./') ), 1);
		
		$user = posix_getpwuid($uid);
		$group = posix_getpwuid($gid);
		
		if ($targetUid AND $user['name'] != $targetUid) {
			$errors[] = "chown $targetUid .";
		}
		
		if ($targetGid AND $group['name'] != $targetGid) {
			$errors[] = "chgrp $targetGid .";
		}
		
		if ($targetMod AND $mod != $targetMod) {
			$errors[] = "chmod $targetMod .";
		}
		
		if (count($errors)) {
			echo "<pre>\n\n";
			echo "Run the following commands to setup the install script environment:\n\n";
			echo "cd " . dirname(__FILE__) . "\n";
			echo join("\n", $errors);
			die();
		}
		
		return true;
	}
}

class DevelInstall_Magento extends DevelInstall_InstallProfileable
{
	protected $filepath;
	protected $downloadUrl;
	protected $status = null;
	protected $installResponse = null;
	protected $encryptionKey = null;
	
	/**
	 * Filesystem
	 * 
	 * @var DevelInstall_Filesystem
	 */
	protected $filesystem;

	public function __construct()
	{

	}
	
	public function setFilesystem(DevelInstall_Filesystem $filesystem)
	{
		$this->filesystem = $filesystem;
		$this->filesystem->setInstallProfile($this->getInstallProfile());
	}
	
	public function getFilesystem()
	{
		return $this->filesystem;
	}
	
	public function setStatus($status)
	{
		$this->status = (bool) $status;
	}
	
	public function getStatus()
	{
		return is_bool($this->status) ? (bool) $this->status : null;
	}
	
	public function setEncryptionKey($encryptionKey)
	{
		$this->encryptionKey = $encryptionKey;
	}
	
	public function getEncryptionKey()
	{
		return $this->encryptionKey;
	}
	
	public function setInstallResponse($installResponse)
	{
		$this->installResponse = $installResponse;
	}
	
	public function getInstallResponse()
	{
		return $this->installResponse;
	}
	
	public function autoRun()
	{
		$this->prepare();
		$this->download();
		$this->expand();
		$this->getFilesystem()->chmod();
		$this->getFilesystem()->chown();
		$this->install();
	}
	
	public function prepare()
	{
		//$user = posix_getpwuid(posix_getuid());
		//print_r($user); die();
		$dir = dirname(__FILE__);
		
		chdir($dir);
		
		if (! is_writeable($dir)) {
			die('Directory not writeable!');
		}
		
		$this->downloadUrl = $this->getDownloadUrl($this->getParam('version')->getValue());
		$this->filepath = basename($this->downloadUrl);
	}
	
	public function download()
	{
		if (file_exists($this->filepath)) {
			die('File exists');
		}
		
		exec('wget ' . $this->downloadUrl);
	}

	public function expand()
	{
		exec("tar xvzf " . $this->filepath);
		exec("mv magento/* .");
		exec("mv magento/.htaccess* .");
		rmdir('magento');
		unlink($this->filepath);
	}
	
	public function install()
	{	
		exec($this->getInstallCmd(), $output);
		
		$return = join("\n", $output);
		
		$this->setInstallResponse($return);
		
		if (preg_match('/^SUCCESS: (\d*)$/mis', $return, $matches)) {
			$this->setStatus(true);
			$this->setEncryptionKey($matches[1]);
		} else {
			$this->setStatus(false);
		}
	}
	
	public function getInstallCmd()
	{
		$installCmd = $this->getFilesystem()->getParam('php')->getValue();
		
		$installCmd .= ' -f install.php --';

		foreach($this->getParams() as $param)
		{
			$installCmd .= ' --' . $param->getKey() . ' "' . $param->getValue() . '"';
		}
		
		return $installCmd;
	}
	
	public function getParams()
	{
		$url = $_SERVER['HTTP_HOST'] . str_replace(basename(__FILE__), '', $_SERVER['REQUEST_URI']);
		
		$params = array(
			new DevelInstall_FormParam_Core('version', 'version', '1.5.1.0'),
			new DevelInstall_FormParam_Core('license_agreement_accepted', 'yesorno', 'yes', array('yes', 'no')),
			new DevelInstall_FormParam_Core('locale', 'locale', 'en_US'),
			new DevelInstall_FormParam_Core('timezone', 'timezone', 'America/Los_Angeles'),
			new DevelInstall_FormParam_Core('default_currency', 'currency', 'USD'),
			new DevelInstall_FormParam_Core('db_host', 'ip', '127.0.0.1'),
			new DevelInstall_FormParam_Core('db_name', 'text', ''),
			new DevelInstall_FormParam_Core('db_user', 'text', ''),
			new DevelInstall_FormParam_Core('db_pass', 'text', ''),
			new DevelInstall_FormParam_Core('db_prefix', 'text', ''),
			new DevelInstall_FormParam_Core('session_save', 'select', 'db', array('files', 'db')),
			new DevelInstall_FormParam_Core('admin_frontname', 'text', 'admin'),
			new DevelInstall_FormParam_Core('url', 'url', 'http://' . $url),
			new DevelInstall_FormParam_Core('skip_url_validation', 'yesorno', 'no', array('yes', 'no')),
			new DevelInstall_FormParam_Core('use_rewrites', 'yesorno', 'yes', array('yes', 'no')),
			new DevelInstall_FormParam_Core('use_secure', 'yesorno', 'no', array('yes', 'no')),
			new DevelInstall_FormParam_Core('secure_base_url', 'url', 'https://' . $url),
			new DevelInstall_FormParam_Core('use_secure_admin', 'yesorno', 'no', array('yes', 'no')),
			new DevelInstall_FormParam_Core('enable_charts', 'yesorno', 'yes', array('yes', 'no')),
			new DevelInstall_FormParam_Core('admin_lastname', 'text', ''),
			new DevelInstall_FormParam_Core('admin_firstname', 'text', ''),
			new DevelInstall_FormParam_Core('admin_email', 'email', ''),
			new DevelInstall_FormParam_Core('admin_username', 'text', ''),
			new DevelInstall_FormParam_Core('admin_password', 'text', ''),
			new DevelInstall_FormParam_Core('encryption_key', 'text', '')
		);
		
		foreach($params as $param) {
			$param->setInstallProfile($this->getInstallProfile());
		}
		
		return $params;
	}
	
	public function getParam($key)
	{
		foreach($this->getParams() as $param) {
			if ($param->getKey() == $key) {
				return $param;
			}
		}
		
		return false;
	}
	
	/* -----| STATIC |----- */
	
	public static function getDownloadUrl($version)
	{
		return 'http://www.magentocommerce.com/downloads/assets/'
			. $version . '/magento-' . $version . '.tar.gz';
	}
}

/* Main */

$install = isset($_POST['install']) ? $_POST['install'] : false;

$installMage = new DevelInstall_Magento();
$installMage->setInstallProfile(new DevelInstall_InstallProfile());
$installMage->setFilesystem(new DevelInstall_Filesystem());

$installMage->getFilesystem()->checkRoot();

if ($install) {
	$page = new stdClass();
	$page->name = 'install';
	$page->backBtn = true;
		
	$installMage->autoRun();
} else {
	$page = new stdClass();
	$page->name = 'index';
	$page->backBtn = false;
}

?>
<!doctype html>
<html>
<head>
	<link rel="stylesheet" href="http://code.jquery.com/mobile/1.0a4.1/jquery.mobile-1.0a4.1.min.css" />
	<script src="http://code.jquery.com/jquery-1.6.1.min.js"></script>
	<script src="http://code.jquery.com/mobile/1.0a4.1/jquery.mobile-1.0a4.1.min.js"></script>
	
	<script>
		$(document).ready(function(){
			$("#devel-install-form").submit(function(){
				
				/* Form validation should go here */
				
				return true;
			});
		});
	</script>
</head>
<body>
	<?php function renderFormParams($params) { ?>
					<?php foreach ($params as $param) : ?>
					<div data-role="fieldcontain">
						<label for="<?php echo $param->getKey() ?>"><?php echo $param->getLabel() ?></label>
						
						<?php if ($param->hasOptions()) : ?>
						<select name="<?php echo $param->getKey() ?>" id="<?php echo $param->getKey() ?>" size="1">
							<?php foreach($param->getOptions() as $option) : ?>
							<option
								<?php echo ($option == $param->getValue()) ? 'selected' : '' ?>
								value="<?php echo $option ?>">
								<?php echo $option ?>
							</option>
							<?php endforeach ?>
						</select>
						<?php else: ?>
						<input type="text" name="<?php echo $param->getKey() ?>" id="<?php echo $param->getKey() ?>" value="<?php echo $param->getValue() ?>" />
						<?php endif ?>
						
						<?php if ($param->hasDescription()) : ?>
						<p><?php echo $params->getDescription() ?></p>
						<?php endif ?>
					</div>
					<?php endforeach ?>
	<?php } ?>


	<div data-role="page" role="main">
		<div
			data-role="header"
			<?php if (! $page->backBtn) : ?>data-backbtn="false"<?php endif ?>
			data-position="fixed">
			<h1>Magento Devel Install</h1>
		</div>
		
		<div data-role="content">
		<?php switch($page->name) : default: ?>
			<?php case 'index': ?>
			<form id="devel-install-form" method="POST" action="<?php echo basename(__FILE__) ?>">
<!--				<div data-role="collapsible" data-collapsed="false">-->
<!--					<h3>Magento version</h3>-->
<!--					<div data-role="fieldcontain">-->
<!--						<label for="version">Version</label>-->
<!--						<input type="text" name="version" id="version" value="<?php echo $installMage->getInstallProfile()->getOption('core', 'version') ?>" />-->
<!--						<p>Enter the version number you want to install, eg. 1.4.2.0 or 1.5.1.0, etc...</p>-->
<!--					</div>	-->
<!--				</div>-->
				<div data-role="collapsible" data-collapsed="true">
					<h3>Filesystem params</h3>
					<?php renderFormParams($installMage->getFilesystem()->getParams()) ?>
				</div>
				<div data-role="collapsible" data-collapsed="true">
					<h3>Magento params</h3>
					<?php renderFormParams($installMage->getParams()) ?>
				</div>
				
				<div data-role="fieldcontain">
					<input type="submit" value="Submit" />
				</div>
				
				<input type="hidden" name="install" value="install" />
			</form>
			<?php break; ?>
			<?php case 'install': ?>
			
				<?php if ($installMage->getStatus()) : ?>
					<p>Install successful!</p>
					<p>Encryption key: <em><?php echo $installMage->getEncryptionKey() ?></em></p>
				<?php else :?>
					<p>Install failed!</p>
				<?php endif ?>
				
				<hr />
				
				<textarea>
					<?php echo $installMage->getInstallResponse() ?>
				</textarea>
				
				<textarea>
					<?php echo $installMage->getInstallCmd() ?>
				</textarea>
			
			<?php break; ?>
		<?php endswitch; ?>
		</div>
		
		<div data-role="footer" data-position="fixed">
			<a href="http://www.dhmedia.com.au/?magento-devel-install">&copy; 2011 &mdash; Doghouse Media</a>
		</div>
	</div>
</body>
</html>