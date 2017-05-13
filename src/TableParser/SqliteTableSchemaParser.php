<?php

namespace Maghead\TableParser;

use Exception;
use stdClass;

class TableDef {

    public $columns = [];

    public $temporary;

    public $ifNotExists = false;

    public $tableName;

}

class Constraint {

    public $name;

    public $primaryKey;

    public $unique;

    public $foreignKey;

}

class Column {

    public $name;

    public $type;

    public $length;

    public $decimals;

    public $unsigned;

    public $primary;

    public $ordering;

    public $autoIncrement;

    public $unique;

    public $notNull;

    public $default;

    public $collate;

    public $references;

}

/**
 * SQLite Parser for parsing table column definitions:.
 *
 *  CREATE TABLE {identifier} ( columndef, columndef, ... );
 *
 *
 * The syntax follows the official documentation below:
 *
 *  http://www.sqlite.org/lang_createtable.html
 */
class SqliteTableSchemaParser
{
    /**
     *  @var int
     *
     *  The default buffer offset
     */
    protected $p = 0;

    /**
     * @var string
     *
     * The buffer string for parsing.
     */
    protected $str = '';

    protected function looksLikeTableConstraint()
    {
        return $this->test(['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK']);
    }

    public function parse($input, $offset = 0)
    {
        $this->str    = $input;
        $this->strlen = strlen($input);
        $this->p = $offset;

        $tableDef = new TableDef;

        $this->skipSpaces();

        $keyword = $this->tryParseKeyword(['CREATE']);

        $this->skipSpaces();

        if ($this->tryParseKeyword(['TEMPORARY', 'TEMP'])) {
            $tableDef->temporary = true;
        }

        $this->skipSpaces();

        $this->tryParseKeyword(['TABLE']);

        $this->skipSpaces();

        if ($this->tryParseKeyword(['IF'])) {
            if (!$this->tryParseKeyword(['NOT'])) {
                throw new Exception('Unexpected token');
            }
            if (!$this->tryParseKeyword(['EXISTS'])) {
                throw new Exception('Unexpected token');
            }
            $tableDef->ifNotExists = true;
        }

        $tableName = $this->tryParseIdentifier();

        $tableDef->tableName = $tableName->val;

        $this->skipSpaces();
        $this->advance('(');
        $this->parseColumns($tableDef);
        $this->advance(')');

        return $tableDef;
    }

