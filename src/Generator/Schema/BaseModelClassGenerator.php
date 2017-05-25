<?php

namespace Maghead\Generator\Schema;

use ReflectionClass;
use ReflectionMethod;
use InvalidArgumentException;

use Maghead\Schema\DeclareSchema;
use Maghead\Exception\SchemaRelatedException;
use Maghead\Schema\Relationship\Relationship;
use Maghead\Manager\DataSourceManager;
use Maghead\Runtime\Bootstrap;
use Doctrine\Common\Inflector\Inflector;

use Maghead\Generator\PDOStatementGenerator;
use Maghead\Generator\AccessorGenerator;

use Magsql\Universal\Query\SelectQuery;
use Magsql\Universal\Query\DeleteQuery;
use Magsql\Bind;
use Magsql\ParamMarker;
use Magsql\ArgumentArray;

use CodeGen\ClassFile;
use CodeGen\Statement\RequireStatement;
use CodeGen\Statement\RequireOnceStatement;
use CodeGen\Expr\ConcatExpr;
use CodeGen\Raw;

class PrimaryKeyColumnMissingException extends SchemaRelatedException
{
}

/**
 * Base Model class generator.
 *
 * Some rules for generating code:
 *
 * - Mutable values should be generated as propertes.
 * - Immutable values should be generated as constants.
 */
class BaseModelClassGenerator
{
    public static $forcePrimaryKey = false;

