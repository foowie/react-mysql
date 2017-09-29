# MySQL client for ReactPHP

## Usage

First you need to create connection factory and connection pool

```
$loop = Factory::create();
$connectionFactory = new DefaultConnectionFactory('username', 'password', 'database');
$pool = new Pool(3, $connectionFactory, $loop); // you can set max pool size in 1st parameter
```

Now you can do singe query on connection pool
```
$pool->query('SELECT 1')->then(function(Result $result) {
    // handle query result 
}, function(Exception $e) {
    // handle QueryException / ConnectionException
});
```

You can also ask for connection and do transactions, just remember to release conneciton in all cases
```
$pool->getConnection()->then(function(Connection $conneciton) {
    return $connection->beginTransaction()->then(function() use ($connection) {
        return $connection->query('SELECT 1');
    })->then(function(Result $result) use($connection) {
        // handle result 1
        return $connection->query('SELECT 2');
    })->then(function(Result $result) use($connection) {
        // handle result 2
        $connection->commit();
        $connection->release();
    }, function(Exception $e) {
        // handle execption
        $connection->rollback();
        $connection->release();
    });
}, function(ConnectionException) {
    // handle ConnectionException 
});
```

To simplify escaping, you can use `queryWithArgs(string $query, array $args): PromiseInterface` method on `Pool`/`Connection`. Instead of values and tables / field names, you can put placeholders in query with prefix `:`. Then you should pass escaped value in `$args` with same key as you put in query, without leading `:`.
To automatically escape value, you can either add prefix `:` to the key of param, or you should specify type of escaped value by adding different prefixes. See example below. 
```
$pool->queryWithArgs('SELECT :id, :name FROM :table WHERE :dateField > :fromDate AND :statusField = :statusId AND :name LIKE :namePattern AND :name != :done', [
    'field:id' => 'id', 
    'field:name' => 'name', 
    'field:statusField' => 'status',
    'table:table' => 'items',
    'number:statusId' => 5,
    'like%:namePattern' => 'Do',
    ':namePattern' => 'Done', // : without any type means detect, fields/tables can't be escaped like this (yet)
])->then(...;
```

See full list of escape types in `Connection::$escapeTypes`