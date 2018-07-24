<?php
/**
 #@ This class provide tools to stop concurrent execution
 #@ of a script even in a rare case of parallel init of the
 #@ same script (I know it seems crazy, but it happens when
 #@ multiple crond services run on a server)
 #@
 #@ Notes: PHP < 5.6.1 does not support any method for querying
 #@ the state of a semaphore in a non-blocking manner. So we
 #@ have to trick it by serializing semaphore using SHM vars.
 #@
 #@
 #@ Author: Abhilash Pillai (20/JUN/2018)
 #@ Modified:
 **/


$concStopperhndTokSem = '';
$concStopperhndTokSHM = '';


class concStopper {


	# @variable: $ModuleName: (Use constructor params to set this)
	#          This is the Name of your module.
	#          (follow the naming convention of a standard variable)
	# Summary:
	#          whenever creating an object of this class,
	#          you should provide the unique name of the
	#          module which needs concStopper. This will help
	#          in identifying duplicates of your module, rather
	#          than stopping any other module which is using
	#          this class.
	private $ModuleName;



	# @variable: $saltDir: (Use constructor params to set this)
	#          Path of a world writable directory
	# Summary:
	#          This class generates SystemV-IPC ID by creating a
	#          runtime file and calculating its IPC. This will be
	#          the path where these files will be created.
	private $saltDir;



	private $lastErr = '';
	private $debugMode;
	private $IGotLock = FALSE;


	public function __construct($ModuleName, $saltDir='/tmp/', $debugMode = FALSE){

		$this->ModuleName = trim($ModuleName);
		$this->saltDir = trim($saltDir);
		$this->debugMode = $debugMode;
	}

	public function __destruct() {
		if($this->IGotLock) {
			$this->releaseLock();
		}
	}


	public function aquireLock () {

		global $concStopperhndTokSem, $concStopperhndTokSHM;

		$ipcID = $this->_getSystemV_IPCID('TOKEN');
		$this->_echo("IPC ID of Token: $ipcID");
		if(0 == $ipcID) {
			return NULL; #Problem! Requester should check the last error
		}
		$concStopperhndTokSem = sem_get($ipcID, 1, 0666, 1);


		$ipcID = $this->_getSystemV_IPCID('SHM');
		$this->_echo("IPC ID of SHM: $ipcID");
		if(0 == $ipcID) {
			return NULL; #Problem! Requester should check the last error
		}
		$concStopperhndTokSHM = shm_attach($ipcID, 10000, 0666);


		$this->_echo("Acquiring token sem");
		sem_acquire($concStopperhndTokSem);
		
		$LockedPID = @shm_get_var($concStopperhndTokSHM, 6);
		$this->_echo("Value of SHM: $LockedPID");
		
		$isRunning = FALSE;
		if(!empty($LockedPID)) {
			if(!$this->_isProcessStale($LockedPID)) $isRunning = TRUE;
		} 

		if($isRunning) {
			$this->_echo("No Lock. Releasing token sem");
			sem_release($concStopperhndTokSem);
			return FALSE; #Process is already locked
		}
		
		$this->IGotLock = TRUE;
		$this->_echo("Got it.");
		$tmp = shm_put_var($concStopperhndTokSHM, 6, getmypid());
		$tmp = shm_get_var($concStopperhndTokSHM, 6);
		$this->_echo("Value of SHM: $tmp");
		$this->_echo("Releasing token sem");
		sem_release($concStopperhndTokSem);

		return TRUE;
	}


	public function releaseLock () {

		global $concStopperhndTokSem, $concStopperhndTokSHM;

		$this->IGotLock = FALSE;
		
		sem_acquire($concStopperhndTokSem);

		#$tmp = shm_put_var($concStopperhndTokSHM, 6, false);
		$tmp = shm_remove ($concStopperhndTokSHM);
		$tmp = shm_detach($concStopperhndTokSHM);

		sem_release($concStopperhndTokSem);
	}


	public function getLastError() {
		return $this->lastErr;
	}




	private function _getSystemV_IPCID ($ForWhichProc = 'TOKEN') {

		$saltDir = realpath(rtrim($this->saltDir,DIRECTORY_SEPARATOR)).DIRECTORY_SEPARATOR;

		switch ($ForWhichProc) {
			case 'RESOURCE':
				$saltFile = "{$saltDir}{$this->ModuleName}.semaphore.res.salt";
				$saltContent = $this->_getSaltContent('RESOURCE');
			break;
			case 'SHM':
				$saltFile = "{$saltDir}{$this->ModuleName}.semaphore.shm.salt";
				$saltContent = $this->_getSaltContent('SHM');
			break;
			case 'TOKEN':
			default:
				$saltFile = "{$saltDir}{$this->ModuleName}.semaphore.tok.salt";
				$saltContent = $this->_getSaltContent('TOKEN');
			break;
		}

		@clearstatcache();
		if(!file_exists($saltFile)) {
			if(FALSE === $this->_createFile($saltFile, $saltContent)) {
				$this->_setError("Unable to create/delete previous salt file: {$saltFile}");
				return 0;
			}
		}

		$SystemVipcID = ftok($saltFile, 'r');

		#$retVoid = $this->_deleteFile($saltFile);

		return $SystemVipcID;
	}

