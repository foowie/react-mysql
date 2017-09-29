<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

/**
 * @author Daniel Robenek <daniel.robenek@me.com>
 */
class DefaultResult implements Result {

	/** @var array[] */
	protected $rows = [];

	/** @var int */
	protected $insertId;

	/** @var int */
	protected $affectedRows;

	public function __construct($mysqliResult, int $insertId, int $affectedRows) {
		if ($mysqliResult instanceof \mysqli_result) {
			while ($row = mysqli_fetch_assoc($mysqliResult)) {
				$this->rows[] = $row;
			}
		}
		$this->insertId = $insertId;
		$this->affectedRows = $affectedRows;
	}

	public function getSingleScalarResult() {
	    $row = current($this->rows);
	    return current($row);
	}

	public function getSingleResult(): array {
	    return current($this->rows);
	}

	public function getResult(): array {
	    return $this->rows;
	}

	public function getCount(): int {
	    return count($this->rows);
	}

	public function getInsertId(): int {
		return $this->insertId;
	}

	public function getAffectedRows(): int {
		return $this->affectedRows;
	}

}