<?php
/*
Script Name: ConvertDbCharset (CDC)


@author Bob Ray (<http://bobsguides.com) 2010, updated 2012

This PHP script is based on a script by Anders Stalheim Oefsdahl for converting a WordPress database to UTF-8. Copyright 2007  Anders Stalheim Oefsdahl  (email : anders@apt.no)

Detail at http://www.mydigitallife.info/2007/07/22/how-to-convert-character-set-and-collation-of-wordpress-database/

 It should convert any database to any charset/collation. Set the variables at the beginning of the script to the appropriate values for your database.

Bug fix by My Digital Life on 22 June 2007.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/


/* Create a cdc.config.php file based on cdc.config.sample.php and set the variables in that file */

require 'cdc.config.php';

$headerOutput = '';
$errorOutput = '';
$sqlOutput = '';
$debugOutput = '';

addLine($headerOutput, 'Convert Database Charset');

addLine($headerOutput, "Note: Running this script will not alter your database in any way. It simply generates SQL queries that you can use to make the changes. Nothing is changed until you paste the SQL statements into PhpMyAdmin and click on \"Go.\" Be sure you have selected the database (rather than a single table) before you click on the SQL tab in PhpMyAdmin and paste the queries.\n");

addLine($headerOutput, "BACK UP your database before altering it!!!\n");

addLine($headerOutput, "create a cdc.config.php file based on the cdc.config.sample.php file and set the variables at the beginning of the file to the appropriate values for your database.\n");

addLine($headerOutput, "The script generates a very long SQL statement that can be pasted into the SQL window in PhpMyAdmin to do the conversion on your database by temporarily setting just the text-containing fields to BLOB, changing the table's charset, and setting them back to their respective types. Indexes (including compound indexes), Defaults, Null settings, Unique settings, and comments are automatically preserved.\n");

addLine($headerOutput, "If a table has multiple indexes, the script may also make harmless changes in the order of some of those indexes. It will not change the order of components of a compound index. Text-based foreign keys (though very rare and generally a bad idea) may cause trouble.\n");

addLine($headerOutput, "IMPORTANT: Be sure any site using the database is offline (e.g. by renaming index.php) before executing the SQL statements in PhpMyAdmin!\n");

addLine($headerOutput, "DB name: ".$dbName."\n");


/* connect and select DB */
$connection = mysql_connect($host, $userName, $password, true);
if (! $connection ) {
    die('Failed to connect to database');
}

if (!@ mysql_select_db($dbName)) {
            die('Failed to select the database ' . $dbName);
}


//*************************************************************
$sql_drop_indexes   = '';    // string to hold SQL statements to drop indexes on text fields
$sql_to_blob        = '';    // string to hold SQL statements to convert text fields to blobs
$sql_to_target        = '';    // string to hold SQL statements to convert tables to uft8
$sql_to_original    = '';    // string to hold SQL statements to convert text fields back to their original format
$sql_restore_indexes= '';    // string to hold SQL statements to restore indexes on text fields
$sql_fix_fields = '';        // string to hold SQL statements to restore fields null and default values.
$sql_restore_multiples = ''; // string to hold SQL statements to restore compound indexes.
$multiples = array();

/* Create the array of tables */

$tables = array();
$sql_tables = "SHOW TABLES";
$res_tables = getResults($sql_tables, $connection);

if ($cdc_debug) {
    addline($debugOutput, "\nTABLES:");
}

$field = "Tables_in_".$dbName;
if( is_array( $res_tables ) ){
    foreach( $res_tables as $res_table ){
        $tables[$res_table->$field] = array();
        if ($cdc_debug) {
            addline($debugOutput, $res_table->$field);
        }
    }
}



/**
 * Loop all tables fetching each table's fields and filter out fields of type CHAR, VARCHAR, TEXT, ENUM, and SET (and related variations)
 * and dropping indexes on those fields
 */
