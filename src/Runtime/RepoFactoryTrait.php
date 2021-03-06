<?php

namespace Maghead\Runtime;

trait RepoFactoryTrait
{
    /**
     * masterRepo method creates the Repo instance class with the default data source IDs
     *
     * @return \Maghead\Runtime\Repo
     */
    public static function masterRepo()
    {
        $dataSourceManager = static::$dataSourceManager;
        $write = $dataSourceManager->getWriteConnection(static::WRITE_SOURCE_ID);
        $read  = $dataSourceManager->getReadConnection(static::READ_SOURCE_ID);
        return static::createRepo($write, $read);
    }

    /**
     * Create a repo object with custom write/read connections.
     *
     * @param string|Connection $write
     * @param string|Connection $read
     * @return Maghead\Runtime\Repo
     */
    public static function repo($write = null, $read = null)
    {
        $dataSourceManager = static::$dataSourceManager;
        if (!$read) {
            if (!$write) {
                return static::masterRepo();
            }
            $read = $write;
        }
        $writeConn = is_string($write) ? $dataSourceManager->getWriteConnection($write) : $write;
        $readConn = is_string($read) ? $dataSourceManager->getReadConnection($read) : $read;
        return static::createRepo($writeConn, $readConn);
    }

    /**
     * This will be overrided by child model class.
     *
     * @param \Maghead\Runtime\Connection $write
     * @param \Maghead\Runtime\Connection $read
     * @return \Maghead\Runtime\Repo
     */
    abstract public static function createRepo($write, $read);
}
