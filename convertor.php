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
$row = array();
$lastTable = null;
$lastRow = null;
$tableData = false;
$fieldOpen = false;

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
    fclose($fh);
} else {
    die("File {$iFilePath} does not exists");
}


function startElement($parser, $name, $attrs)
{
    global $fh;
    global $tableFields;
    global $tableData;
    global $lastTable;
    global $row;
    global $lastRow;
    global $fieldOpen;

    switch ($name) {
        case "table_structure":
            $tableFields         = array();
            $tableFields['name'] = $attrs['name'];
            break;
        case "field":
            if ($tableData) {
                $row[$attrs['name']] = null;
                $lastRow             = $attrs['name'];
                $fieldOpen           = true;
            } else {
                convert_field_data($attrs);
            }
            break;
        case "key":
            convert_key_data($attrs);
            break;
        case "table_data":
            $tableData = true;
            $lastTable = $attrs['name'];
            break;
        case "row":
            $row = array();
            break;
    }

}

function endElement($parser, $name)
{
    global $fh;
    global $tableFields;
    global $tableData;
    global $lastTable;
    global $row;
    global $lastRow;
    global $fieldOpen;
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
        case "table_data":
            $tableData = false;
            break;
        case "row":
            fwrite(
                $fh,
                "INSERT INTO {$lastTable} (\"" . join("\",\"", array_keys($row)) . "\") VALUES (E'" . join(
                    "',E'",
                    $row
                ) . "');\n"
            );
            break;
        case "field":
            if ($tableData) {
                $fieldOpen = false;
            }
            break;
    }

}

function characterData($parser, $data)
{
    global $tableData;
    global $lastTable;
    global $row;
    global $lastRow;
    global $fieldOpen;

    if ($tableData && $fieldOpen) {
        $data = addslashes($data);
        if ("0000-00-00 00:00:00" == $data) {
            $data = "1971-01-01 00:00:01";
        }
        $row[$lastRow] = $data;

    }
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

        if (!array_key_exists("key", $tableFields)) {
            $tableFields["key"] = array();
        }

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