    protected function parseColumns(TableDef $tableDef)
    {
        // Parse columns
        while (!$this->metEnd()) {
            $this->skipSpaces();

            $tryPos = $this->p;
            if ($this->tryParseTableConstraints()) {
                $this->rollback($tryPos);
                break;
            }

            $identifier = $this->tryParseIdentifier();
            if (!$identifier) {
                break;
            }

            $column = new Column;
            $column->name = $identifier->val;

            $this->skipSpaces();
            $typeName = $this->tryParseTypeName();
            if ($typeName) {
                $this->skipSpaces();
                $column->type = $typeName->val;
                $precision = $this->tryParseTypePrecision();
                if ($precision && $precision->val) {
                    if (count($precision->val) == 2) {
                        $column->length = $precision->val[0];
                        $column->decimals = $precision->val[1];
                    } elseif (count($precision->val) == 1) {
                        $column->length = $precision->val[0];
                    }
                }

                if (in_array(strtoupper($column->type), self::$intTypes)) {
                    $column->unsigned = $this->consume('unsigned', 'unsigned');
                }

                while ($constraintToken = $this->tryParseColumnConstraint()) {
                    if ($constraintToken->val == 'PRIMARY') {
                        $this->tryParseKeyword(['KEY']);

                        $column->primary = true;

                        if ($orderingToken = $this->tryParseKeyword(['ASC', 'DESC'])) {
                            $column->ordering = $orderingToken->val;
                        }

                        if ($this->tryParseKeyword(['AUTOINCREMENT'])) {
                            $column->autoIncrement = true;
                        }

                    } else if ($constraintToken->val == 'UNIQUE') {

                        $column->unique = true;

                    } else if ($constraintToken->val == 'NOT NULL') {

                        $column->notNull = true;

                    } else if ($constraintToken->val == 'NULL') {

                        $column->notNull = false;

                    } else if ($constraintToken->val == 'DEFAULT') {

                        // parse scalar
                        if ($scalarToken = $this->tryParseScalar()) {
                            $column->default = $scalarToken->val;
                        } elseif ($literal = $this->tryParseKeyword(['CURRENT_TIME', 'CURRENT_DATE', 'CURRENT_TIMESTAMP'], 'literal')) {
                            $column->default = $literal;
                        } elseif ($null = $this->tryParseKeyword(['NULL'])) {
                            $column->default = null;
                        } elseif ($null = $this->tryParseKeyword(['TRUE'])) {
                            $column->default = true;
                        } elseif ($null = $this->tryParseKeyword(['FALSE'])) {
                            $column->default = false;
                        } else {
                            throw new Exception("Can't parse literal: ".$this->currentWindow());
                        }

                    } else if ($constraintToken->val == 'COLLATE') {
                        $collateName = $this->tryParseIdentifier();
                        $column->collate = $collateName->val;

                    } else if ($constraintToken->val == 'REFERENCES') {

                        $tableNameToken = $this->tryParseIdentifier();

                        $this->advance('(');
                        $columnNames = $this->parseColumnNames();
                        $this->advance(')');

                        $actions = [];
                        if ($this->tryParseKeyword(['ON'])) {
                            while ($onToken = $this->tryParseKeyword(['DELETE', 'UPDATE'])) {
                                $on = $onToken->val;
                                $actionToken = $this->tryParseKeyword(['SET NULL', 'SET DEFAULT', 'CASCADE', 'RESTRICT', 'NO ACTION']);
                                $actions[$on] = $actionToken->val;
                            }
                        }

                        $column->references = (object) [
                            'table' => $tableNameToken->val,
                            'columns' => $columnNames,
                            'actions' => $actions,
                        ];
                    }
                    $this->skipSpaces();
                }
            }

            $tableDef->columns[] = $column;
            $this->skipSpaces();
            if ($this->metComma()) {
                $this->skipComma();
                $this->skipSpaces();
            }
        } // end of column parsing

        if ($tableConstraints = $this->tryParseTableConstraints()) {
            $tableDef->tableConstraints = $tableConstraints;
            $this->skipSpaces();
            if ($this->metComma()) {
                $this->skipComma();
                $this->skipSpaces();
            }
        }

        return $tableDef;
    }

    protected function rollback($p)
    {
        $this->p = $p;
    }

    protected function test($str)
    {
        if (is_array($str)) {
            foreach ($str as $s) {
                if ($this->test($s)) {
                    return strlen($s);
                }
            }
        } elseif (is_string($str)) {
            $p = stripos($this->str, $str, $this->p);

            return $p === $this->p ? strlen($str) : false;
        } else {
            throw new Exception('Invalid argument type');
        }
    }

    protected function skip($tokens)
    {
        while ($len = $this->test($tokens)) {
            $this->p += $len;
        }
    }

    protected function parseColumnNames()
    {
        $columnNames = [];
        while ($identifier = $this->tryParseIdentifier()) {
            $columnNames[] = $identifier->val;
            $this->skipSpaces();
            if ($this->metComma()) {
                $this->skipComma();
                $this->skipSpaces();
            } else {
                break;
            }
        }

        return $columnNames;
    }

