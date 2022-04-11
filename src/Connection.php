<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\Promise;
use React\Promise\PromiseInterface;

/**
 * @author Daniel Robenek <daniel.robenek@me.com>
 */
class Connection implements Queryable, LoggerAwareInterface {

	use LoggerAwareTrait;

	/** @var int */
	protected $id;

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

	public function __construct(\mysqli $mysqli, int $id = null) {
		$this->mysqli = $mysqli;
		$this->id = $id ?? random_int(0, PHP_INT_MAX);
		$this->lastUseTimestamp = time();
		$this->logger = new NullLogger();

		$this->escapeTypes = [
			':' => [$this, 'escapeDetect'],
			'detect:' => [$this, 'escapeDetect'],
			't:' => [$this, 'escapeText'],
			'ta:' => [$this, 'escapeTextArray'],
			'text:' => [$this, 'escapeText'],
			'texts:' => [$this, 'escapeTextArray'],
			'n:' => function($val) { return $val === null ? 'NULL' : $val; },
			'na:' => function(array $val) { return implode(',', array_map(function($item) { return $item === null ? 'NULL' : $item; }, $val)); },
			'number:' => function($val) { return $val === null ? 'NULL' : $val; },
			'numbers:' => function(array $val) { return implode(',', array_map(function($item) { return $item === null ? 'NULL' : $item; }, $val)); },
			'b:' => [$this, 'escapeBool'],
			'ba:' => [$this, 'escapeBoolArray'],
			'bool:' => [$this, 'escapeBool'],
			'bools:' => [$this, 'escapeBoolArray'],
			'd:' => [$this, 'escapeDate'],
			'da:' => [$this, 'escapeDateArray'],
			'date:' => [$this, 'escapeDate'],
			'dates:' => [$this, 'escapeDateArray'],
			'dt:' => [$this, 'escapeDateTime'],
			'dta:' => [$this, 'escapeDateTimeArray'],
			'datetime:' => [$this, 'escapeDateTime'],
			'datetimes:' => [$this, 'escapeDateTimeArray'],
			'binary:' => [$this, 'escapeBinary'],
			'binaries:' => [$this, 'escapeBinaryArray'],
			'id:' => [$this, 'escapeIdentifier'],
			'table:' => [$this, 'escapeIdentifier'],
			'field:' => [$this, 'escapeIdentifier'],
			'%like%:' => function(string $val) { return $this->escapeLike($val, 0); },
			'%like:' => function(string $val) { return $this->escapeLike($val, -1); },
			'like%:' => function(string $val) { return $this->escapeLike($val, 1); },
		];
	}

	public function getId(): int {
		return $this->id;
	}

	public function setEscapeType(string $key, callable $callback) {
		$this->escapeTypes[$key] = $callback;
	}

	/**
	 * @param string $query
	 * @param array $args
	 * @return Promise\ExtendedPromiseInterface
	 */
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
			$query = str_replace(":$arg", (string)$value, $query);
		}
		return $this->query($query);
	}

	/**
	 * @param string $query
	 * @return Promise\ExtendedPromiseInterface
	 */
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

	/**
	 * @return Promise\ExtendedPromiseInterface
	 */
	public function beginTransaction(): PromiseInterface {
		return $this->query('START TRANSACTION');
	}

	/**
	 * @return Promise\ExtendedPromiseInterface
	 */
	public function commit(): PromiseInterface {
		return $this->query('COMMIT');
	}

	/**
	 * @return Promise\ExtendedPromiseInterface
	 */
	public function commitAndRelease(): PromiseInterface {
		return $this->commit()->then(function($result) {
			$this->release();
			return $result;
		}, function($e) {
			$this->release();
			return Promise\reject($e);
		});
	}

	/**
	 * @return Promise\ExtendedPromiseInterface
	 */
	public function rollback(): PromiseInterface {
		return $this->query('ROLLBACK');
	}

	/**
	 * @return Promise\ExtendedPromiseInterface
	 */
	public function rollbackAndRelease(): PromiseInterface {
		return $this->rollback()->then(function($result) {
			$this->release();
			return $result;
		}, function($e) {
			$this->release();
			return Promise\reject($e);
		});
	}

	public function escapeDetect($value) {
		if ($value === null) {
			return 'NULL';
		}
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
		if (is_array($value)) {
			return implode(',', array_map([$this, 'escapeDetect'], $value));
		}
		throw new \InvalidArgumentException('Unknown type of argument');
	}

	public function escapeText(string $value = null): string {
		return $value === null ? 'NULL' : "'" . mysqli_real_escape_string($this->mysqli, $value) . "'";
	}

	public function escapeTextArray(array $values): string {
		return implode(',', array_map([$this, 'escapeText'], $values));
	}

	public function escapeBinary(string $value = null): string {
		return $value === null ? 'NULL' : "_binary'" . mysqli_real_escape_string($this->mysqli, $value) . "'";
	}

	public function escapeBinaryArray(array $values): string {
		return implode(',', array_map([$this, 'escapeBinary'], $values));
	}

	public function escapeIdentifier(string $value): string {
		return '`' . str_replace('`', '``', $value) . '`';
	}

	public function escapeBool(bool $value = null): string {
		return $value === null ? 'NULL' : ($value ? '1' : '0');
	}

	public function escapeBoolArray(array $values): string {
		return implode(',', array_map([$this, 'escapeBool'], $values));
	}

	public function escapeDate(\DateTimeInterface $value = null): string {
		return $value === null ? 'NULL' : $value->format("'Y-m-d'");
	}

	public function escapeDateArray(array $values): string {
		return implode(',', array_map([$this, 'escapeDate'], $values));
	}

	public function escapeDateTime(\DateTimeInterface $value = null): string {
		return $value === null ? 'NULL' : $value->format("'Y-m-d H:i:s.u'");
	}

	public function escapeDateTimeArray(array $values): string {
		return implode(',', array_map([$this, 'escapeDateTime'], $values));
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
		$message = mysqli_error($this->mysqli);
		$code = mysqli_errno($this->mysqli);

		if ($code === 1062) {
			return new UniqueConstraintViolationException($message, $code);
		}

		if (in_array($code, Pool::MYSQL_CONNECTION_ISSUE_CODES, true)) {
			$this->logger->info('MySQL connection [{id}] issue: {message} with code {code}', ['id' => $this->id, 'message' => $message, 'code' => $code]);
			return new ConnectionException($message, $code);
		}

		return new QueryException($message, $code);
	}

}