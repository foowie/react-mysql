<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

interface Result {

	public function getSingleScalarResult();

	public function getSingleResult(): array;

	public function getResult(): array;

	public function getCount(): int;

	public function getInsertId(): int;

	public function getAffectedRows(): int;

}