	private function _setError($strMsg) {
		$this->lastErr = $strMsg;
	}

	private function _deleteFile ($fileName) {

		@clearstatcache();
		if(file_exists($fileName)) {
			if(FALSE === @unlink($fileName)) return FALSE;
		}
		return TRUE;
	}

	private function _createFile($filePath, $content) {

		@clearstatcache();
		if(FALSE === $this->_deleteFile($filePath)) return FALSE;
		if(FALSE === file_put_contents($filePath, $content)) return FALSE;
		return TRUE;
	}

	private function _isProcessStale($PID) {

        $pids = explode("\n", trim(`ps -e | awk '{print $1}'`));

        if( in_array( $PID, $pids ) ) return false;
        else return true;
	}
	
	private function _getSaltContent ($ForWhichProc = 'TOKEN') {

		$arrSaltContents = array();

		$arrSaltContents['TOKEN'] =  'KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqDQojIyMgRE8gTk9UIEVESVQgT1IgREVMRVRFIFRISVMgRklMRSAjIyMNCioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKg0KLS0gVGhpcyBmaWxlIGlzIHVzZWQgZm9yIGNyZWF0aW5nIGlub2RlIA0KLS0gZm9yIHNlbWFwaG9yZSBrZXkuIEFueSBjaGFuZ2UgaW4gdGhlIA0KLS0gY29udGVudHMgd2lsbCBjaGFuZ2UgdGhlIHNlbWFwaG9yZS4NCi0tIENPTkNVUlJFTkNZIFNUT1BQRVIgLSBUT0tFTiBGSUxFDQpbRU9GIFRPS0VOXQ==';

		$arrSaltContents['RESOURCE'] =  'KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqDQojIyMgRE8gTk9UIEVESVQgT1IgREVMRVRFIFRISVMgRklMRSAjIyMNCioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKg0KLS0gVGhpcyBmaWxlIGlzIHVzZWQgZm9yIGNyZWF0aW5nIGlub2RlIA0KLS0gZm9yIHNlbWFwaG9yZSBrZXkuIEFueSBjaGFuZ2UgaW4gdGhlIA0KLS0gY29udGVudHMgd2lsbCBjaGFuZ2UgdGhlIHNlbWFwaG9yZS4NCi0tIENPTkNVUlJFTkNZIFNUT1BQRVIgLSBSRVNPVVJDRSBGSUxFDQpbRU9GXQ==';

		$arrSaltContents['SHM'] =  'KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqDQojIyMgRE8gTk9UIEVESVQgT1IgREVMRVRFIFRISVMgRklMRSAjIyMNCioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKg0KLS0gVGhpcyBmaWxlIGlzIHVzZWQgZm9yIGNyZWF0aW5nIGlub2RlIA0KLS0gZm9yIHNlbWFwaG9yZSBrZXkuIEFueSBjaGFuZ2UgaW4gdGhlIA0KLS0gY29udGVudHMgd2lsbCBjaGFuZ2UgdGhlIHNlbWFwaG9yZS4NCi0tIENPTkNVUlJFTkNZIFNUT1BQRVIgLSBTSE0gRklMRQ0KW0VPRiBTSE1d';

		$arrSaltContents[0] =  'KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqDQojIyMgRE8gTk9UIEVESVQgT1IgREVMRVRFIFRISVMgRklMRSAjIyMNCioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKg0KLS0gVGhpcyBmaWxlIGlzIHVzZWQgZm9yIGNyZWF0aW5nIGlub2RlIA0KLS0gZm9yIHNlbWFwaG9yZSBrZXkuIEFueSBjaGFuZ2UgaW4gdGhlIA0KLS0gY29udGVudHMgd2lsbCBjaGFuZ2UgdGhlIHNlbWFwaG9yZS4NCltFT0YgVU5LTk9XTl0=';

		$content = $this->ModuleName;
		if(isset($arrSaltContents[$ForWhichProc])) $content.= base64_decode($arrSaltContents[$ForWhichProc]);
		else $content.= base64_decode($arrSaltContents[0]);

		return $content;
	}

	private function _echo ($strMsg) {
		if(!$this->debugMode) return '';
		echo microtime().": $strMsg \n";
	}

} #end class

