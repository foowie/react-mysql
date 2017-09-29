<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

use React\Promise\PromiseInterface;

/**
 * @author Daniel Robenek <daniel.robenek@me.com>
 */
interface ConnectionFactory {

	public function create(): PromiseInterface;

}