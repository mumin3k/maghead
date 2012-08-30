<?php
namespace LazyRecord\TableParser;
use PDO;
use Exception;

class MysqlTableParser extends BaseTablePaser
{

    public function getTables()
    {
        $stm = $this->connection->query('show tables;');
        $rows = $stm->fetchAll( PDO::FETCH_NUM);
        return array_map(function($row) { return $row[0]; },$rows);
    }

    public function getTableSchema($table)
    {
        $stm = $this->connection->query("show columns from $table;");
        $schema = new Schema\SchemaDeclare;
        $schema->columnNames = $schema->columns = array();
        $rows = $stm->fetchAll();
        foreach( $rows as $row ) {
            $type = $row['Type'];
            $isa = $this->_parserType($type);

            // reverse type for mysql
            if ( 'int(11)' === $type ) {
                $type = 'integer';
            }
            else if( 'tinyint(1)' === $type ) {
                $type = 'boolean';
                $isa = 'bool';
            }

            $column = $schema->column( $row['Field'] );
            $column->type( $type );
            $column->null( $row['Null'] === 'YES' );

            if( 'PRI' === $row['Key'] ) {
                $column->primary(true);
                $schema->primaryKey = $row['Field'];
            }
            elseif( 'UNI' === $row['Key'] ) {
                $column->unique(true);
            }

            if($isa) {
                $column->isa($isa);
            }

            if( NULL !== $row['Default'] ) {
                // $column->default( array($row['Default']) );
            }
        }
        return $schema;
    }


    public function _parserType($type)
    {
        $type = strtolower($type);
        if( preg_match( '/^(char|varchar|text)/' , $type ) ) {
            return 'str';
        }
        elseif( preg_match('/^(int|tinyint|smallint|mediumint|bigint)/', $type ) ) {
            return 'int';
        }
        elseif( 'double' === $type ) {
            return 'double';
        }
        elseif( 'float' === $type ) {
            return 'float';
        }
        elseif( 'datetime' === $type || 'date' === $type ) {
            return 'DateTime';
        }
        elseif( preg_match('/timestamp/', $type ) ) {
            return 'DateTime';
        }
        else {
            throw new Exception("Unknown type $type");
        }
    }
}
