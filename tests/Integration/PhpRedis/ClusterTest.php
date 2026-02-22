<?php

declare(strict_types=1);

namespace MiMatus\Locksmith\Tests\Integration\PhpRedis;

use Exception;
use MiMatus\Locksmith\Resource;
use MiMatus\Locksmith\Semaphore\Redis\PhpRedisClient;
use MiMatus\Locksmith\Semaphore\Redis\RedisSemaphore;
use MiMatus\Locksmith\Semaphore\TimeProvider;
use MiMatus\Locksmith\SemaphoreInterface;
use MiMatus\Locksmith\Tests\SemaphoreTestCase;
use Override;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;

class ClusterTest extends TestCase
{
    private RedisCluster $redis;

    /**
     * @throws Exception
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new RedisCluster(null, ['172.30.0.11:6379', '172.30.0.12:6379', '172.30.0.13:6379', '172.30.0.14:6379', '172.30.0.15:6379', '172.30.0.16:6379']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->redis->close();
    }

    public function testA(){
        
        var_dump($this->redis->_masters());

        $master = $this->redis->_masters()[0];
        print_r($this->redis->cluster($master, 'SHARDS'));
        var_dump($this->redis->cluster($master, 'KEYSLOT', 'somekey'));

        foreach($this->redis->cluster($master, 'SHARDS') as $shardInfo) {
            // $slotRange = $this->getData('slots', $shardInfo);
            // $nodes = $this->getData('nodes', $shardInfo);
            // foreach($nodes as $node) {
                
            // }

            $shards = [];
            // var_dump($this->getAsMapedArray($shardInfo));
            $mappedShardInfo = $this->getAsMapedArray($shardInfo);
            foreach($mappedShardInfo['nodes'] as $node) {
                $mappedNodeinfo = $this->getAsMapedArray($node);
                $shards[] = new RedisSlot(
                    rangeStart: $mappedShardInfo['slots'][0],
                    rangeEnd: $mappedShardInfo['slots'][1],
                    endpoint: $mappedNodeinfo['endpoint'],
                    port: (int) $mappedNodeinfo['port'],
                );
            }

            var_dump($shards);

            // var_dump($this->getData('slots', $shardInfo));
            // var_dump($this->getData('nodes', $shardInfo));
        }
    }

    private function getAsMapedArray(array $data): array
    {
        $dataMap = [];
        do {
            $key = array_shift($data);
            $value = array_shift($data);
            $dataMap[$key] = $value;
        } while (count($data) > 0);
        return $dataMap;
    }

    private function getData(string $search, array $data): mixed
    {
        foreach($data as $key => $dataValue) {
            if($search === $dataValue) {
                return $data[++$key] ?? null;
            }
        }
        return null;
    }
}

class RedisSlot {
    public function __construct(
        private int $rangeStart,
        private int $rangeEnd,
        private string $endpoint,
        private int $port,
    ) {}
}
