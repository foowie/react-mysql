<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

use React\Promise\PromiseInterface;

/**
 * @author Daniel Robenek <daniel.robenek@me.com>
 */
interface Queryable {

	public function query(string $query): PromiseInterface;

	public function queryWithArgs(string $query, array $args): PromiseInterface;

}