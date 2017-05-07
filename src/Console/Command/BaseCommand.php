<?php

namespace Maghead\Console\Command;

use CLIFramework\Command;
use Maghead\Runtime\Config\SymbolicLinkConfigLoader;
use Maghead\Schema\SchemaUtils;
use Maghead\Schema\SchemaLoader;
use Maghead\Schema\SchemaFinder;
use Maghead\Manager\DataSourceManager;
use RuntimeException;
use Maghead\Runtime\Bootstrap;

class BaseCommand extends Command
{
    public $config;

    public $dataSourceManager;

    /**
     * @override
     */
    public function createCommand($commandClass)
    {
        $cmd = parent::createCommand($commandClass);

        if ($cmd instanceof BaseCommand) {
            $cmd->config = $this->config;
            $cmd->dataSourceManager = $this->dataSourceManager;
        }
        return $cmd;
    }

    public function prepare()
    {
        // softly load the config file.
        $this->config = SymbolicLinkConfigLoader::load(null, true); // force loading
        Bootstrap::setupForCLI($this->config);

        $this->dataSourceManager = DataSourceManager::getInstance();
    }

    /**
     * Return the config object in the current context
     */
    protected function getConfig($force = false)
    {
        if (!$this->config || $force) {
            $this->config = SymbolicLinkConfigLoader::load(null, true); // force loading
        }
        if (!$this->config) {
            throw new \Exception("Can't load symbolic config file.");
        }
        Bootstrap::setupForCLI($this->config);
        return $this->config;
    }

    protected function findSchemasByArguments(array $args)
    {
        $config = $this->getConfig();
        $classes = SchemaUtils::argumentsToSchemaObjects($args);

        // filter file path argumets
        $paths = array_filter($args, 'file_exists');
        if (empty($paths)) {
            $paths = $config->getSchemaPaths();
        }

        if (!empty($paths)) {
            $finder = new SchemaFinder($paths);
            $finder->find();
        }

        return SchemaLoader::loadDeclaredSchemas();
    }
}