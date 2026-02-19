<img width="1439" height="496" alt="image" src="https://github.com/user-attachments/assets/1b7f034c-0cef-46b4-bf56-42529bffc827" align="center" />

# Locksmith
PHP 8.3+ library which helps to manage execution in concurent situations (async code, parallel, distributed systems).

## Installation
```sh
composer require mimatus/locksmith
```

## Features
- Mutual exclusion / exclusive locks / mutex / serialized execution / semaphore with capacity 1 - lot of buzzwords describing ensurance resource/code is accessed/executed only once
- Semaphore / shared lock - limit access to resource N times at the same time
- Deadlock prevention via max lock wait time and cooperative suspension points
- Versioned locks - resource is locked only for higher/equal versions - prevents processing of outdated data/code
- Async/concurrent-friendly - cooperative suspension points to allow other lock acquisition attempts or allow lock TTL checks for long running processes
- TTL-based locks - locks are held only for specified time
- In-memory semaphore implementation for single-process scenarios
- Redis-based semaphore implementation for multi-process/distributed scenarios
- Extendable via `Semaphore` interface - implement your own semaphore (e.g., Redis-based, database-based, etc.)
- Deadlock prevention via max lock wait time and cooperative suspension points

## Roadmap
- [x] Basic in-memory & Redis semaphore implementation
- [x] Redlock algorithm for Redis semaphore
- [x] Predis support for Redis semaphore
- [x] AMPHP Redis client support for Redis semaphore
- [ ] First class support and tests for Valkey/KeyDB
- [ ] Feedback and API stabilization
- [ ] Documentation improvements
- [ ] MySQL/MariaDB/PostgreSQL semaphore implementation

## Usage

### In-Memory semaphore
```php

$locksmith = new Locksmith(
    semaphore: new InMemorySemaphore(maxConcurrentLocks: 1) // Single lock at a time -> mutex
);

$resource = new Resource(
    namespace: 'test-resource', // Namespace/identifier for resource
    version: 1, // Optional resource version
);

$locked = $locksmith->locked(
    $resource, 
    lockTTLNs: 1_000_000_000, // How long should be resource locked
    maxLockWaitNs: 500_000_000, // How long to wait for lock acquisition - error if exceeded
    minSuspensionDelayNs: 10_000 // Minimum delay between retries when lock acquisition fails
);

$locked(function (Closure $suspension): void {
    // Critical section - code executed under lock

    $suspension(); // Optional - cooperative suspension point to allow other lock acquisition attempts or allow lock TTL checks for long running processes
});

// Lock is released after callback execution
```

### Redis semaphore
```php

$redis = new Redis();
$redis->connect('redis');
$phpRedisCleint = new PhpRedisClient($redis);

$semaphore = new RedisSemaphore(
    redisClient: $phpRedisCleint,
    maxConcurrentLocks: 3, // Max concurrent locks
);

$locksmith = new Locksmith(semaphore: $semaphore);


$resource = new Resource(
    namespace: 'test-resource', // Namespace/identifier for resource
    version: 1, // Optional resouce version
);

$locked = $locksmith->locked(
    $resource, 
    lockTTLNs: 1_000_000_000, // How long should be resource locked
    maxLockWaitNs: 500_000_000, // How long to wait for lock acquisition - error if exceeded
    minSuspensionDelayNs: 10_000 // Minimum delay between retries when lock acquisition fails
);

$locked(function (Closure $suspension): void {
    // Critical section - code executed under lock

    $suspension(); // Optional - cooperative suspension point to allow other lock acquisition attempts or allow lock TTL checks for long running processes
});
// Lock is released after callback execution
```

### Distributed semaphore
```php
$semaphores = new SemaphoreCollection([
    new RedisSemaphore(
        redisClient: $redisClient1,
        maxConcurrentLocks: 3,
    ),
    new RedisSemaphore(
        redisClient: $redisClient2,
        maxConcurrentLocks: 3,
    ),
    new RedisSemaphore(
        redisClient: $redisClient3,
        maxConcurrentLocks: 3,
    ),
]);

$locksmith = new Locksmith(
    semaphore: new DistributedSemaphore(
        semaphores: $semaphores,
        quorum: 2,
    ),
);
$resource = new Resource(
    namespace: 'test-resource', // Namespace/identifier for resource
    version: 1, // Optional resouce version
);
$locked = $locksmith->locked(
    $resource, 
    lockTTLNs: 1_000_000_000, // How long should be resource locked
    maxLockWaitNs: 500_000_000, // How long to wait for lock acquisition - error if exceeded
    minSuspensionDelayNs: 10_000 // Minimum delay between retries when lock acquisition fails
);
$locked(function (Closure $suspension): void {
    // Critical section - code executed under lock

    $suspension(); // Optional - cooperative suspension point to allow other lock acquisition attempts or allow lock TTL checks for long running processes
});
// Lock is released after callback execution
```
## Development

### Commits
Project follows [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) for commit messages.

### Helpers

All necessary commands are available via `Makefile`.

To see all available commands:
```sh
make help
```
The most important ones:

```sh
composer-install: Install PHP dependencies via Composer.
help: Show help for each of the Makefile recipes.
mago-analyze: Run static analysis via mago.
mago-format: Run code formatting via mago.
mago-lint-fix: Run linting with auto-fix via mago.
mago-lint: Run linting via mago.
run-tests: Run tests via PHPUnit.
```

## License
MIT — see [License](License).
