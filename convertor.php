<?php
    /**
     * Created by PhpStorm.
     * User: mihailshumilov
     * Date: 22.11.13
     * Time: 09:30
     */

    require( "Convertor.class.php" );

    $options = getopt( "i:o:b::", array( "input-file:", "output-file:", "batch-count::" ) );

    $iFilePath  = $options["i"] ? $options["i"] : $options["input-file"];
    $oFilePath  = $options["o"] ? $options["o"] : $options["output-file"];
    $batchCount = $options["b"] ? $options["b"] : ( $options["batch-count"] ? $options["batch-count"] : false );

    $conv = new Convertor( array(
        "iFileName"  => $iFilePath,
        "oFileName"  => $oFilePath,
        "batchCount" => $batchCount
    ) );
    $conv->run();

    exit( 0 );


