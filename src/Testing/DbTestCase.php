<?php

namespace Maghead\Testing;

use Maghead\Manager\DataSourceManager;
use Maghead\TableBuilder\TableBuilder;
use Maghead\Runtime\Model;
use Maghead\Runtime\Config\FileConfigLoader;
use Maghead\Generator\Schema\SchemaGenerator;
use Maghead\Schema\DeclareSchema;
use Maghead\Runtime\Collection;
use Maghead\Runtime\Result;
use Maghead\Runtime\Bootstrap;
use Maghead\Runtime\PDOExceptionPrinter;
use Magsql\Driver\BaseDriver;
use CLIFramework\Logger;
use PDO;
use PDOException;
use Exception;
use InvalidArgumentException;

/**
 * @codeCoverageIgnore
 */
abstract class DbTestCase extends TestCase
{
    /**
     * @var string
     *
     * The data source id for creating default connection.
     * by default, $this->driver will be the default data source.
     */
    protected $defaultDataSource;

    protected $dataSourceManager;

    protected $config;

    /**
     * @var Maghead\Runtime\Connection
     *
     * The default connection object.
     */
    protected $conn;

    protected $allowConnectionFailure = false;

    protected $freeConnections;

    /**
     * @var Maghead\QueryDriver
     *
     * The query driver object of the default connection.
     */
    protected $queryDriver;

    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        if (!extension_loaded('pdo')) {
            return $this->markTestSkipped('pdo extension is required for model testing');
        }

