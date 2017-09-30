<?php

declare(strict_types=1);

namespace Foowie\ReactMySql;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;
use React\Promise;

/**
 * @author Daniel Robenek <daniel.robenek@me.com>
 */
class Pool implements Queryable {

	const MYSQL_CONNECTION_ISSUE_CODES = [1053, 2006, 2008, 2013, 2055];

	/** @var ConnectionFactory */
	protected $connectionFactory;

	/** @var LoopInterface */
	protected $loop;

	/** @var int */
	protected $maxConnections;

	/** @var \SplObjectStorage */
	protected $availableConnections; // todo: SplObjectStorage is FIFO, use LIFO to get unused connections released!

	/** @var \SplObjectStorage */
	protected $usedConnections;

	/** @var int */
	protected $connectionCount = 0;

	/** @var Promise\Deferred[] */
	protected $queuedConnectionRequests = [];

	/** @var TimerInterface */
	protected $connectionTimer;

	/** @var float */
	protected $timerInterval = 0.01;

	/** @var int */
	protected $waitTimeout;

	/** @var Promise\Deferred|null */
	protected $closeDeferred;

	public function __construct(int $maxConnections, ConnectionFactory $connectionFactory, LoopInterface $loop) {
		$this->connectionFactory = $connectionFactory;
		$this->loop = $loop;
		$this->maxConnections = $maxConnections;
		$this->availableConnections = new \SplObjectStorage();
		$this->usedConnections = new \SplObjectStorage();

		// todo: make timeout & interval configurable
		$this->query("SHOW VARIABLES WHERE VARIABLE_NAME='wait_timeout'")->then(function(Result $result) {
			$this->waitTimeout = $result->getSingleResult()['Value'];
			$this->loop->addPeriodicTimer(1, function() {
				$limit = time() - $this->waitTimeout + 5;
				/** @var Connection $availableConnection */
				foreach ($this->availableConnections as $availableConnection) {
					if ($availableConnection->getLastUseTimestamp() < $limit) {
						$this->closeAndRemoveConnection($availableConnection);
					}
				}
			});
		});
	}


	public function getConnection(): Promise\PromiseInterface {
		$this->availableConnections->rewind();
		if ($this->availableConnections->valid()) {
			/** @var Connection $connection */
			$connection = $this->availableConnections->current();
			$this->availableConnections->detach($connection);
			$this->usedConnections->attach($connection->getMysqli(), $connection);
			$this->updateTimer();
			return Promise\resolve($connection);
		}

		if ($this->maxConnections <= $this->connectionCount) {
			$deferred = new Promise\Deferred();
			$this->queuedConnectionRequests[] = $deferred;
			return $deferred->promise();
		}

		$this->connectionCount++;
		return $this->connectionFactory->create()->then(function(Connection $connection) {
			$this->usedConnections->attach($connection->getMysqli(), $connection);
			$connection->setReleaseCallback([$this, 'release']);
			$this->updateTimer();
			return $connection;
		}, function($e) {
			$this->connectionCount--;
			return Promise\reject($e);
		});
	}

	public function release(Connection $connection) {
	    if (!$this->availableConnections->contains($connection) && $this->usedConnections->contains($connection->getMysqli())) {
	    	if ($this->closeDeferred !== null) {
	    		$this->closeAndRemoveConnection($connection);
	    		if ($this->connectionCount === 0) {
	    			$this->closeDeferred->resolve();
				    $this->closeDeferred = null;
			    }
		    } else if (empty($this->queuedConnectionRequests)) {
	    		$this->usedConnections->detach($connection->getMysqli());
	    	    $this->availableConnections->attach($connection);
			    $this->updateTimer();
		    } else {
			    $deferred = array_shift($this->queuedConnectionRequests);
			    $deferred->resolve($connection);
		    }
	    }
	}

