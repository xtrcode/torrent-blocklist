#!/bin/sh
# Entrypoint of the runtime image.
#   generate (default)  build the blocklist files from the environment configuration
#   help                show all configuration variables
#   anything else       executed as-is (e.g. "sh")
set -eu

PHP="php -d memory_limit=-1 -d error_reporting=E_ALL -d display_errors=stderr"

case "${1:-generate}" in
    generate)
        shift
        exec $PHP /app/bin/generate.php "$@"
        ;;
    help|--help|-h)
        exec $PHP /app/bin/generate.php --help
        ;;
    *)
        exec "$@"
        ;;
esac
