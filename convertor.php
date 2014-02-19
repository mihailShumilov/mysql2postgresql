<?php
/**
 * Created by PhpStorm.
 * User: mihailshumilov
 * Date: 22.11.13
 * Time: 09:30
 */

$options = getopt("i:o:", array("input-file:", "output-file:"));

$iFilePath = $options["i"] ? $options["i"] : $options["input-file"];
$oFilePath = $options["o"] ? $options["o"] : $options["output-file"];

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

$dumpData = ob_get_contents();
ob_end_clean();

$fh = fopen($oFilePath, "w+");
fwrite($fh, $dumpData);
fclose($fh);