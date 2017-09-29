<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

/**
 * @author Daniel Robenek <daniel.robenek@me.com>
 */
class ConnectionResult implements Result {

	/** @var Connection */
	protected $connection;

	/** @var Result */
	protected $result;

	public function __construct(Connection $connection, Result $result) {
		$this->connection = $connection;
		$this->result = $result;
	}

	public function getConnection(): Connection {
		return $this->connection;
	}

	public function getSingleScalarResult() {
		return $this->result->getSingleScalarResult();
	}

	public function getSingleResult(): array {
		return $this->result->getSingleResult();
	}

	public function getResult(): array {
		return $this->result->getResult();
	}

	public function getCount(): int {
		return $this->result->getCount();
	}

	public function getInsertId(): int {
		return $this->result->getInsertId();
	}

	public function getAffectedRows(): int {
		return $this->result->getAffectedRows();
	}

}