if( count( $tables )>0 ){
    foreach( $tables as $table=>$fields ){
        if ($table == 'modx_user_settings') {
            $x = 1;
        }
        $multiples = array();
        if ($cdc_debug) {
            addLine($debugOutput, "******************************************************************\nTABLE: ".$table .
            "\n******************************************************************\n");
        }

        $sql_fields = "SHOW FULL COLUMNS FROM " . $table;

        $sql_fields_index = "SHOW INDEX FROM ".$table;
        $res_fields = getResults($sql_fields, $connection);
        $res_fields_index = getResults($sql_fields_index, $connection);

if ($cdc_debug) {

    addline($debugOutput, "\nRES FIELDS:\n");
    addline($debugOutput,  print_r($res_fields,true)) ;
    addline($debugOutput, "\n******************************************\n");
    addline($debugOutput, "RES FIELDS INDEX\n");
    addline($debugOutput, print_r($res_fields_index, true));
}




     /* convert stdObject $res_fields_indes to an abbreviated array */
     $idxs = array();
     $i=0;
     if (is_array($res_fields_index) ) { /* skip if table has no index */
         foreach($res_fields_index as $index) {
             $idxs[$i]['Keyname'] = $index->Key_name ;
             $idxs[$i]['Columname'] = $index->Column_name ;
             $idxs[$i]['Indextype'] = $index->Index_type ;
             $idxs[$i]['Seqnumber'] = $index->Seq_in_index;
             $idxs[$i]['Null'] = $index->Null;

            $i++;
         }

     }
     /* Create table of compound keys and their comma-separated column names. e.g.:
      *     Multiples Array(
      *         [content_ft_idx] => 'pagetitle,description,content'
      *     )
      */

      /* array used for the compound index column names */
      $temp = array();
      $multiples = array();

        if ($cdc_debug) {
            addline($debugOutput, "\nIDX Array\n");
            addline($debugOutput, print_r($idxs, true));
        }

      /* create the string used for a compound index */

     foreach($idxs as $idx) {
         if (hasDuplicate($idxs, $idx['Keyname'])) {
             if (empty($multiples[$idx['Keyname']])) {
                $s = doCompound ($idxs, $multiples, $idx['Keyname']);
                $multiples[$idx['Keyname']] = $s;
             }
         }
     } /* end foreach $idxs */

if ($cdc_debug) {
     if (! empty($multiples[$idx['Keyname']])) {
         addline($debugOutput, $table . "\nCompound Keys:");
         addline($debugOutput, print_r($multiples, true));
     }

}

        /* foreach text field - create appropriate DROP/ADD index SQL and SQL to restore comments, UNIQUE, DEFAULT, and NOT NULL */
        if( is_array( $res_fields ) ){
            if ($cdc_debug) {
                addline($debugOutput, "\nTEXT FIELDS:");
            }
            $done = array();
            foreach( $res_fields as $field ){

                switch( TRUE ){

                    case strpos( strtolower( $field->Type ), 'char' )===0:
                    case strpos( strtolower( $field->Type ), 'varchar' )===0:
                    case strpos( strtolower( $field->Type ), 'text' )===0:
                    case strpos( strtolower( $field->Type ), 'enum' )===0:
                    case strpos( strtolower( $field->Type ), 'set' )===0:
                    case strpos( strtolower( $field->Type ), 'tinytext' )===0:
                    case strpos( strtolower( $field->Type ), 'mediumtext' )===0:
                    case strpos( strtolower( $field->Type ), 'longtext' )===0:
                        $tables[$table][$field->Field] = $field->Type;
                        if ($cdc_debug) {
                            addline($debugOutput, 'Field: ' . $field->Field . " " . $field->Type . ', Key:' . $field->Key);
                        }

                        /* add SQL to convert field to BLOB and back again */
                        $sql_to_blob .= "\nALTER TABLE ".$table." MODIFY ". '`' . $field->Field . '`' . " BLOB;";
                        $sql_to_original .= "\nALTER TABLE ".$table." MODIFY ". '`' . $field->Field . '`' .  " ".$field->Type. " CHARACTER SET " . $targetCharset . " COLLATE " . $targetCollation . ";";

                        /* Create SQL to restore comments, UNIQUE, DEFAULT, and NOT NULL  */
                        if (!empty($field->Null) || !empty($field->Default) || !empty($field->Comment) ) {
                            $fix = "\nALTER TABLE ".$table." MODIFY ". '`'. $field->Field . '`' . " " . $field->Type. " CHARACTER SET " . $targetCharset . " COLLATE " . $targetCollation;
                            if ($field->Null == 'NO') {
                                $fix .= ' NOT NULL';
                            }
                            if (!empty($field->Default) ) {
                                $fix .= ' DEFAULT ' . "'" . $field->Default . "'";
                            } else if ($field->Default ==='') {
                                /* handle defaults set to empty string */
                                $fix .= ' DEFAULT ' . "''";
                            } else if ($field->Default === NULL) {
                                /* do nothing */
                            } else if ($field->Default === '0') {
                                /* handle defaults set to '0' */
                                $fix .= ' DEFAULT ' . "'0'";
                            }
                            if (!empty($field->Comment) ) {
                                $fix .= ' COMMENT ' . "'" . $field->Comment . "'";
                            }
                            /* add terminator */
                            $fix .= ';';
                            /* add final result to the array */
                            $sql_fix_fields .= $fix;

                        }


                         if( ! empty($field->Key)) {
                            /*if ($cdc_debug) {
                                echo "  *** It's a key -- i=".$i.", Keyname = ".$field->Field . ', Null = ' . $field->Null . ', Default = ' . $field->Default . ', Name = ' . $field->Name;
                            }*/

                            foreach($res_fields_index as $index_item) {     // walk though the indexes until we find it.
                                if ($index_item->Column_name == $field->Field) {

                                    $key=$index_item->Key_name;
                                    $column=$index_item->Column_name;
                                    if ($cdc_debug) {
                                        addline($debugOutput, ", indexName = ".$key.", ColumnName=".$column."\n");
                                    }
                                    break; // new
                                }
                            }
                            /* handle compound indexes (in the $multiples array created earlier).
                             * Note: This only happens for compound indexes with text fields in them.
                             * Other compound indexes won't be dropped in the first place.
                             */
                             if (in_array($key,$done)) {
                                 continue;
                             }
                             $done[] = $key;
                            if (array_key_exists($key,$multiples)) {
                                if ($table == 'modx_context_resource') {
                                    $x = 1;
                                }
                               $prefix = "\nALTER TABLE ". $table . ' ADD ';
                               $type = $index_item->Index_type;
                               $extra = '';
                               if ($key == 'PRIMARY') {
                                   $type = '';
                                   $extra = ' KEY';
                               }
                               if ($type == 'BTREE') {
                                   $type = 'UNIQUE';
                               }

                               $prefix .= $type . ' ';

                               if ($key == 'PRIMARY') {
                                   $sql_drop_indexes .= "\nALTER TABLE ".$table." DROP PRIMARY KEY " . ";";
                                   $prefix .= $key . $extra . '(' . $multiples[$key] . ');';
                               } else {
                                   $sql_drop_indexes .= "\nALTER TABLE ".$table." DROP INDEX ". '`' . $key . '`' . ";";
                                   $prefix .= '`' . $key . '`' . $extra . '(' . $multiples[$key] . ');';
                               }
                               /* restoration happens after everything else it finished to make sure fields are there */
                               $sql_restore_multiples .= $prefix;
                            } else {     //handle everything else
                                if ($key == 'PRIMARY') {
                                    $sql_drop_indexes .= "\nALTER TABLE ".$table." DROP PRIMARY KEY ".";";
                                } else {
                                    $sql_drop_indexes .= "\nALTER TABLE ".$table." DROP INDEX ". '`' .$key . '`'.";";
                                /* Identify 'PRI' and 'UNIQUE'  indexes
                                 * Key = UNI or PRI means a UNIQUE index. (ADD UNIQUE instead of ADD INDEX)
                                 *  */
                                }
                                if ($key != 'PRIMARY') {

                                    if ($field->Key== 'UNI' || $field->Key == 'PRI') {
                                        $sql_restore_indexes .= "\nALTER TABLE ".$table." ADD UNIQUE ". '`'. $key . '`'."(". '`' . $column. '`'.")".";";
                                    } elseif ($index_item->Index_type == 'FULLTEXT') {
                                        $sql_restore_indexes .= "\nALTER TABLE ".$table." ADD FULLTEXT ". '`' . $key. '`'."(". '`' . $column . '`' .")".";";
                                    } else {
                                        $sql_restore_indexes .= "\nALTER TABLE ".$table." ADD INDEX ". '`' . $key. '`'."(". '`' . $column . '`' .")".";";
                                    }
                                } else {
                                    $sql_restore_indexes .= "\nALTER TABLE ".$table." ADD PRIMARY KEY ". "(". '`' . $column . '`' .")".";";
                                }
                            }
                        }

                        break;

                    default:
                        /* do nothing with non-text fields */
                        break;

                } /* end switch */
            }  /* end foreach $res_fields */
        } else {  /* end if (is_array($res_fields)) */
            addLine($errorOutput, "***************** $res_fields is not an Array ***********************");
        }

    }  /* end foreach $tables */
} /* end if( count( $tables )>0 ) */

