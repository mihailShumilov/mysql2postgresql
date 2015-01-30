<?php
    /**
     * Created by PhpStorm.
     * User: mihailshumilov
     * Date: 22.11.13
     * Time: 09:30
     */

    require( "Convertor.class.php" );

    $options = getopt( "i:o:tz::", array( "input-file:", "output-file:", "timestamp-with-timezone::" ) );

    $iFilePath = $options["i"] ? $options["i"] : $options["input-file"];
    $oFilePath = $options["o"] ? $options["o"] : $options["output-file"];

    $conv = new Convertor( array(
        "iFileName" => $iFilePath,
        "oFileName" => $oFilePath
    ) );
    $conv->run();

    exit( 0 );


