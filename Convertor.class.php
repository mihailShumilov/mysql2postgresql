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

        private $iFh;
        private $oFh;

        private $tableFields = array();
        private $row = array();
        private $lastTable = null;
        private $lastRow = null;
        private $tableData = false;
        private $fieldOpen = false;
        private $timestampType = "timestamp with time zone";
        private $seq = false;
        private $insertRowField = array();

        public function __construct( $params )
        {
            if (isset( $params['iFileName'] ) && ! empty( $params['iFileName'] )) {
                $this->iFileName = $params['iFileName'];
            } else {
                throw new Exception( "Input file name not set" );
            }
            if (isset( $params['oFileName'] ) && ! empty( $params['oFileName'] )) {
                $this->oFileName = $params['oFileName'];
            } else {
                throw new Exception( "Output file name not set" );
            }
            $this->checkFiles();
        }

        private function checkFiles()
        {
            if (file_exists( $this->iFileName )) {
                if ($this->iFh = @fopen( $this->iFileName, "r" )) {
                    //if input file success try to open output file
                    if ($this->oFh = @fopen( $this->oFileName, "w+" )) {

                    } else {
                        throw new Exception( "Can't open `{$this->oFileName}` to write" );
                    }
                } else {
                    throw new Exception( "Can't open `{$this->iFileName}`" );
                }
            } else {
                throw new Exception( "File `{$this->iFileName}` does not exists" );
            }
        }

        public function __descruct()
        {
            fclose( $this->iFh );
            fclose( $this->oFh );
        }

        public function run()
        {
            $xml = xml_parser_create();

            xml_set_element_handler( $xml, array( &$this, "start" ), array( &$this, "end" ) );
            xml_set_character_data_handler( $xml, array( &$this, "data" ) );
            xml_parser_set_option( $xml, XML_OPTION_CASE_FOLDING, false );

            $totalFileSize = filesize( $this->iFileName );
            $processed     = 0;

            echo "\nProcess:0%\r";
            while ($data = fread( $this->iFh, 4096 )) {
                xml_parse( $xml, $data, feof( $this->iFh ) ) or die( "Can't parse XML data" );
                $processed += 4096;
                $percentage = round( $processed / $totalFileSize * 100, 2 );
                echo "Processed: {$percentage}%\r";
            }
            xml_parser_free( $xml );
            echo "\r";
            echo "Processed: 100%\n";
        }

        private function start( $parser, $name, $attrs )
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
                        $this->convert_field_data( $attrs );
                    }
                    break;
                case "key":
                    $this->convert_key_data( $attrs );
                    break;
                case "table_data":
                    $this->tableData = true;
                    $this->lastTable = $attrs['name'];
                    break;
                case "row":
                    $this->row = array();
                    break;
                case "options":
                    if (isset( $attrs['Auto_increment'] )) {
                        $this->setSeqValue( $attrs );
                    }
                    break;
            }
        }

        private function end( $parser, $name )
        {
            switch ($name) {
                case "table_structure":

                    if (array_key_exists( "types", $this->tableFields )) {
                        foreach ($this->tableFields["types"] as $customTypeName => $customType) {
                            fwrite( $this->oFh, "DROP TYPE IF EXISTS {$customTypeName};\n" );
                            fwrite( $this->oFh, "CREATE TYPE " . $customType . ";\n" );
                        }
                    }

                    fwrite( $this->oFh, "\nDROP TABLE IF EXISTS {$this->tableFields['name']};\n" );

                    fwrite( $this->oFh, "\nCREATE TABLE {$this->tableFields['name']} (\n" );
                    fwrite( $this->oFh, "\t" );
                    fwrite( $this->oFh, join( ",\n\t", $this->tableFields["fields"] ) );

                    if ( ! empty( $this->tableFields["primary"] )) {
                        fwrite(
                            $this->oFh,
                            ",\n\t" . 'PRIMARY KEY ("' . join( '","', $this->tableFields["primary"] ) . '")' . "\n"
                        );
                    }
                    fwrite( $this->oFh, "\n);\n" );

                    if (array_key_exists( "key", $this->tableFields )) {
                        foreach ($this->tableFields["key"] as $keyName => $keyData) {
                            fwrite(
                                $this->oFh,
                                "CREATE " . ( $keyData["uniq"] ? "UNIQUE " : "" ) . "INDEX \"" . $this->tableFields['name'] . "_" . $keyName . "\" ON {$this->tableFields['name']} (" . join(
                                    ",",
                                    $keyData['fields']
                                ) . ");\n"
                            );
                        }
                    }
                    if ($this->seq) {
                        fwrite( $this->oFh, $this->seq );
                        $this->seq = false;
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
                    array_walk( $this->row, function ( &$item, $key ) {
                        $item = pg_escape_string( $item );
                    } );
                    if (empty( $this->insertRowField )) {
                        $this->insertRowField = $this->row;
                        fwrite(
                            $this->oFh,
                            "INSERT INTO {$this->lastTable} (\"" . join(
                                "\",\"",
                                array_keys( $this->insertRowField )
                            ) . "\") VALUES \n"
                        );
                    } else {
                        fwrite(
                            $this->oFh, ",\n"
                        );
                    }
                    fwrite(
                        $this->oFh,
                        "('" . join(
                            "','",
                            $this->row
                        ) . "')"
                    );
                    break;
                case "field":
                    if ($this->tableData) {
                        $this->fieldOpen = false;
                    }
                    break;
            }
        }

        private function data( $parser, $data )
        {
            if ($this->tableData && $this->fieldOpen) {
                $data = addslashes( $data );
                if ("0000-00-00 00:00:00" == $data) {
                    $data = "1971-01-01 00:00:01";
                }
                $this->row[$this->lastRow] = $data;

            }
        }

        private function convert_field_data( $attrs )
        {
            if (isset( $attrs ) && ! empty( $attrs ) && isset( $attrs['Field'] )) {
                //Set field name
                $fieldStr = '"' . $attrs['Field'] . '"' . " ";

                //Set field type
                if ($attrs['Extra'] == "auto_increment") {
                    $fieldStr .= "serial ";
                } elseif (substr( $attrs['Type'], 0, 3 ) == "int") {
                    $fieldStr .= "integer ";
                } elseif (substr( $attrs['Type'], 0, 6 ) == "bigint") {
                    $fieldStr .= "bigint ";
                } elseif (substr( $attrs['Type'], 0, 6 ) == "double") {
                    $fieldStr .= "money ";
                } elseif (substr( $attrs['Type'], 0, 7 ) == "tinyint") {
                    $fieldStr .= "smallint ";
                } elseif (substr( $attrs['Type'], 0, 8 ) == "smallint") {
                    $fieldStr .= "smallint ";
                } elseif (substr( $attrs['Type'], 0, 5 ) == "float") {
                    $fieldStr .= "real ";
                } elseif (( $attrs['Type'] == "datetime" ) || ( $attrs['Type'] == "timestamp" )) {
                    $fieldStr .= "{$this->timestampType} ";
                } elseif (( $attrs['Type'] == "mediumtext" ) || ( $attrs['Type'] == "tinytext" ) || ( $attrs['Type'] == "longtext" )
                ) {
                    $fieldStr .= "text ";
                } elseif (substr( $attrs['Type'], 0, 4 ) == "enum") {
                    //Create custom type
                    $dataType                              = $this->tableFields['name'] . "_enum_" . $attrs['Field'];
                    $this->tableFields["types"][$dataType] = $dataType . " as " . $attrs['Type'];
                    $fieldStr .= $dataType . " ";
                } else {
                    $fieldStr .= $attrs['Type'] . " ";
                }

                //Set extra field
                if ($attrs['Null'] == "NO") {
                    $fieldStr .= "NOT NULL ";
                }
                $this->tableFields["fields"][] = $fieldStr;

                if ($attrs['Key'] == "PRI") {
                    $this->tableFields["primary"][] = $attrs['Field'];
                }

            }
        }

        private function convert_key_data( $attrs )
        {
            if ($attrs['Key_name'] != "PRIMARY") {

                if ( ! array_key_exists( "key", $this->tableFields )) {
                    $this->tableFields["key"] = array();
                }

                $keyName = $attrs['Key_name'];
                if ( ! array_key_exists( $keyName, $this->tableFields["key"] )) {
                    $this->tableFields["key"][$keyName] = array( "fields" => array(), "uniq" => false );
                }
                $this->tableFields["key"][$keyName]["fields"][] = $attrs['Column_name'];
                if ($attrs['Non_unique'] == 0) {
                    $this->tableFields["key"][$keyName]["uniq"] = true;
                }
            }
        }

        private function setSeqValue( $attrs )
        {
            if (isset( $attrs['Auto_increment'] )) {
                $this->seq = "SELECT setval('{$attrs['Name']}_{$this->tableFields["primary"][0]}_seq', {$attrs['Auto_increment']});\n";
            }
        }
    }