    protected function tryParseTableConstraints()
    {
        $tableConstraints = null;

        while (!$this->metEnd()) {
            $this->skipSpaces();
            $tableConstraint = new Constraint;

            if ($this->tryParseKeyword(['CONSTRAINT'])) {
                $this->skipSpaces();
                $constraintName = $this->tryParseIdentifier();
                if (!$constraintName) {
                    throw new Exception('Expect constraint name');
                }
                $tableConstraint->name = $constraintName->val;
            }

            $this->skipSpaces();
            $tableConstraintKeyword = $this->tryParseKeyword(['PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN']);

            if (!$tableConstraintKeyword) {
                if (isset($tableConstraint->name)) {
                    throw new Exception('Expect constraint type');
                }
                break;
            }

            if (in_array($tableConstraintKeyword->val, ['PRIMARY', 'FOREIGN'])) {
                $this->skipSpaces();
                $this->tryParseKeyword(['KEY']);
            }

            if ($tableConstraintKeyword->val == 'PRIMARY') {
                if ($indexColumns = $this->tryParseIndexColumns()) {
                    $tableConstraint->primaryKey = $indexColumns;
                }
            } else if ($tableConstraintKeyword->val == 'UNIQUE') {
                if ($indexColumns = $this->tryParseIndexColumns()) {
                    $tableConstraint->unique = $indexColumns;
                }
            } else if ($tableConstraintKeyword->val == 'FOREIGN') {
                if ($this->cur() == '(') {
                    $this->advance('(');
                    $tableConstraint->foreignKey = $this->parseColumnNames();
                    if ($this->cur() == ')') {
                        $this->advance(')');
                    } else {
                        throw new Exception('Unexpected token: '.$this->currentWindow());
                    }
                } else {
                    throw new Exception('Unexpected token: '.$this->currentWindow());
                }
            }
            $tableConstraints[] = $tableConstraint;
        }

        return $tableConstraints;
    }

    /**
     * return the current buffer window
     */
    protected function currentWindow($window = 32)
    {
        return var_export(substr($this->str, $this->p, $window) . '...', true)." FROM '{$this->str}'\n";
    }

    protected function metEnd()
    {
        return $this->p + 1 >= $this->strlen;
    }

    protected function skipSpaces()
    {
        while (!$this->metEnd() && in_array($this->str[$this->p], [' ', "\t", "\n"])) {
            ++$this->p;
        }
    }

    protected function metComma()
    {
        return !$this->metEnd() && $this->str[$this->p] == ',';
    }

    protected function skipComma()
    {
        if (!$this->metEnd() && $this->str[ $this->p ] == ',') {
            ++$this->p;
        }
    }

    protected function tryParseColumnConstraint()
    {
        return $this->tryParseKeyword(['PRIMARY', 'UNIQUE', 'NOT NULL', 'NULL', 'DEFAULT', 'COLLATE', 'REFERENCES'], 'constraint');
    }

    protected function cur()
    {
        return $this->str[ $this->p ];
    }

    protected function advance($c = null)
    {
        if (!$this->metEnd()) {
            if ($c) {
                if ($c === $this->str[$this->p]) {
                    ++$this->p;
                    return true;
                }
            } else {
                ++$this->p;
            }
        }
    }

    protected function tryParseKeyword(array $keywords, $as = 'keyword')
    {
        $this->skipSpaces();
        $this->sortKeywordsByLen($keywords);

        foreach ($keywords as $keyword) {
            $p2 = stripos($this->str, $keyword, $this->p);
            if ($p2 === $this->p) {
                $this->p += strlen($keyword);

                return new Token($as, $keyword);
            }
        }

        return;
    }

    protected function tryParseIndexColumns()
    {
        $this->advance('(');
        $this->skipSpaces();
        $indexColumns = [];
        while ($columnName = $this->tryParseIdentifier()) {
            $indexColumn = new stdClass();
            $indexColumn->name = $columnName->val;

            if ($this->tryParseKeyword(['COLLATE'])) {
                $this->skipSpaces();
                if ($collationName = $this->tryParseIdentifier()) {
                    $indexColumn->collationName = $collationName->val;
                }
            }

            if ($ordering = $this->tryParseKeyword(['ASC', 'DESC'])) {
                $indexColumn->ordering = $ordering->val;
            }

            $this->skipSpaces();
            if ($this->metComma()) {
                $this->skipComma();
            }
            $this->skipSpaces();
            $indexColumns[] = $indexColumn;
        }
        if ($this->cur() == ')') {
            $this->advance();
        }

        return $indexColumns;
    }

