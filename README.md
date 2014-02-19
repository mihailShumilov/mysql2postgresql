mysql2postgresql
================

Converter mysql schema to postgresql

Usage

1. Create schema dump in xml format using command: `mysqldump --xml -d -u USER_NAME DB_NAME > DUMP_FILE_NAME`
2. Run converter using command: `php convertor.php -i DUMP_FILE_NAME -o PSQL_FILE_NAME`