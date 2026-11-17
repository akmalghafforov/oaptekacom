<?php

return [
    'host' => env('DATABASE_PULL_HOST'),
    'ssh-user' => env('DATABASE_PULL_SSH_USER'),
    'ssh-port' => env('DATABASE_PULL_SSH_PORT'),
    'ssh-key' => env('DATABASE_PULL_SSH_KEY'),
    'ssh-config' => env('DATABASE_PULL_SSH_CONFIG'),
    'remote-path' => env('DATABASE_PULL_REMOTE_PATH', '~/Projects/oaptekacom'),
    'remote-env-file' => env('DATABASE_PULL_REMOTE_ENV_FILE'),
    'remote-compose-file' => array_values(array_filter(explode(',', (string) env('DATABASE_PULL_REMOTE_COMPOSE_FILES', '')))),
    'remote-project' => env('DATABASE_PULL_REMOTE_PROJECT'),
    'remote-app-service' => env('DATABASE_PULL_REMOTE_APP_SERVICE', 'app'),
    'remote-db-service' => env('DATABASE_PULL_REMOTE_DB_SERVICE'),
    'local-mode' => env('DATABASE_PULL_LOCAL_MODE', 'docker'),
    'local-compose-file' => array_values(array_filter(explode(',', (string) env('DATABASE_PULL_LOCAL_COMPOSE_FILES', 'docker-compose.yml')))),
    'local-project' => env('DATABASE_PULL_LOCAL_PROJECT', 'oaptekacom'),
    'local-db-service' => env('DATABASE_PULL_LOCAL_DB_SERVICE', 'postgres'),
    'backup-directory' => env('DATABASE_PULL_BACKUP_DIRECTORY', storage_path('app/database-backups')),
    'timeout' => env('DATABASE_PULL_TIMEOUT', 1800),
];
