#!/bin/bash
# Umar Techy E-Commerce — runs once, when the MySQL volume is first created.
#
# The platform's restore rehearsal (MysqlRehearsalTarget) restores a backup
# into a throwaway database named <database>_rehearsal_<random> and drops it
# afterwards. The application account may create and drop those databases,
# and nothing else beyond its own database (which the image already grants).
set -euo pipefail

# Underscores are wildcards in a grant's database name; escape them.
pattern="$(printf '%s' "${MYSQL_DATABASE}_rehearsal_" | sed 's/_/\\_/g')%"

# The password goes through the environment, not the command line.
MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql --protocol=socket -uroot <<SQL
GRANT ALL PRIVILEGES ON \`${pattern}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