	public function query(string $query): Promise\PromiseInterface {
	    return $this->getConnection()->then(function(Connection $connection) use ($query) {
	    	return $connection->query($query)->then(function(Result $result) use ($connection) {
	    		$connection->release();
	    		return $result;
		    }, function($e) use ($connection) {
			    $connection->release();
	    		return Promise\reject($e);
		    });
	    });
	}

	public function queryWithArgs(string $query, array $args): Promise\PromiseInterface {
	    return $this->getConnection()->then(function(Connection $connection) use ($query, $args) {
	    	return $connection->queryWithArgs($query, $args)->then(function(Result $result) use ($connection) {
	    		$connection->release();
	    		return $result;
		    }, function($e) use ($connection) {
			    $connection->release();
	    		return Promise\reject($e);
		    });
	    });
	}

	public function checkConnectionResults() {
		/** @var \mysqli[] $reads */
		/** @var \mysqli[] $errors */
		$reads = $errors = iterator_to_array($this->usedConnections);
		if (empty($reads)) {
			return;
		}

		$noResults = [];
	    if (mysqli_poll($reads, $errors, $noResults, 0) === false) {
	    	return; // todo
	    }

		foreach ($reads as $read) {
			/** @var Connection $connection */
			$connection = $this->usedConnections[$read];
			$connection->processQueryResult();
		}

		foreach ($errors as $error) {
	    	$errorCode = mysqli_errno($error);
			/** @var Connection $connection */
			$connection = $this->usedConnections[$error];
			$connection->processQueryError();
			if (in_array($errorCode, self::MYSQL_CONNECTION_ISSUE_CODES, true)) {
				$this->closeAndRemoveConnection($connection);
			}
		}
	}

	public function waitAndClose(int $maxTimeout = null): Promise\PromiseInterface {
		if ($this->closeDeferred !== null) {
			return $this->closeDeferred->promise();
		}
		/** @var Connection $connection */
		foreach ($this->availableConnections as $connection) {
	    	$this->closeAndRemoveConnection($connection);
	    }

	    if ($maxTimeout === 0) {
	    	foreach ($this->usedConnections as $mysqli) {
	    		/** @var Connection $connection */
	    		$connection = $this->usedConnections[$mysqli];
	    		$this->closeAndRemoveConnection($connection);
			    // todo: send information to promise that connection was closed?
		    }
		    return Promise\resolve();
	    }

	    if ($this->connectionCount > 0) {
	    	$this->closeDeferred = new Promise\Deferred();
		    if ($maxTimeout !== null) {
			    $this->loop->addTimer($maxTimeout, function() {
			    	if ($this->closeDeferred === null) {
			    		return;
				    }
				    foreach ($this->usedConnections as $mysqli) {
					    /** @var Connection $connection */
					    $connection = $this->usedConnections[$mysqli];
					    $this->closeAndRemoveConnection($connection);
					    // todo: send information to promise that connection was closed?
				    }
				    /** @var Connection $connection */
				    foreach ($this->availableConnections as $connection) {
					    $this->closeAndRemoveConnection($connection);
				    }
				    $this->closeDeferred->resolve();
				    $this->closeDeferred = null;
			    });
		    }
	    	return $this->closeDeferred->promise();
	    }

    	return Promise\resolve();
	}

	protected function updateTimer() {
		if ($this->usedConnections->count() > 0) {
			if ($this->connectionTimer === null) {
				$this->connectionTimer = $this->loop->addPeriodicTimer($this->timerInterval, [$this, 'checkConnectionResults']);
			}
		} else {
			if ($this->connectionTimer !== null) {
				$this->connectionTimer->cancel();
				$this->connectionTimer = null;
			}
		}
	}

	protected function closeAndRemoveConnection(Connection $connection) {
		mysqli_close($connection->getMysqli());
		if ($this->availableConnections->contains($connection)) {
			$this->availableConnections->detach($connection);
		}
		if ($this->usedConnections->contains($connection->getMysqli())) {
			$this->usedConnections->detach($connection->getMysqli());
		}
		$this->connectionCount--;
	}

}