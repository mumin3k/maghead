<?php
use SQLBuilder\Universal\Query\SelectQuery;
use Maghead\Testing\ModelTestCase;
use Maghead\Sharding\QueryMapper\Gearman\GearmanQueryMapper;
use Maghead\ConfigLoader;
use Maghead\Sharding\Manager\ShardManager;
use StoreApp\Model\{Store, StoreSchema, StoreRepo};
use StoreApp\Model\{Order, OrderSchema, OrderRepo};

// used by worker
use Monolog\Logger;
use Maghead\Bootstrap;
use Maghead\Sharding\QueryMapper\Gearman\GearmanQueryWorker;
use Maghead\Manager\ConnectionManager;
use Monolog\Handler\ErrorLogHandler;
use StoreApp\StoreTestCase;


/**
 * @group sharding
 */
class GearmanQueryMapperTest extends StoreTestCase
{
    protected $processId;

    public function setUp()
    {
        if (!extension_loaded('gearman')) {
            return $this->markTestSkipped('require gearman extension');
        }

        $this->processId = pcntl_fork();
        if ($this->processId === -1) {
            die('could not fork');
        } else if ($this->processId) {
            // we are the parent
            // pcntl_wait($status); // Protect against Zombie children
            parent::setUp();
        } else {
            // we are the child
            // create worker here.
            $connManager = ConnectionManager::getInstance();
            $connManager->free();
            Bootstrap::setup($config = $this->loadConfig(), true); // setup connection manager

            // create a log channel
            $logger = new Logger('query-worker');
            if (getenv('DEBUG')) {
                $logger->pushHandler(new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Logger::DEBUG));
            } else {
                $logger->pushHandler(new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Logger::ERROR));
            }
            $worker = new GearmanQueryWorker($config, $connManager, null, $logger);
            $worker->run();
            exit(0);
        }
    }

    public function tearDown()
    {
        if ($this->processId) {
            parent::tearDown();
            // Send kill signal to the forked process
            posix_kill($this->processId, SIGUSR1);

            // Wait for children process exits
            pcntl_wait($status); // Protect against Zombie children
        }
    }

    public function testGearmanQueryMapper()
    {
        $shardManager = new ShardManager($this->config, $this->connManager);
        $mapping = $shardManager->getShardMapping('M_store_id');
        $shards = $shardManager->getShardsOf('M_store_id');
        $this->assertNotEmpty($shards);

        $dispatcher = $shardManager->createShardDispatcherOf('M_store_id');

        $g1 = $shards['s1'];
        $repo1 = $g1->createRepo('StoreApp\\Model\\OrderRepo');
        $this->assertInstanceOf('Maghead\\Runtime\\BaseRepo', $repo1);

        $g2 = $shards['s2'];
        $repo2 = $g2->createRepo('StoreApp\\Model\\OrderRepo');
        $this->assertInstanceOf('Maghead\\Runtime\\BaseRepo', $repo2);

        $ret = $repo1->create(['store_id' => 1, 'amount' => 200]);
        $this->assertResultSuccess($ret);
        $o1 = $repo1->findByPrimaryKey($ret->key);
        $this->assertNotNull($o1);

        $ret = $repo2->create(['store_id' => 2, 'amount' => 1000]);
        $this->assertResultSuccess($ret);
        $o2 = $repo2->findByPrimaryKey($ret->key);
        $this->assertNotNull($o2);

        $query = new SelectQuery;
        $query->select(['SUM(amount)' => 'amount']);
        $query->from('orders');
        $query->where()->in('store_id', [1,2]);

        $client = new GearmanClient;
        $client->addServer();
        $mapper = new GearmanQueryMapper($client);
        $res = $mapper->map($shards, $query);
        $this->assertEquals(1000, $res['node2'][0]['amount']);
        $this->assertEquals(200, $res['node1'][0]['amount']);
    }
}
