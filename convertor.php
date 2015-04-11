<?php
    /**
     * Created by PhpStorm.
     * User: mihailshumilov
     * Date: 22.11.13
     * Time: 09:30
     */

    require( "Convertor.class.php" );

    $options = getopt( "i:o:b::", array( "input-file:", "output-file:", "batch-count::" ) );

    $iFilePath  = isset( $options["i"] ) ? $options["i"] : $options["input-file"];
    $oFilePath  = isset( $options["o"] ) ? $options["o"] : $options["output-file"];
    $batchCount = isset( $options["b"] ) ? $options["b"] : ( isset( $options["batch-count"] ) ? $options["batch-count"] : false );

    $conv = new Convertor( array(
        "iFileName"  => $iFilePath,
        "oFileName"  => $oFilePath,
        "batchCount" => $batchCount
    ) );
    $conv->run();

    exit( 0 );


