<?php

if (php_sapi_name() !== 'cli') die('only executable from CLI');

define('BASE', dirname(__FILE__));
require_once BASE.'/settings.php';
require_once BASE.'/vendor/autoload.php';

$soap_options = [];
foreach ($settings['soap_hosts'] as $host => $options) {
	$context = stream_context_create(['ssl' => $options['tls'] ?? []]);
	$soap_options[$host] = [
		'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
		'location' => $options['address'].'/remote/',
		'uri' => 'urn:halon',
		'login' => $options['username'],
		'password' => $options['password'],
		'stream_context' => $context
	];
}

if (empty($soap_options))
	die("Couldn't find any hosts.");

$loader = new Twig_Loader_Filesystem(BASE.'/templates');
$twig = new Twig_Environment($loader);

$q = $dbh->prepare('SELECT * FROM release_sender WHERE found = -1;');
$q->execute();
while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
	sleep(3);
	$host_options = $soap_options[$row['node']];
	if (!$host_options) {
		echo "Invalid node\n";
		continue;
	}
	$id = $row['msgid'];
	echo "Fetching $id from ".$host_options['location']."\n";
	try {
		$client = new SoapClient($host_options['location'].'?wsdl', $host_options);
		$items = $client->mailQueue(array('filter' => 'messageid='.$id.' quarantine='.$settings['quarantine_short'], 'offset' => '0', 'limit' => 50));
	} catch (Exception $e) {
		echo $e->getMessage();
		continue;
	}
	if (!isset($items->result->item) || count($items->result->item) < 1) {
		echo "Email not found\n";
		$q2 = $dbh->prepare('UPDATE release_sender SET found=0 WHERE id = :id;');
		$q2->execute([':id' => $row['id']]);
		continue;
	}
	$mail = $items->result->item[0];
	$rpdscore = -1;
	$rpdrefid = '';
	if (isset($mail->msgscore->item))
		foreach ($mail->msgscore->item as $score)
			if ($score->first == 3)
				list ($rpdscore, $rpdrefid) = explode('|', $score->second);
	$q2 = $dbh->prepare('UPDATE release_sender SET found=1,msgfrom=:msgfrom,msgsubject=:msgsubject,msgrpdscore=:msgrpdscore,msgrpdrefid=:msgrpdrefid WHERE id = :id;');
	$q2->execute([
		':id' => $row['id'],
		':msgfrom' => $mail->msgfrom,
		':msgsubject' => $mail->msgsubject,
		':msgrpdscore' => $rpdscore,
		':msgrpdrefid' => $rpdrefid,
	]);
	foreach ($items->result->item as $mail) {
		sleep(3);
		// Move to long-term quarantine
		try {
			$client = new SoapClient($host_options['location'].'?wsdl', $host_options);
			$items = $client->mailQueueUpdateBulk(array('filter' => 'messageid='.$id.' quarantine='.$settings['quarantine_short'], 'fields' => [["first"=>"quarantine", "second"=>$settings['quarantine_long']]]));
		} catch (Exception $e) {
			echo $e->getMessage();
			continue;
		}
		$token = bin2hex(openssl_random_pseudo_bytes(12));
		$q2 = $dbh->prepare('INSERT INTO release_rcpt (release_id,queueid,msgto,token) VALUES (:id,:queueid,:msgto,:token);');
		$q2->execute([
			':id' => $row['id'],
			':queueid' => $mail->id,
			':msgto' => $mail->msgto,
			':token' => $token,
		]);
		$insertid = $dbh->lastInsertId();
		if ($dbh->getAttribute(PDO::ATTR_DRIVER_NAME) == 'pgsql') {
			// XXX PDO lastInsertId() doesnt work for PostgreSQL
			$q2 = $dbh->prepare('SELECT id FROM release_rcpt WHERE release_id = :id AND queueid = :queueid AND token = :token;');
			$q2->execute([
				':id' => $row['id'],
				':queueid' => $mail->id,
				':token' => $token,
			]);
			$row2 = $q2->fetch(PDO::FETCH_ASSOC);
			$insertid = $row2['id']; 
		}
		mail($mail->msgto, $settings['mail_template']['subject'], $twig->render('mail.twig', ['msgfrom' => $mail->msgfrom, 'template' => $settings['template'], 'id' => $insertid, 'token' => $token]), implode("\r\n", $settings['mail_template']['headers']));
	}
}

$q = $dbh->prepare('SELECT * FROM release_sender AS r INNER JOIN release_rcpt AS rr ON r.id = rr.release_id WHERE status = 1;');
$q->execute();
while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
	$host_options = $soap_options[$row['node']];
	$id = $row['queueid'];
	echo "Release $id from ".$host_options['location']."\n";
	try {
		$client = new SoapClient($host_options['location'].'?wsdl', $host_options);
		$ret = $client->mailQueueRetry(['id' => $id]);
	} catch (Exception $e) {
		if ($e->getMessage() == 'queueid not found') {
			$q2 = $dbh->prepare('UPDATE release_rcpt SET status = -1 WHERE id = :id;');
			$q2->execute([':id' => $row['id']]);
		} else {
			echo $e->getMessage();
			continue;
		}
	}
	$q2 = $dbh->prepare('UPDATE release_rcpt SET status = 2 WHERE id = :id;');
	$q2->execute([':id' => $row['id']]);
}

// Whitelist && blacklist
$q = $dbh->prepare('SELECT * FROM release_sender AS r INNER JOIN release_rcpt AS rr ON r.id = rr.release_id WHERE rr.blacklist = 1 OR rr.whitelist = 1;');
$q->execute();
while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $id = $row['msgid'];

    $type = "";
    if($row['blacklist'] == 1) {
        $type = "blacklist";
        # Clean up whitelist if it exists so we don't end up with multiple rules.
        $q2 = $dbh_bw->prepare('DELETE from bwlist WHERE access = :access AND type = "whitelist" AND value = :value');
        $q2->execute([':value' => $row['msgfrom'], 'access' => $row['msgto']]);
    } else if($row['whitelist'] == 1) {
        $type = "whitelist";
        # Clean up blacklist if it exists so we don't end up with multiple rules.
        $q2 = $dbh_bw->prepare('DELETE from bwlist WHERE access = :access AND type = "blacklist" AND value = :value');
        $q2->execute([':value' => $row['msgfrom'], 'access' => $row['msgto']]);
    } else {
        continue;
    }
	echo "$type $id from ".$host_options['location']."\n";
        
    # Value = From
    # access = To
    $q2 = $dbh_bw->prepare('REPLACE INTO bwlist SET access = :access, type = :type, value = :value');
	$q2->execute([':type' => $type, ':value' => $row['msgfrom'], 'access' => $row['msgto']]);

    if($type == "whitelist") {
    	$q3 = $dbh->prepare('UPDATE release_rcpt SET whitelist = 0 WHERE id = :id;');
    } else {
    	$q3 = $dbh->prepare('UPDATE release_rcpt SET blacklist = 0 WHERE id = :id;');
    }
	$q3->execute([':id' => $row['id']]);
}