        $this->dataSourceManager = DataSourceManager::getInstance();
        $this->logger = new Logger();
        $this->logger->setQuiet();
    }

    protected function getMasterDataSourceId()
    {
        return 'master';
    }


    /**
     * by default we load the config from symbolic file. (this will be created
     * by the bootstrap script)
     */
    protected function config()
    {
        $driverType = $this->getCurrentDriverType();
        $configFile = "tests/config/{$driverType}.yml";

        if (!file_exists($configFile)) {
            throw new InvalidArgumentException("$configFile doesn't exist.");
        }

        $tmpConfig = tempnam("/tmp", "{$driverType}_") . '.yml';
        copy($configFile, $tmpConfig);
        $config = FileConfigLoader::load($tmpConfig, true);
        $config->setAutoId();
        return $config;
    }

    public function setUp()
    {
        parent::setUp();

        // Always reset config from symbol file
        $this->config = $this->config();
        Bootstrap::setConfig($this->config);
        Bootstrap::setupDataSources($this->config, $this->dataSourceManager);
        Bootstrap::setupGlobalVars($this->config, $this->dataSourceManager);

        $this->prepareConnections();
    }

    public function tearDown()
    {
        // for sqlite
        if ($this->freeConnections === null) {
            $driverType = $this->getCurrentDriverType();
            $this->freeConnections = $driverType === 'sqlite';
        }
        if (true === $this->freeConnections) {
            $this->dataSourceManager->free();
            $this->dataSourceManager->clean();
            $this->conn = null;
        }

    }

    public static function tearDownAfterClass()
    {
    }

    protected function prepareConnections()
    {
        $this->setupMasterConnection();
    }

    protected function getMasterConnection()
    {
        if (!$this->conn) {
            throw new Exception("The test case didn't setup the default connection.");
        }
        return $this->conn;
    }

    protected function setupMasterConnection()
    {
        if (!$this->conn && $this->getMasterDataSourceId()) {
            $this->conn = $this->setupConnection($this->getMasterDataSourceId());
            $this->queryDriver = $this->conn->getQueryDriver();
        }
    }

    /**
     * @return Maghead\Runtime\Connection
     */
    protected function setupConnection(string $connId)
    {
        try {
            // Create the default connection
            $conn = $this->dataSourceManager->getWriteConnection($connId);

            if ($this->getCurrentDriverType() === 'sqlite') {
                // This is for sqlite:memory, copy the connection object to another connection ID.
                $this->dataSourceManager->shareWrite($connId);
            }

            return $conn;
        } catch (PDOException $e) {
            if ($this->allowConnectionFailure) {
                $this->markTestSkipped(
                    sprintf("Can not connect to database by data source '%s' message:'%s' config:'%s'",
                        $connId,
                        $e->getMessage(),
                        var_export($this->config->getDataSource($connId), true)
                    ));

                return;
            }
            fprintf(STDERR, "Can not connect to database by data source '%s' message:'%s' config:'%s'",
                $connId,
                $e->getMessage(),
                var_export($this->config->getDataSource($connId), true)
            );
            throw $e;
        }
    }


    /**
     * @return array[] class map
     */
    protected function updateSchemaFiles(DeclareSchema $schema)
    {
        $generator = new SchemaGenerator($this->config, $this->logger);

        return $generator->generate([$schema]);
    }

    protected function buildSchemaTable(PDO $conn, BaseDriver $driver, DeclareSchema $schema, array $options = ['rebuild' => true])
    {
        $builder = TableBuilder::create($driver, $options);
        $sqls = array_filter(array_merge($builder->prepare(), $builder->build($schema), $builder->finalize()));
        foreach ($sqls as $sql) {
            $conn->query($sql);
        }
    }

    public function matrixDataProvider(array $alist, array $blist)
    {
        $data = [];
        foreach ($alist as $a) {
            foreach ($blist as $b) {
                $data[] = [$a, $b];
            }
        }

        return $data;
    }

    public function driverTypeDataProvider()
    {
        $data = [];
        if (extension_loaded('pdo_mysql')) {
            $data[] = ['mysql'];
        }
        if (extension_loaded('pdo_pgsql')) {
            $data[] = ['pgsql'];
        }
        if (extension_loaded('pdo_sqlite')) {
            $data[] = ['sqlite'];
        }

        return $data;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function getConfig()
    {
        return $this->config;
    }

    // ==========================================================
    // Assertion Methods
    // ==========================================================

    public function assertTableExists(PDO $conn, $tableName)
    {
        $driverName = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        switch ($driverName) {
            case 'mysql':
                $stm = $conn->query("SHOW COLUMNS FROM $tableName");
                break;
            case 'pgsql':
                $stm = $conn->query("SELECT * FROM information_schema.columns WHERE table_name = '$tableName';");
                break;
            case 'sqlite':
                $stm = $conn->query("select sql from sqlite_master where type = 'table' AND name = '$tableName'");
                break;
            default:
                throw new Exception('Unsupported PDO driver');
                break;
        }
        $result = $stm->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($result);
    }

    public function assertQueryOK(PDO $conn, $sql, $args = array())
    {
        try {
            $ret = $conn->query($sql);
            $this->assertNotNull($ret);

            return $ret;
        } catch (PDOException $e) {
            PDOExceptionPrinter::show(new Logger, $e, $sql, $args);
            throw $e;
        }
    }

    public function assertDelete(Model $record)
    {
        $this->assertResultSuccess($record->delete());
    }

    public function assertResultFail(Result $ret, $message = null)
    {
        $this->assertTrue($ret->error, $message ?: $ret->message);
    }

    public function assertInstanceOfModel(Model $record)
    {
        $this->assertInstanceOf('Maghead\Runtime\Model', $record);
    }

    public function assertInstanceOfCollection(Collection $collection)
    {
        $this->assertInstanceOf('Maghead\Runtime\Collection', $collection);
    }

    public function assertCollectionSize($size, Collection $collection, $message = null)
    {
        $this->assertEquals($size, $collection->size(), $message ?: 'Colletion size should match');
    }

    public function assertRecordLoaded(Model $record, $message = null)
    {
        $data = $record->getStashedData();
        $this->assertNotEmpty($data, $message ?: 'Record loaded');
    }

    public function assertResultsSuccess(array $rets, $message = null)
    {
        foreach ($rets as $ret) {
            $this->assertResultSuccess($ret, $message);
        }
    }

    public function assertResultSuccess(Result $ret, $message = null)
    {
        if ($ret->error === true) {
            // Pretty printing this
            var_dump($ret);
        }
        $this->assertFalse($ret->error, $message ?: $ret->message);
    }

    public function resultOK($expect, Result $ret)
    {
        $this->assertNotNull($ret);
        if ($ret->success === $expect) {
            $this->assertTrue($ret->success, $ret->message);
        } else {
            var_dump($ret->sql);
            echo $ret->exception;
            $this->assertTrue($ret->success, $ret->message);
        }
    }
}
