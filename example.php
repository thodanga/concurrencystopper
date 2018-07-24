<?php


	require(realpath(dirname(__FILE__)).'/conc_stopper.class.php');
	
	echo "Trying to start new instance having PID: ".getmypid()."\n";
	echo "Am I a clone? \n";

	
	$objCloneCheck = new concStopper('FACT', '/tmp/', true);
	$LockAquired = $objCloneCheck->aquireLock(); #try to acquire the lock
	
	
	if( ! $LockAquired ) {
		die("Ooops I am a clone!!! Process terminating \n");
	}
	
	$RunThisScriptForSec = 20;
	
	echo "Yeah I am original. Let me run for {$RunThisScriptForSec} seconds\n";

	$cnt = 1;
	while ($cnt <= $RunThisScriptForSec) {
		echo ".";
		$cnt++;
		sleep(1);
	}
	echo "\n";
	echo "Finished execution. Bye. \n";

	
	$objCloneCheck->releaseLock(); #release the lock


	