if ($cdc_debug) {
    addline($debugOutput, "\nMULTIPLES:");
    addline($debugOutput, print_r($multiples, true) . "\n\n");
}

mysql_close($connection);

foreach( $tables as $table=>$fields ){
    $sql_to_target .= "\nALTER TABLE ".$table." DEFAULT CHARACTER SET " . $targetCharset . " COLLATE " . $targetCollation .  ";";
}

$complete_sql = $sql_drop_indexes . $sql_to_blob . "\nALTER DATABASE " . $dbName." CHARSET " . $targetCharset ." COLLATE " . $targetCollation . ";". $sql_to_target . $sql_to_original . $sql_fix_fields .  $sql_restore_indexes . $sql_restore_multiples;

if ($showHeaders) {
    $headerOutput = "Change Database Charset for DB: " . $dbName . "\n\n" . $headerOutput;
    $complete_sql = "SQL to paste into PhpMyAdmin (paste the whole block):\n" . $complete_sql;
    $debugOutput = "\nDebugging Information\n" . $debugOutput;
}

if ($convertNewlines) {
    $headerOutput = nl2br($headerOutput);
    $errorOutput = nl2br($errorOutput);
    $complete_sql = nl2br($complete_sql);
    $debugOutput = nl2br($debugOutput);
}

