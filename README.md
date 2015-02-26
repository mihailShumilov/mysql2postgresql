mysql2postgresql
================

Converter mysql schema and data to postgresql

Usage

1. Create dump in xml format using command: `mysqldump --xml -u USER_NAME DB_NAME > DUMP_FILE_NAME`
2. Run converter using command: `php convertor.php -i DUMP_FILE_NAME -o PSQL_FILE_NAME`


Additional options

* `-b50` - set batch count (used on insert data). By default batch count = 200


Restriction

This converter does not support foreign keys, because mysql does not return foreign key in xml dump 