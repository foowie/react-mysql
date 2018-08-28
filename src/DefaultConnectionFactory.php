<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

use React\Promise;
use React\Promise\PromiseInterface;


/**
 * @author Daniel Robenek <daniel.robenek@me.com>
 */
class DefaultConnectionFactory implements ConnectionFactory {

	/** @var string */
	protected $host = 'localhost';

	/** @var int */
	protected $port = 3306;

	/** @var string */
	protected $socket = '';

	/** @var string */
	protected $username;

	/** @var string */
	protected $password;

	/** @var string */
	protected $database;

	/** @var string[] */
	protected $options = [MYSQLI_OPT_CONNECT_TIMEOUT => 5];

	/** @var string */
	protected $charset = 'utf8';

	/** @var string */
	protected $sqlMode;

	/** @var string */
	protected $timezone;

	/** @var int */
	protected $currentConnectionId = 0;

	public function __construct(string $username, string $password, string $database = null, string $host = 'localhost', int $port = 3306) {
		if (!extension_loaded('mysqli')) {
			throw new ConnectionException('PHP extension mysqli is not loaded');
		}
		$this->host = $host;
		$this->port = $port;
		$this->username = $username;
		$this->password = $password;
		$this->database = $database;
		$this->timezone = date('P');
	}

	public function create(): PromiseInterface {
		mysqli_report(MYSQLI_REPORT_OFF);
		$connection = mysqli_init();

		foreach ($this->options as $key => $option) {
			mysqli_options($connection, $key, $option);
		}

		@mysqli_real_connect($connection, $this->host, $this->username, $this->password, $this->database ?? '', $this->port ?? 0, $this->socket, 0);

		if ($errno = mysqli_connect_errno()) {
			return Promise\reject(new ConnectionException(mysqli_connect_error(), $errno));
		}

		// todo: async
		if ($this->charset !== null) {
			if (!@mysqli_set_charset($connection, $this->charset)) {
				@mysqli_query($connection, "SET NAMES '{$this->charset}'");
				if ($errno = mysqli_errno($connection)) {
					return Promise\reject(new ConnectionException(mysqli_error($connection), $errno));
				}
			}
		}

		if ($this->sqlMode !== null) {
			@mysqli_query($connection, "SET sql_mode='{$this->sqlMode}'");
			if ($errno = mysqli_errno($connection)) {
				return Promise\reject(new ConnectionException(mysqli_error($connection), $errno));
			}

		}

		if ($this->timezone !== null) {
			@mysqli_query($connection, "SET time_zone='{$this->timezone}'");
			if ($errno = mysqli_errno($connection)) {
				return Promise\reject(new ConnectionException(mysqli_error($connection), $errno));
			}
		}

		return Promise\resolve(new Connection($connection, $this->currentConnectionId++));
	}

	public function setHost(string $host) {
		$this->host = $host;
	}

	public function setPort(int $port) {
		$this->port = $port;
	}

	public function setSocket(string $socket) {
		$this->socket = $socket;
	}

	public function setUsername(string $username) {
		$this->username = $username;
	}

	public function setPassword(string $password) {
		$this->password = $password;
	}

	public function setDatabase(string $database) {
		$this->database = $database;
	}

	public function setOption(string $key, string $option) {
		$this->options[$key] = $option;
	}

	public function setCharset(string $charset) {
		$this->charset = $charset;
	}

	public function setSqlMode(string $sqlMode) {
		$this->sqlMode = $sqlMode;
	}

	public function setTimezone(string $timezone) {
		$this->timezone = $timezone;
	}

}