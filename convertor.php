<?php
/**
 * Created by PhpStorm.
 * User: mihailshumilov
 * Date: 22.11.13
 * Time: 09:30
 */

$options = getopt("i:o:", array("input-file:", "output-file:"));

$iFilePath   = $options["i"] ? $options["i"] : $options["input-file"];
$oFilePath   = $options["o"] ? $options["o"] : $options["output-file"];
$fh          = null;
$tableFields = array();

if (file_exists($iFilePath)) {

    if ($fh = fopen($oFilePath, "w+")) {

    } else {
        die("Can't open {$oFilePath} to write");
    }

    $xml = xml_parser_create();

    xml_set_element_handler($xml, 'startElement', 'endElement');
    xml_set_character_data_handler($xml, 'characterData');
    xml_parser_set_option($xml, XML_OPTION_CASE_FOLDING, false);

    $fp = fopen($iFilePath, 'r') or die("Can't read XML data.");
    while ($data = fread($fp, 4096)) {
        xml_parse($xml, $data, feof($fp)) or die("Can't parse XML data");
    }
    fclose($fp);

    xml_parser_free($xml);
} else {
    die("File {$iFilePath} does not exists");
}


function startElement($parser, $name, $attrs)
{
    global $fh;
    global $tableFields;
    print "--------- Start element -----------\n";

    switch ($name) {
        case "table_structure":
//            fwrite($fh, "CREATE TABLE {$attrs['name']} (\n");
            $tableFields         = array();
            $tableFields['name'] = $attrs['name'];
            break;
        case "field":
            convert_field_data($attrs);
            break;
        case "key":
            convert_key_data($attrs);
            break;
    }

    print $name . "\n";
    print_r($attrs);
}

function endElement($parser, $name)
{
    global $fh;
    global $tableFields;
    switch ($name) {
        case "table_structure":

            if (array_key_exists("types", $tableFields)) {
                foreach ($tableFields["types"] as $customType) {
                    fwrite($fh, "CREATE TYPE " . $customType . ";\n");
                }
            }

            fwrite($fh, "\nDROP TABLE IF EXISTS {$tableFields['name']};\n");

            fwrite($fh, "\nCREATE TABLE {$tableFields['name']} (\n");
            fwrite($fh, "\t");
            fwrite($fh, join(",\n\t", $tableFields["fields"]));

            if (!empty($tableFields["primary"])) {
                fwrite($fh, ",\n\t" . 'PRIMARY KEY ("' . join('","', $tableFields["primary"]) . '")' . "\n");
            }
            fwrite($fh, "\n);\n");

            if (array_key_exists("key", $tableFields)) {
                foreach ($tableFields["key"] as $keyName => $keyData) {
                    fwrite(
                        $fh,
                        "CREATE " . ($keyData["uniq"] ? "UNIQUE " : "") . "INDEX \"" . $tableFields['name'] . "_" . $keyName . "\" ON {$tableFields['name']} (" . join(
                            ",",
                            $keyData['fields']
                        ) . ");\n"
                    );
                }
            }
            break;
    }

    print $name . "\n";
    print "--------- End element ---------\n";
}

function characterData($parser, $data)
{
    print "-- start data --\n";
    print $data;
    print "-- end data --\n";
}

function convert_field_data($attrs)
{
    global $tableFields;
    if (isset($attrs) && !empty($attrs) && isset($attrs['Field'])) {
        //Set field name
        $fieldStr = '"' . $attrs['Field'] . '"' . " ";

        //Set field type
        if ($attrs['Extra'] == "auto_increment") {
            $fieldStr .= "serial ";
        } elseif (substr($attrs['Type'], 0, 3) == "int") {
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
        } elseif ($attrs['Type'] == "datetime") {
            $fieldStr .= "timestamp ";
        } elseif (($attrs['Type'] == "mediumtext") || ($attrs['Type'] == "tinytext")
        ) {
            $fieldStr .= "text ";
        } elseif (substr($attrs['Type'], 0, 4) == "enum") {
            //Create custom type
            $dataType               = "enum_" . $attrs['Field'];
            $tableFields["types"][] = $dataType . " as " . $attrs['Type'];
            $fieldStr .= $dataType . " ";
        } else {
            $fieldStr .= $attrs['Type'] . " ";
        }

        //Set extra field
        if ($attrs['Null'] == "NO") {
            $fieldStr .= "NOT NULL ";
        }
        $tableFields["fields"][] = $fieldStr;

        if ($attrs['Key'] == "PRI") {
            $tableFields["primary"][] = $attrs['Field'];
        }

    }
}

function convert_key_data($attrs)
{
    global $tableFields;
    if ($attrs['Key_name'] != "PRIMARY") {

        $keyName = $attrs['Key_name'];
        if (!array_key_exists($keyName, $tableFields["key"])) {
            $tableFields["key"][$keyName] = array("fields" => array(), "uniq" => false);
        }
        $tableFields["key"][$keyName]["fields"][] = $attrs['Column_name'];
        if ($attrs['Non_unique'] == 0) {
            $tableFields["key"][$keyName]["uniq"] = true;
        }
    }
}

