#!/bin/sh
set -e

# Create .env file if it doesn't exist
if [ ! -f .env ]; then
    echo "Creating .env file from environment variables..."
    envsubst < .env.dist > .env

    # Remove any unpopulated environment keys so the defaults work
    sed -i -E '/=\s*('\''\''|""|)\s*$/d' .env
fi

if [ "$1" != 'php' ] && [ "$1" != 'sh' ]; then
    # Install dependencies if not already installed (e.g. if vendor volume was mounted over)
    if [ ! -d 'vendor/' ]; then
        echo "Installing dependencies via Composer..."
        composer install --prefer-dist --no-progress --no-interaction
    fi

    # Check and wait for the database to be ready
    if grep -q ^DB_HOST= .env; then
        echo "Waiting for the database to be ready..."
        ATTEMPTS_LEFT_TO_REACH_DATABASE=60
        until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php artisan db:show 2>&1); do
            ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
            echo "Database not ready yet. Attempts left: $ATTEMPTS_LEFT_TO_REACH_DATABASE."
            sleep 1
        done

        if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
            echo "Failed to connect to the database:"
            echo "$DATABASE_ERROR"
            exit 1
        else
            echo "Database is now ready."
        fi
    fi

    echo "Running database migrations..."
    php artisan migrate --force

    if [ ! -f '_install/install.lock' ]; then
        echo "Creating folders structure..."
        mkdir -p /app/storage/app/public
        mkdir -p "$COVERS_PATH/anime" "$COVERS_PATH/audio" "$COVERS_PATH/audiosample" \
                 "$COVERS_PATH/book" "$COVERS_PATH/console" "$COVERS_PATH/games" \
                 "$COVERS_PATH/movies" "$COVERS_PATH/music" "$COVERS_PATH/preview" \
                 "$COVERS_PATH/sample" "$COVERS_PATH/tvrage" "$COVERS_PATH/tvshows" \
                 "$COVERS_PATH/video" "$COVERS_PATH/xxx" \
                 "$PATH_TO_NZBS" "$TEMP_UNRAR_PATH" "$TEMP_UNZIP_PATH"

        echo "Clearing application cache..."
        php artisan cache:clear
        php artisan config:clear
        php artisan route:clear
        php artisan view:clear

        echo "Caching configuration and routes..."
        php artisan config:cache
        php artisan route:cache

        echo "Caching spatie/laravel-data structures..."
        php artisan data:cache-structures || true

        # Run NNTmux installation
        echo "NNTmux installation..."
        php artisan nntmux:install --yes

        # Use single '=' for POSIX compatibility with /bin/sh
        if [ "${ELASTICSEARCH_ENABLED}" = "true" ]; then
            echo "Elasticsearch initialisation"
            php artisan nntmux:create-es-indexes
            php artisan nntmux:populate --elastic --releases
            php artisan nntmux:populate --elastic --predb
        else
            echo "Manticore initialisation"
            php artisan nntmux:populate --manticore --releases
            php artisan nntmux:populate --manticore --predb
        fi

        # Set permissions AFTER artisan commands so generated cache files are owned by www-data
        echo "Setting permissions on storage and bootstrap/cache directories..."
        chmod -R 775 bootstrap/cache
        chmod -R 777 storage resources
        chown -R www-data:www-data storage bootstrap/cache resources
    fi
fi

# Dynamically generate ~/.mytop
cat <<EOF > "$HOME/.mytop"
host=${DB_HOST:-mariadb}
user=${DB_USERNAME:-root}
pass=${DB_PASSWORD:-}
db=${DB_DATABASE:-nntmux}
port=${DB_PORT:-3306}
delay=3
EOF

chmod 600 "$HOME/.mytop"

# Run the PHP entry point with arguments
exec docker-php-entrypoint "$@"