if ($cdc_debug) {
    echo $debugOutput;
}

if ($showHeaders) {
    echo $headerOutput;
}
if (!empty($errorOutput)) {
    $msg = "\n\nErrors\n";
    if ($convertNewlines) {
        $msg = nl2br($msg);
    }
    echo $msg . $errorOutput;
}

if ($showSql) {
    echo $complete_sql;
}

return '';

function getResults($sql, $connection) {

    $result = mysql_query($sql, $connection);

    $num_rows = 0;

    while ($row = getRow($result) ) {
          $final[$num_rows] = $row;
          $num_rows++;
    }
    return $final;
}

function hasDuplicate($arr,$keyname) {
  $count = 0;
  foreach ($arr as $row) {
     if ($row['Keyname'] === $keyname) {
       $count++;
     }
  }
  return ($count >1);

}

/**
 * @param $ds
 * @param string $mode
 * @return array|object|stdClass
 */
function getRow($ds, $mode = 'assoc') {
      if ($ds) {
         if ($mode == 'assoc') {
           // return mysql_fetch_assoc($ds);
            return mysql_fetch_object($ds);
         }
         elseif ($mode == 'num') {
            return mysql_fetch_row($ds);
         }
         elseif ($mode == 'both') {
            return mysql_fetch_array($ds, MYSQL_BOTH);
         } else {
            addError("Unknown get type ($mode) specified for fetchRow - must be empty, 'assoc', 'num' or 'both'.");
         }
      }
    return null;
   }


/* create string to use in compound indexes */
function doCompound($idxs, $multiples, $keyName) {
    /* use sequence number to order subindexes */
    foreach($idxs as $idx) {
        if ($idx['Keyname'] == $keyName) {
            $temp[$idx['Seqnumber']] = '`' . $idx['Columname'] . '`';
        }
    }
    return implode(',',$temp);
}

function addLine(&$target, $msg) {
    $target .= $msg . "\n";
}