exit(0);

$oData = array();

$oXml = simplexml_load_file($iFilePath);


ob_start();
$aDBs = (array)$oXml->{'database'};


foreach ($aDBs['table_structure'] as $key => $val) {
    $tableName         = (string)$val->attributes()->name;
    $oData[$tableName] = array("fields" => array(), "types" => array(), "key" => array(), "primary" => array());
    foreach ($val->field as $fieldData) {
        //Set field name
        $fieldStr = '"' . (string)$fieldData->attributes()->Field . '"' . " ";

        //Set field type
        if ((string)$fieldData->attributes()->Extra == "auto_increment") {
            $fieldStr .= "serial ";
        } elseif (substr((string)$fieldData->attributes()->Type, 0, 3) == "int") {
            $fieldStr .= "integer ";
        } elseif (substr((string)$fieldData->attributes()->Type, 0, 6) == "bigint") {
            $fieldStr .= "bigint ";
        } elseif (substr((string)$fieldData->attributes()->Type, 0, 6) == "double") {
            $fieldStr .= "money ";
        } elseif (substr((string)$fieldData->attributes()->Type, 0, 7) == "tinyint") {
            $fieldStr .= "smallint ";
        } elseif (substr((string)$fieldData->attributes()->Type, 0, 8) == "smallint") {
            $fieldStr .= "smallint ";
        } elseif (substr((string)$fieldData->attributes()->Type, 0, 5) == "float") {
            $fieldStr .= "real ";
        } elseif ((string)$fieldData->attributes()->Type == "datetime") {
            $fieldStr .= "timestamp ";
        } elseif (((string)$fieldData->attributes()->Type == "mediumtext") || ((string)$fieldData->attributes(
                )->Type == "tinytext")
        ) {
            $fieldStr .= "text ";
        } elseif (substr((string)$fieldData->attributes()->Type, 0, 4) == "enum") {
            //Create custom type
            $dataType                     = $tableName . "_" . (string)$fieldData->attributes()->Field;
            $oData[$tableName]["types"][] = $dataType . " as " . (string)$fieldData->attributes()->Type;
            $fieldStr .= $dataType . " ";
        } else {
            $fieldStr .= (string)$fieldData->attributes()->Type . " ";
        }

        //Set extra field
        if ((string)$fieldData->attributes()->Null == "NO") {
            $fieldStr .= "NOT NULL ";
        }
        $oData[$tableName]["fields"][] = $fieldStr;

        if ((string)$fieldData->attributes()->Key == "PRI") {
            $oData[$tableName]["primary"][] = (string)$fieldData->attributes()->Field;
        }
    }
    foreach ($val->key as $keyData) {
        if ($keyData->attributes()->Key_name != "PRIMARY") {
//            print_r($keyData);
            $keyName = (string)$keyData->attributes()->Key_name;
            if (!array_key_exists($keyName, $oData[$tableName]["key"])) {
                $oData[$tableName]["key"][$keyName] = array("fields" => array(), "uniq" => false);
            }
            $oData[$tableName]["key"][$keyName]["fields"][] = (string)$keyData->attributes()->Column_name;
            if ((int)$keyData->attributes()->Non_unique == 0) {
                $oData[$tableName]["key"][$keyName]["uniq"] = true;
            }
        }
    }
}

foreach ($oData as $tableName => $desc) {
    foreach ($oData[$tableName]["types"] as $customType) {
        echo "CREATE TYPE " . $customType . ";\n";
    }

    echo "\nDROP TABLE IF EXISTS $tableName;\n";

    echo "\nCREATE TABLE $tableName (\n";
    echo "\t";
    echo join(",\n\t", $oData[$tableName]["fields"]);

    if (!empty($oData[$tableName]["primary"])) {
        echo ",\n\t" . 'PRIMARY KEY ("' . join('","', $oData[$tableName]["primary"]) . '")' . "\n";
    }
    echo ");\n";

    foreach ($oData[$tableName]["key"] as $keyName => $keyData) {
        echo "CREATE INDEX \"" . $tableName . "_" . $keyName . "\" ON $tableName (" . join(
                ",",
                $keyData['fields']
            ) . ");\n";
    }
}

foreach ($aDBs['table_data'] as $k => $tableData) {
    $tableName = (string)$tableData->attributes()->name;
    if (isset($tableData->row)) {

        foreach ($tableData->row as $row) {
            $fieldData = (array)$row->field;
            unset($fieldData['@attributes']);
            array_walk(
                $fieldData,
                function (&$tv, $tk) {
                    $tv = addslashes($tv);
                    if ("0000-00-00 00:00:00" == $tv) {
                        $tv = "1971-01-01 00:00:01";
                    }
                }
            );
            echo "INSERT INTO $tableName VALUES(E'" . join("',E'", $fieldData) . "');\n";
        }

    }
    echo "VACUUM FULL $tableName;\n";
}

$dumpData = ob_get_contents();
ob_end_clean();

$fh = fopen($oFilePath, "w+");
fwrite($fh, $dumpData);
fclose($fh);