    public static function create(DeclareSchema $schema, $baseClass)
    {
        // get data source ids
        $readFrom = $schema->getReadSourceId();
        $writeTo  = $schema->getWriteSourceId();

        $primaryKey = $schema->primaryKey;

        if (static::$forcePrimaryKey && !$primaryKey) {
            throw new PrimaryKeyColumnMissingException($schema, "PrimaryKey is required to be defined in the schema.");
        }

        $cTemplate = clone $schema->classes->baseModel;
        $cTemplate->extendClass('\\'.$baseClass);

        $cTemplate->useClass('Maghead\\Schema\\SchemaLoader');
        $cTemplate->useClass('Maghead\\Runtime\\Result');
        $cTemplate->useClass('Maghead\\Runtime\\Inflator');
        $cTemplate->useClass('Magsql\\Bind');
        $cTemplate->useClass('Magsql\\ArgumentArray');
        $cTemplate->useClass('DateTime');

        $cTemplate->addConsts([
            'SCHEMA_PROXY_CLASS' => $schema->getSchemaProxyClass(),
            'READ_SOURCE_ID'     => $schema->getReadSourceId(),
            'WRITE_SOURCE_ID'    => $schema->getWriteSourceId(),
            'TABLE_ALIAS'        => 'm',
        ]);

        $cTemplate->addConst('SCHEMA_CLASS', get_class($schema));
        $cTemplate->addConst('LABEL', $schema->getLabel());
        $cTemplate->addConst('MODEL_NAME', $schema->getModelName());
        $cTemplate->addConst('MODEL_NAMESPACE', $schema->getNamespace());
        $cTemplate->addConst('MODEL_CLASS', $schema->getModelClass());
        $cTemplate->addConst('REPO_CLASS', $schema->getBaseRepoClass());
        $cTemplate->addConst('COLLECTION_CLASS', $schema->getCollectionClass());
        $cTemplate->addConst('TABLE', $schema->getTable());
        $cTemplate->addConst('PRIMARY_KEY', $schema->primaryKey);
        $cTemplate->addConst('GLOBAL_PRIMARY_KEY', $schema->findGlobalPrimaryKey());
        $cTemplate->addConst('LOCAL_PRIMARY_KEY', $schema->findLocalPrimaryKey());


        // Sharding related constants
        // If sharding is not enabled, don't throw exception.
        $config = Bootstrap::getConfig();
        if (isset($config['sharding'])) {
            $cTemplate->addConst('SHARD_MAPPING_ID', $schema->shardMapping);
            $cTemplate->addConst('GLOBAL_TABLE', $schema->globalTable);
        }

        // TODO: can be removed now.
        $cTemplate->addProtectedProperty('table', $schema->getTable());

        $cTemplate->addStaticVar('column_names', $schema->getColumnNames());
        $cTemplate->addStaticVar('mixin_classes', array_reverse($schema->getMixinSchemaClasses()));

        $cTemplate->addStaticMethod('public', 'getSchema', [], function () use ($schema) {
            return [
                "static \$schema;",
                "if (\$schema) {",
                "   return \$schema;",
                "}",
                "return \$schema = new \\{$schema->getSchemaProxyClass()};",
            ];
        });

        if ($traitClasses = $schema->getModelTraitClasses()) {
            foreach ($traitClasses as $traitClass) {
                $cTemplate->useTrait($traitClass);
            }
        }

        $cTemplate->addStaticMethod('public', 'createRepo', ['$write', '$read'], function () use ($schema) {
            return "return new \\{$schema->getBaseRepoClass()}(\$write, \$read);";
        });


        // interfaces
        if ($ifs = $schema->getModelInterfaces()) {
            foreach ($ifs as $iface) {
                $cTemplate->implementClass($iface);
            }
        }




        $cTemplate->addMethod('public', 'getKeyName', [], function () use ($primaryKey) {
            return "return " . var_export($primaryKey, true) . ';' ;
        });

        $cTemplate->addMethod('public', 'getKey', [], function () use ($primaryKey) {
            return
                "return \$this->{$primaryKey};"
            ;
        });

        $cTemplate->addMethod('public', 'hasKey', [], function () use ($primaryKey) {
            return
                "return isset(\$this->{$primaryKey});"
            ;
        });

        $cTemplate->addMethod('public', 'setKey', ['$key'], function () use ($primaryKey) {
            return
                "return \$this->{$primaryKey} = \$key;"
            ;
        });


        $cTemplate->addMethod('public', 'removeLocalPrimaryKey', [], function () use ($schema) {
            if ($key = $schema->findLocalPrimaryKey()) {
                return "\$this->$key = null;";
            }
            return [];
        });

        if ($globalPk = $schema->findGlobalPrimaryKey()) {
            $cTemplate->addMethod('public', 'removeGlobalPrimaryKey', [], function () use ($globalPk) {
                return "\$this->$globalPk = null;";
            });

            $cTemplate->addMethod('public', 'getGlobalPrimaryKey', [], function () use ($globalPk) {
                return "return \$this->$globalPk;";
            });
        }





        // Create column accessor
        $properties = [];
        foreach ($schema->getColumns(false) as $columnName => $column) {
            $propertyName = Inflector::camelize($columnName);
            $properties[] = [$columnName, $propertyName, $column];

            $cTemplate->addPublicProperty($columnName, null);


            if ($schema->enableColumnAccessors) {
                $booleanAccessor = false;
                if (preg_match('/^is[A-Z]/', $propertyName)) {
                    $booleanAccessor = true;
                    $accessorMethodName = $propertyName;
                } elseif ($column->isa === "bool") {
                    // for column names like "is_confirmed", don't prepend another "is" prefix to the accessor name.
                    $booleanAccessor = true;
                    $accessorMethodName = 'is'.ucfirst($propertyName);
                } else {
                    $accessorMethodName = 'get'.ucfirst($propertyName);
                }
                AccessorGenerator::generateGetterAccessor($cTemplate, $column, $accessorMethodName, $propertyName);

                /*
                if (!$booleanAccessor) {
                    AccessorGenerator::generateSetterAccessor($cTemplate, $column, 'set'.ucfirst($propertyName), $propertyName);
                }
                */
            }

            // Generate findable proxy methods
            if ($column->findable) {
                $findMethodName = 'findBy'.ucfirst(Inflector::camelize($columnName));
                $cTemplate->addMethod('public', $findMethodName, ['$value'], function () use ($findMethodName) {
                    // Call Repo methods on masterRepo
                    return ["return static::masterRepo()->{$findMethodName}(\$value);"];
                });
            }
        }


        $cTemplate->addMethod('public', 'getAlterableData', [], function () use ($properties) {
            $alterableProperties = array_filter($properties, function ($p) {
                list($columnName, $propertyName, $column) = $p;
                return !$column->immutable;
            });
            return
                'return [' . join(", ", array_map(function ($p) {
                    list($columnName, $propertyName, $column) = $p;
                    return "\"$columnName\" => \$this->{$columnName}";
                }, $alterableProperties)) . '];'
                ;
        });


        $cTemplate->addMethod('public', 'getData', [], function () use ($properties) {
            return
                'return [' . join(", ", array_map(function ($p) {
                    list($columnName, $propertyName) = $p;
                    return "\"$columnName\" => \$this->{$columnName}";
                }, $properties)) . '];'
            ;
        });

        $cTemplate->addMethod('public', 'setData', ['array $data'], function () use ($properties) {
            return array_map(function ($p) {
                list($columnName, $propertyName) = $p;
                return "if (array_key_exists(\"{$columnName}\", \$data)) { \$this->{$columnName} = \$data[\"{$columnName}\"]; }";
            }, $properties);
        });

        $cTemplate->addMethod('public', 'clear', [], function () use ($properties) {
            return array_map(function ($p) {
                list($columnName, $propertyName) = $p;
                return "\$this->{$columnName} = NULL;";
            }, $properties);
        });


        foreach ($schema->getRelations() as $relKey => $rel) {
            switch ($rel['type']) {
                case Relationship::HAS_ONE:
                case Relationship::HAS_MANY:
                case Relationship::BELONGS_TO:
                $relName = ucfirst(Inflector::camelize($relKey));
                $methodName = 'fetch'. $relName;
                $repoMethodName = 'fetch'. $relName . 'Of';
                $cTemplate->addMethod('public', $methodName, [],
                    "return static::masterRepo()->{$repoMethodName}(\$this);");
                break;
            }


            $relName = ucfirst(Inflector::camelize($relKey));
            $methodName = 'get'. $relName;

            switch ($rel['type']) {
                case Relationship::HAS_MANY:

                $foreignSchema = $rel->newForeignSchema();
                $foreignCollectionClass = $foreignSchema->getCollectionClass();

                $foreignColumn = $rel->getForeignColumn();
                $selfColumn = $rel->getSelfColumn();

                $cTemplate->addMethod('public', $methodName, [], function () use ($foreignCollectionClass, $foreignColumn, $selfColumn) {
                    return [
                        "\$collection = new \\{$foreignCollectionClass};",
                        "\$collection->where()->equal(\"{$foreignColumn}\", \$this->{$selfColumn});",
                        "\$collection->setPresetVars([ \"{$foreignColumn}\" => \$this->{$selfColumn} ]);",
                        "return \$collection;",
                    ];
                });

                break;

                case Relationship::MANY_TO_MANY:



                // assemble the join query with the collection class string
                $cTemplate->addMethod('public', $methodName, [], function () use ($schema, $relName, $relKey, $rel) {
                    $junctionRelKey = $rel['relation_junction'];
                    $junctionRel = $schema->getRelation($junctionRelKey);
                    if (!$junctionRel) {
                        throw new InvalidArgumentException("Junction relationship of many-to-many $junctionRelKey is undefined.");
                    }
                    $junctionSchema = $junctionRel->newForeignSchema();

                    $foreignRelKey = $rel['relation_foreign'];
                    $foreignRel = $junctionSchema->getRelation($foreignRelKey);
                    if (!$foreignRel) {
                        throw new InvalidArgumentException("Foreign relationship of many-to-many $foreignRelKey is undefined.");
                    }
                    $foreignSchema = $foreignRel->newForeignSchema();
                    $targetCollectionClass = $foreignSchema->getCollectionClass();

                    $selfRefColumn = $foreignRel->getForeignColumn();

                    // Join the junction table, generate some sql query like this:
                    //      SELECT * from books m LEFT JOIN author_books j on (j.book_id = m.id)
                    //      WHERE j.author_id = :author_id
                    return [
                        "\$collection = new \\{$targetCollectionClass};",
                        "\$collection->joinTable('{$junctionSchema->getTable()}', 'j', 'INNER')",
                        "   ->on(\"j.{$foreignRel->getSelfColumn()} = {\$collection->getAlias()}.{$foreignRel->getForeignColumn()}\");",
                        // " ->on()->equal('j.{$foreignRel->getSelfColumn()}', [\$collection->getAlias() . '.{$foreignRel->getForeignColumn()}']);",
                        "\$collection->where()->equal('j.{$junctionRel->getForeignColumn()}', \$this->{$selfRefColumn});",
                        "\$parent = \$this;",
                        "\$collection->setAfterCreate(function(\$record, \$args) use (\$parent) {",
                        "   \$a = [",
                        "      '{$foreignRel->getSelfColumn()}' => \$record->get(\"{$foreignRel->getForeignColumn()}\"),",
                        "      '{$junctionRel['foreign_column']}' => \$parent->{$selfRefColumn},",
                        "   ];",
                        "   if (isset(\$args['{$junctionRelKey}'])) {",
                        "      \$a = array_merge(\$args['{$junctionRelKey}'], \$a);",
                        "   }",
                        "   return \\{$junctionSchema->getModelClass()}::createAndLoad(\$a);",
                        "});",
                        "return \$collection;",
                    ];
                });
                break;
            }
        }

        return $cTemplate;
    }
}
