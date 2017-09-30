<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

use React\Promise;
use React\Promise\PromiseInterface;

/**
 * @author Daniel Robenek <daniel.robenek@me.com>
 */
class Connection implements Queryable {

	/** @var \mysqli */
	protected $mysqli;

	/** @var Promise\Deferred|null */
	protected $deferred;

	/** @var callable */
	protected $releaseCallback;

	/** @var null|string */
	protected $lastQuery;

	/** @var int */
	protected $lastUseTimestamp;

	/** @var string[] */
	protected $escapeTypes;

	public function __construct(\mysqli $mysqli) {
		$this->mysqli = $mysqli;
		$this->lastUseTimestamp = time();

		$this->escapeTypes = [
			':' => [$this, 'escapeDetect'],
			'detect:' => [$this, 'escapeDetect'],
			't:' => [$this, 'escapeText'],
			'text:' => [$this, 'escapeText'],
			'n:' => function($val) { return $val; },
			'number:' => function($val) { return $val; },
			'b:' => [$this, 'escapeBool'],
			'bool:' => [$this, 'escapeBool'],
			'd:' => [$this, 'escapeDate'],
			'date:' => [$this, 'escapeDate'],
			'dt:' => [$this, 'escapeDateTime'],
			'datetime:' => [$this, 'escapeDateTime'],
			'binary:' => [$this, 'escapeBinary'],
			'id:' => [$this, 'escapeIdentifier'],
			'table:' => [$this, 'escapeIdentifier'],
			'field:' => [$this, 'escapeIdentifier'],
			'%like%:' => function(string $val) { return $this->escapeLike($val, 0); },
			'%like:' => function(string $val) { return $this->escapeLike($val, -1); },
			'like%:' => function(string $val) { return $this->escapeLike($val, 1); },
		];
	}

	public function setEscapeType(string $key, callable $callback) {
	    $this->escapeTypes[$key] = $callback;
	}

	public function queryWithArgs(string $query, array $args): PromiseInterface {
		foreach ($args as $arg => $value) {
			$pos = strpos($arg, ':');
			if ($pos !== false) {
				$prefix = substr($arg, 0, $pos + 1);
				if (!isset($this->escapeTypes[$prefix])) {
					throw new \InvalidArgumentException('Escape type ' . $prefix . ' not found!');
				}
				$value = call_user_func($this->escapeTypes[$prefix], $value, $this->mysqli);
				$arg = substr($arg, $pos + 1);
			}
			$query = str_replace(":$arg", $value, $query);
		}
		return $this->query($query);
	}

	public function query(string $query): PromiseInterface {
		if ($this->deferred !== null) {
			throw new InvalidStateException('Connection already in use');
		}

		$this->deferred = new Promise\Deferred();

		$this->lastQuery = $query;
		$this->lastUseTimestamp = time();
		$res = @mysqli_query($this->mysqli, $query, MYSQLI_ASYNC);

		if ($res === false) {
			return Promise\reject($this->createException());
		}

		return $this->deferred->promise();
	}

	public function getMysqli(): \mysqli {
		return $this->mysqli;
	}

	/**
	 * @return null|string
	 */
	public function getLastQuery() {
		return $this->lastQuery;
	}

	/**
	 * @return int
	 */
	public function getLastUseTimestamp(): int {
		return $this->lastUseTimestamp;
	}

	public function processQueryResult() {
		$result = @mysqli_reap_async_query($this->mysqli);

		if ($this->deferred === null) {
			throw new InvalidStateException('No deferred for query result');
		}

		$deferred = $this->deferred;
		$this->deferred = null;

		if ($result === false) {
			$deferred->reject($this->createException());
		} else {
			$result = new DefaultResult($result, mysqli_insert_id($this->mysqli), mysqli_affected_rows($this->mysqli));
			$deferred->resolve(new ConnectionResult($this, $result));
		}

	}
	
	public function processQueryError() {
		if ($this->deferred === null) {
			throw new InvalidStateException('No deferred for query result');
		}

		$deferred = $this->deferred;
		$this->deferred = null;

		$deferred->reject($this->createException());
	}

	public function beginTransaction(): PromiseInterface {
	    return $this->query('START TRANSACTION');
	}

	public function commit(): PromiseInterface {
	    return $this->query('COMMIT');
	}

	public function rollback(): PromiseInterface {
	    return $this->query('ROLLBACK');
	}

	public function escapeDetect($value) {
		if (is_numeric($value)) {
			return $value;
		}
		if (is_string($value)) {
			return $this->escapeText($value);
		}
		if ($value instanceof \DateTimeInterface) {
			return $this->escapeDateTime($value);
		}
		if (is_bool($value)) {
			return $this->escapeBool($value);
		}
		throw new \InvalidArgumentException('Unknown type of argument');
	}

	public function escapeText(string $value): string {
		return "'" . mysqli_real_escape_string($this->mysqli, $value) . "'";
	}

	public function escapeBinary(string $value): string {
		return "_binary'" . mysqli_real_escape_string($this->mysqli, $value) . "'";
	}

	public function escapeIdentifier(string $value): string {
		return '`' . str_replace('`', '``', $value) . '`';
	}

	public function escapeBool(bool $value): string {
		return $value ? '1' : '0';
	}

	public function escapeDate(\DateTimeInterface $value): string {
		return $value->format("'Y-m-d'");
	}

	public function escapeDateTime(\DateTimeInterface $value): string {
		return $value->format("'Y-m-d H:i:s.u'");
	}

	public function escapeLike(string $value, int $pos): string {
		$value = addcslashes(str_replace('\\', '\\\\', $value), "\x00\n\r\\'%_");
		return ($pos <= 0 ? "'%" : "'") . $value . ($pos >= 0 ? "%'" : "'");
	}

	public function release() {
	    if ($this->releaseCallback !== null) {
	    	call_user_func($this->releaseCallback, $this);
	    }
	}

	public function setReleaseCallback(callable $releaseCallback) {
		$this->releaseCallback = $releaseCallback;
	}

	/**
	 * @todo: detect and throw ConnectionException
	 */
	protected function createException(): \Exception {
		return new QueryException(mysqli_error($this->mysqli), mysqli_errno($this->mysqli));
	}
	
}