    protected function tryParseTypePrecision()
    {
        $c = $this->str[ $this->p ];
        if ($c == '(') {
            if (preg_match('/\( \s* (\d+) \s* , \s* (\d+) \s* \)/x', $this->str, $matches, 0, $this->p)) {
                $this->p += strlen($matches[0]);

                return new Token('precision', [intval($matches[1]), intval($matches[2])]);
            } elseif (preg_match('/\(  \s* (\d+) \s* \)/x', $this->str, $matches, 0, $this->p)) {
                $this->p += strlen($matches[0]);

                return new Token('precision', [intval($matches[1])]);
            } else {
                throw new Exception('Invalid precision syntax');
            }
        }

        return;
    }

    protected function sortKeywordsByLen(array &$keywords)
    {
        usort($keywords, function ($a, $b) {
            $al = strlen($a);
            $bl = strlen($b);
            if ($al == $bl) {
                return 0;
            } elseif ($al > $bl) {
                return -1;
            } else {
                return 1;
            }
        });
    }

    public static $intTypes = ['INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'BIG INT', 'INT2', 'INT8'];
    public static $textTypes = ['CHARACTER', 'VARCHAR', 'VARYING CHARACTER', 'NCHAR', 'NATIVE CHARACTER', 'NVARCHAR', 'TEXT', 'BLOB', 'BINARY'];
    public static $numericTypes = ['NUMERIC', 'DECIMAL', 'BOOLEAN', 'DATE', 'DATETIME', 'TIMESTAMP'];

    protected function consume($token, $typeName)
    {
        if (($p2 = stripos($this->str, $token, $this->p)) !== false && $p2 == $this->p) {
            $this->p += strlen($token);

            return new Token($typeName, $token);
        }

        return false;
    }

    protected function tryParseTypeName()
    {
        $blobTypes = ['BLOB', 'NONE'];
        $realTypes = ['REAL', 'DOUBLE', 'DOUBLE PRECISION', 'FLOAT'];
        $allTypes = array_merge(self::$intTypes,
            self::$textTypes,
            $blobTypes,
            $realTypes,
            self::$numericTypes);
        $this->sortKeywordsByLen($allTypes);
        foreach ($allTypes as $typeName) {
            // Matched
            if (($p2 = stripos($this->str, $typeName, $this->p)) !== false && $p2 == $this->p) {
                $this->p += strlen($typeName);

                return new Token('type-name', $typeName);
            }
        }

        return;
        // throw new Exception('Expecting type-name');
    }

    protected function tryParseIdentifier()
    {
        $this->skipSpaces();
        if ($this->str[$this->p] == '`') {
            ++$this->p;
            // find the quote pair position
            $p2 = strpos($this->str, '`', $this->p);
            if ($p2 === false) {
                throw new Exception('Expecting identifier quote (`): '.$this->currentWindow());
            }
            $token = substr($this->str, $this->p, $p2 - $this->p);
            $this->p = $p2 + 1;

            return new Token('identifier', $token);
        }
        if (preg_match('/^(\w+)/', substr($this->str, $this->p), $matches)) {
            $this->p += strlen($matches[0]);

            return new Token('identifier', $matches[1]);
        }

        return;
    }

    protected function tryParseScalar()
    {
        $this->skipSpaces();

        if ($this->advance("'")) {

            $p = $this->p;

            while (!$this->metEnd()) {
                if ($this->advance("'")) {
                    break;
                }
                $this->advance("\\"); // skip
                $this->advance();
            }

            $string = str_replace("''", "'", substr($this->str, $p, ($this->p - 1) - $p));

            return new Token('string', $string);

        } else if (preg_match('/-?\d+  \. \d+/x', substr($this->str, $this->p), $matches)) {

            $this->p += strlen($matches[0]);

            return new Token('double', doubleval($matches[0]));

        } else if (preg_match('/-?\d+/x', substr($this->str, $this->p), $matches)) {

            $this->p += strlen($matches[0]);

            return new Token('int', intval($matches[0]));

        }
    }
}
