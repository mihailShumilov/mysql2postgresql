<?php

    /**
     * Created by PhpStorm.
     * User: mihailshumilov
     * Date: 23.02.14
     * Time: 16:27
     */
    class Convertor
    {

        private $iFileName;
        private $oFileName;
        private $exportStructure;

        private $iFh;
        private $oFh;

        private $tableFields = array();
        private $comment4Field  = array();
        private $comment4Table  = '';
        private $row = array();
        private $lastTable = null;
        private $lastRow = null;
        private $tableData = false;
        private $fieldOpen = false;
        private $timestampType = "timestamp with time zone";
        private $seq = false;
        private $insertRowField = array();
        private $maxBatchCount = 200;
        private $batchCount = 0;
        private $defaultWords = array(
            'CURRENT_TIMESTAMP'
        );

        public function __construct($params)
        {
            if (isset($params['iFileName']) && ! empty($params['iFileName'])) {
                $this->iFileName = $params['iFileName'];
            } else {
                throw new Exception("Input file name not set");
            }
            if (isset($params['oFileName']) && ! empty($params['oFileName'])) {
                $this->oFileName = $params['oFileName'];
            } else {
                throw new Exception("Output file name not set");
            }
            if (isset($params['batchCount']) && $params['batchCount']) {
                if (filter_var($params['batchCount'], FILTER_VALIDATE_INT)) {
                    $this->maxBatchCount = $params['batchCount'];
                } else {
                    throw new Exception("Parameter `batchCount` must be positive integer");
                }
            }
            if (isset($params['exportStructure'])) {
                $this->exportStructure = $params['exportStructure'];
            } else {
                throw new Exception("Export database structure not set");
            }
            $this->checkFiles();
        }

        private function checkFiles()
        {
            if (!file_exists($this->iFileName)) {
                throw new RuntimeException("File `{$this->iFileName}` does not exists");
            }
            if (!$this->iFh = @fopen($this->iFileName, "r")) {
                throw new RuntimeException("Can't open `{$this->iFileName}`");
            }
            if (!$this->oFh = @fopen($this->oFileName, "w+")) {
                throw new RuntimeException("Can't open `{$this->oFileName}` to write");
            }
        }

        public function __destruct()
        {
            fclose($this->iFh);
            fclose($this->oFh);
        }

        public function run()
        {
            $xml = xml_parser_create();

            xml_set_element_handler($xml, array( &$this, "start" ), array( &$this, "end" ));
            xml_set_character_data_handler($xml, array( &$this, "data" ));
            xml_parser_set_option($xml, XML_OPTION_CASE_FOLDING, false);

            $totalFileSize = filesize($this->iFileName);
            $processed     = 0;

            echo "\nProcess:0%\r";
            while ($data = fread($this->iFh, 4096)) {
                if(xml_parse($xml, $data, feof($this->iFh))==0){
			echo("Error: ".xml_error_string(xml_get_error_code($xml))."\n");
			echo("Errored line: ".xml_get_current_line_number($xml)."\n");
			echo("Errored column: ".xml_get_current_column_number($xml)."\n");
			echo("Errored index: ".xml_get_current_byte_index($xml)."\n");
			die("Can't parse XML data\n");
		}
                $processed += 4096;
                $percentage = round($processed / $totalFileSize * 100, 2);
                echo "Processed: {$percentage}%\r";
            }
            xml_parser_free($xml);
            echo "\r";
            echo "Processed: 100%\n";
        }

        private function start($parser, $name, $attrs)
        {
            switch ($name) {
                case "table_structure":
                    $this->tableFields         = array();
                    $this->tableFields['name'] = $attrs['name'];
                    break;
                case "field":
                    if ($this->tableData) {
                        $this->row[$attrs['name']] = null;
                        $this->lastRow             = $attrs['name'];
                        $this->fieldOpen           = true;
                    } else {
                        $this->convert_field_data($attrs);
                    }
                    break;
                case "key":
                    $this->convert_key_data($attrs);
                    break;
                case "table_data":
                    $this->batchCount = 0;
                    $this->tableData = true;
                    $this->lastTable = $attrs['name'];
                    break;
                case "row":
                    $this->row = array();
                    break;
                case "options":
                    if (isset($attrs['Auto_increment'])) {
                        $this->setSeqValue($attrs);
                    }
                    if (isset($attrs['Comment']) && strlen($attrs['Comment']) > 0) {
                        $this->comment4Table = $attrs['Comment'];
                    }
                    break;
            }
        }

        private function end($parser, $name)
        {
            switch ($name) {
                case "table_structure":

                    if ($this->exportStructure) {
                        fwrite($this->oFh, "\nDROP TABLE IF EXISTS \"{$this->tableFields['name']}\";\n");
    
                        if (array_key_exists("types", $this->tableFields)) {
                            foreach ($this->tableFields["types"] as $customTypeName => $customType) {
                                fwrite($this->oFh, "DROP TYPE IF EXISTS \"" . trim($customTypeName, "\"") . "\";\n");
                                fwrite($this->oFh, "CREATE TYPE " . str_replace('""', '"', $customType) . ";\n");
                            }
                        }
    
                        fwrite($this->oFh, "\nCREATE TABLE \"{$this->tableFields['name']}\" (\n");
                        fwrite($this->oFh, "\t");
                        fwrite($this->oFh, join(",\n\t", $this->tableFields["fields"]));
    
                        if (! empty($this->tableFields["primary"])) {
                            fwrite(
                                $this->oFh,
                                ",\n\t" . 'PRIMARY KEY ("' . join('","', $this->tableFields["primary"]) . '")' . "\n"
                            );
                        }
                        fwrite($this->oFh, "\n);\n");
    
                        if (array_key_exists("key", $this->tableFields)) {
                            foreach ($this->tableFields["key"] as $keyName => $keyData) {
                                $indexName = $this->tableFields['name'] . "_" . $keyName;
                                fwrite(
                                    $this->oFh,
                                    "DROP INDEX IF EXISTS \"{$indexName}\";\n"
                                );
                                $fields = $keyData['fields'];
                                array_walk($fields, function (&$item, $key) {
                                    $item = "\"" . $item . "\"";
                                });
                                fwrite(
                                    $this->oFh,
                                    "CREATE " . ($keyData["uniq"] ? "UNIQUE " : "") . "INDEX \"" . $indexName . "\" ON \"{$this->tableFields['name']}\" (" . join(
                                        ",",
                                        $fields
                                    ) . ");\n"
                                );
                            }
                        }
                        if ($this->seq) {
                            fwrite($this->oFh, $this->seq);
                            $this->seq = false;
                        }

                        if (count($this->comment4Field)) {
                            fwrite($this->oFh, "\n");
                            foreach ($this->comment4Field as $field => $comment) {
                                $comment_field = sprintf("COMMENT ON COLUMN %s.%s IS '%s';\n",
                                    $this->tableFields['name'], $field, addslashes($comment));
                                fwrite($this->oFh, $comment_field);
                            }
                        }
                        if ($this->comment4Table) {
                            fwrite($this->oFh,
                                sprintf("COMMENT ON TABLE %s IS '%s';\n",
                                    $this->tableFields['name'], addslashes($this->comment4Table)));
                        }

                        $this->comment4Field = array();
                        $this->comment4Table = '';
                    }
                    break;
                case "table_data":
                    $this->tableData = false;
                    fwrite(
                        $this->oFh,
                        ";\n"
                    );
                    $this->insertRowField = array();
                    break;
                case "row":
                    array_walk($this->row, function (&$item, $key) {
                        $item = pg_escape_string($item);
                    });
                    if (empty($this->insertRowField) || ($this->maxBatchCount == $this->batchCount)) {
                        if ($this->maxBatchCount == $this->batchCount) {
                            fwrite(
                                $this->oFh,
                                ";\n"
                            );
                        }
                        $this->batchCount = 0;
                        $this->insertRowField = $this->row;
                        fwrite(
                            $this->oFh,
                            "INSERT INTO \"{$this->lastTable}\" (\"" . join(
                                "\",\"",
                                array_keys($this->insertRowField)
                            ) . "\") VALUES \n"
                        );
                    } else {
                        fwrite(
                            $this->oFh, ",\n"
                        );
                    }
                    foreach ($this->row as $n=>&$v) {
                        if (($v == '') && (isset($this->tableFields['null'])) && ($this->tableFields['null'][$n] === true)) {
                            $v = 'NULL';
                        } else {
                            $v = "'".$v."'";
                        }
                    }
                    unset($v);

                    fwrite(
                        $this->oFh,
                        "(" . implode(', ', $this->row). ")"
                    );
                    
                    $this->batchCount ++;
                    break;
                case "field":
                    if ($this->tableData) {
                        $this->fieldOpen = false;
                    }
                    break;
            }
        }

        private function data($parser, $data)
        {
            if ($this->tableData && $this->fieldOpen) {
                if ("0000-00-00 00:00:00" == $data or "0000-00-00" == $data) {
                    $data = null;
                }
                $this->row[$this->lastRow] = ((isset($this->row[$this->lastRow])) ? $this->row[$this->lastRow] : '') . $data;
            }
        }

        private function convert_field_data($attrs)
        {
            if (isset($attrs) && ! empty($attrs) && isset($attrs['Field'])) {
                //Set field name
                $fieldStr = '"' . $attrs['Field'] . '"' . " ";

                //Set field type
                if ($attrs['Extra'] == "auto_increment") {
                    $fieldStr .= "serial ";
                } elseif (substr($attrs['Type'], 0, 3) == "int"
                    || substr($attrs['Type'], 0, 9) == "mediumint") {
                    $fieldStr .= "integer ";
                } elseif (substr($attrs['Type'], 0, 6) == "bigint") {
                    $fieldStr .= "bigint ";
                } elseif (substr($attrs['Type'], 0, 6) == "double") {
                    $fieldStr .= "money ";
                } elseif (substr($attrs['Type'], 0, 7) == "tinyint") {
                    $fieldStr .= "smallint ";
                } elseif (substr($attrs['Type'], 0, 8) == "smallint") {
                    $fieldStr .= "smallint ";
                } elseif (substr($attrs['Type'], 0, 5) == "float") {
                    $fieldStr .= "real ";
                } elseif (substr($attrs['Type'], 0, 7) == "decimal") {
                    $fieldStr .= "decimal ";
                } elseif (substr($attrs['Type'], 0, 4) == "blob") {
                    $fieldStr .= "bytea ";
                } elseif (substr($attrs['Type'], 0, 7) == "varchar") {
                    $varcharLen = array();
                    if (preg_match('/\(([0-9]+)\)/', $attrs['Type'], $varcharLen) === 1) {
                        $varcharLen = isset($varcharLen[1]) ? $varcharLen[1] : "0";
                        $fieldStr .= "varchar({$varcharLen}) ";
                    } else {
                        $fieldStr .= "text ";
                    }
                } elseif (($attrs['Type'] == "datetime") || ($attrs['Type'] == "timestamp")) {
                    $fieldStr .= "{$this->timestampType} ";
                } elseif (($attrs['Type'] == "mediumtext") || ($attrs['Type'] == "tinytext") || ($attrs['Type'] == "longtext")
                ) {
                    $fieldStr .= "text ";
                } elseif (substr($attrs['Type'], 0, 4) == "enum") {
                    //Create custom type
                    $dataType                              = "\"" . $this->tableFields['name'] . "_enum_" . $attrs['Field'] . "\"";
                    $this->tableFields["types"][$dataType] = "\"" . $dataType . "\" as " . $attrs['Type'];
                    $fieldStr .= $dataType . " ";
                } else {
                    $fieldStr .= $attrs['Type'] . " ";
                }

                //Set extra field
                $null = true;
                if ($attrs['Null'] == "NO") {
                    $fieldStr .= "NOT NULL ";
                    $null = false;
                }

                if (isset($attrs['Default'])) {
                    if ($attrs['Default'] != "") {
                        //TODO: See more cases!
                        if (substr($attrs['Type'], 0, 4) != "enum"
                            && (is_numeric($attrs['Default']) || in_array($attrs['Default'], $this->defaultWords))) {
                            $fieldStr .= " DEFAULT " . $attrs['Default'];
                        } else {
                            $fieldStr .= " DEFAULT '" . $attrs['Default'] . "'";
                        }
                    }
                }

                $this->tableFields["fields"][] = $fieldStr;
                $this->tableFields["null"][$attrs['Field']] = $null;

                if ($attrs['Key'] == "PRI") {
                    $this->tableFields["primary"][] = $attrs['Field'];
                }

                if (isset($attrs['Comment']) && strlen($attrs['Comment']) > 0) {
                    $this->comment4Field[$attrs['Field']] = $attrs['Comment'];
                }
            }
        }

        private function convert_key_data($attrs)
        {
            if ($attrs['Key_name'] != "PRIMARY") {
                if (! array_key_exists("key", $this->tableFields)) {
                    $this->tableFields["key"] = array();
                }

                $keyName = $attrs['Key_name'];
                if (! array_key_exists($keyName, $this->tableFields["key"])) {
                    $this->tableFields["key"][$keyName] = array( "fields" => array(), "uniq" => false );
                }
                $this->tableFields["key"][$keyName]["fields"][] = $attrs['Column_name'];
                if ($attrs['Non_unique'] == 0) {
                    $this->tableFields["key"][$keyName]["uniq"] = true;
                }
            }
        }

        private function setSeqValue($attrs)
        {
            if (isset($attrs['Auto_increment'])) {
                $this->seq = "SELECT setval('\"{$attrs['Name']}_{$this->tableFields["primary"][0]}_seq\"', {$attrs['Auto_increment']}, true);\n";
            }
        }
    }
