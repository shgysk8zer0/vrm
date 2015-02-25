<?php
// Check PHP version >= 5.5
if (! defined('PHP_VERSION_ID') or PHP_VERSION_ID < 50500) {
	echo "This requires PHP version > 5.5" . PHP_EOL;
	exit(-1);
}

// Verify that this is running via command line/cron
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit();
}

// Verify that Sockets extension is loaded
if (! extension_loaded('sockets')) {
	echo "This requires sockets extension (http://www.php.net/manual/en/sockets.installation.php)" . PHP_EOL;
	exit(-1);
}

if (!extension_loaded('PDO')) {
	echo "This requires PDO extension (http://php.net/manual/en/pdo.installation.php)" . PHP_EOL;
	exit(-1);
}

if (!extension_loaded('pdo_mysql')) {
	echo "This requires PDO MySQL extension (http://php.net/manual/en/ref.pdo-mysql.php)" . PHP_EOL;
	exit(-1);
}

// Verify that process control extension is loaded
if (! extension_loaded('pcntl')) {
	echo "This requires PCNTL extension (http://www.php.net/manual/en/pcntl.installation.php)" . PHP_EOL;
	exit(-1);
}

// Configure include_path & autoloader
set_include_path(realpath('includes') . PATH_SEPARATOR . get_include_path());
spl_autoload_extensions('.php');
spl_autoload_register('spl_autoload');


// Create & check database connection
$PDO = new \shgysk8zer0\Core\PDO('connect.json');
if (! $PDO->connected) {
	exit('Failed to connect to database');
}

// Prepared statement to be passed into child on connections
$insert = $PDO->prepare(
	"INSERT INTO `data` (
		`message`,
		`date_time_received`
	) VALUES (
		:message,
		:date_time_received
	);"
);

$config = \shgysk8zer0\Core\Resources\Parser::parseFile('socket_settings.json');
$server = new \shgysk8zer0\Sockets\SocketServer($config->port, $config->address);
$server->init();
$server->setConnectionHandler(
	function(\shgysk8zer0\Sockets\SocketClient $client) use ($insert)
	{
		$pid = pcntl_fork();
		if ($pid == -1) {
			die('could not fork');
		} elseif ($pid) {
			// parent process
			return;
		}
		$read = '';
		printf("[%s] Connected at port %d" . PHP_EOL, $client->getAddress(), $client->getPort());
		while(true) {
			$read = $client->read();
			$read = trim($read);
			if (! empty($read)) {
				$client->send('[' . date(DATE_RFC822) . '] ' . $read . PHP_EOL);
			} else {
				break;
			}
			if (is_null($read)) {
				printf("[%s] Disconnected" . PHP_EOL, $client->getAddress());
				return false;
			} else {
				$insert->message = $read;
				$insert->date_time_received = date('Y-m-d H:i:s');
				$insert->execute();
				printf("[%s] received: %s" . PHP_EOL, $client->getAddress(), $read);
			}
		}
		$client->close();
		printf("[%s] Disconnected\n", $client->getAddress());
	}
);
$server->listen();
