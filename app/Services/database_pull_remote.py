"""Read-only remote discovery and stdout-only PostgreSQL snapshot export."""
import base64
import json
import os
import pathlib
import re
import subprocess
import sys


def execute(arguments, environment=None, stream=False):
    result = subprocess.run(
        arguments, env=environment, stdin=subprocess.DEVNULL,
        stdout=None if stream else subprocess.PIPE, stderr=subprocess.DEVNULL,
        timeout=options['timeout'], check=True,
    )
    return '' if stream else result.stdout.decode()


def container(service, required=True):
    ids = execute(compose + ['ps', '--status', 'running', '-q', service]).split()
    if not ids and not required:
        return None
    if len(ids) != 1:
        raise ValueError('A unique running service is required')
    data = json.loads(execute(['docker', 'inspect', ids[0]]))[0]
    labels = data['Config'].get('Labels') or {}
    if not data['State']['Running'] or labels.get('com.docker.compose.project') != project or labels.get('com.docker.compose.service') != service:
        raise ValueError('Unexpected container identity')
    return data


def pg(tool, arguments, stream=False):
    environment = os.environ.copy()
    environment.update({
        'PGHOST': '/var/run/postgresql', 'PGPORT': str(connection.get('port') or 5432),
        'PGDATABASE': str(connection['database']), 'PGUSER': str(connection['username']),
        'PGPASSWORD': str(connection.get('password') or ''),
        'PGCONNECT_TIMEOUT': '10', 'PGSSLMODE': str(connection.get('sslmode') or 'prefer'),
        'PGSERVICEFILE': '/dev/null', 'PGPASSFILE': '/dev/null',
        'PGOPTIONS': '-c default_transaction_read_only=on', 'PGCLIENTENCODING': 'UTF8',
    })
    for name in ['PGSERVICE', 'PGHOSTADDR']:
        environment.pop(name, None)
    command = ['docker', 'exec']
    for name in ['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGCONNECT_TIMEOUT', 'PGSSLMODE', 'PGSERVICE', 'PGSERVICEFILE', 'PGPASSFILE', 'PGOPTIONS', 'PGHOSTADDR', 'PGCLIENTENCODING']:
        command += ['--env', name]
    return execute(command + [database['Id'], tool] + arguments, environment, stream)


try:
    action = sys.argv[1]
    options = json.loads(base64.b64decode(sys.argv[2]))
    root = pathlib.Path(os.path.expanduser(options['remote-path'])).resolve(strict=True)
    os.chdir(root)
    environment_file = options.get('remote-env-file') or ('.env.production' if (root / '.env.production').is_file() else '.env')
    environment_path = (root / environment_file).resolve(strict=True)
    text = environment_path.read_text()
    match = re.search(r'^\s*(?:export\s+)?APP_ENV\s*=\s*([^\r\n]*)', text, re.M)
    environment = match.group(1).split(' #', 1)[0].strip().strip('\"\'') if match else ('production' if environment_path.name == '.env.production' else 'local')
    if environment not in ('production', 'local', 'development', 'testing'):
        raise ValueError('Unsupported project environment')
    production = environment == 'production'
    project = options.get('remote-project') or ('oapteka-op' if production else 'oaptekacom')
    files = options.get('remote-compose-file') or ['compose.production.yaml' if production else 'docker-compose.yml']
    endpoint = json.loads(execute(['docker', 'context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}']))
    if not endpoint.startswith('unix://') or (os.environ.get('DOCKER_HOST') and not os.environ['DOCKER_HOST'].startswith('unix://')):
        raise ValueError('Remote Docker endpoint is unsupported')
    compose = ['docker', 'compose', '--project-name', project, '--env-file', str(environment_path)]
    os.environ['PRODUCTION_ENV_FILE'] = str(environment_path)
    for file in files:
        compose += ['-f', str((root / file).resolve(strict=True))]
    configuration = json.loads(execute(compose + ['config', '--format', 'json']))
    application = container(options.get('remote-app-service') or 'app')
    php = r'''
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = (new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration(config('database.connections.'.config('database.default')));
if (($c['driver'] ?? '') !== 'pgsql' || isset($c['read']) || isset($c['write'])) { exit(1); }
foreach (['host', 'database', 'username', 'password'] as $key) {
    if (isset($c[$key]) && !is_scalar($c[$key])) { exit(1); }
    $c[$key] = (string) ($c[$key] ?? '');
}
echo json_encode(array_intersect_key($c, array_flip(['driver','host','port','database','username','password','sslmode'])));
'''
    connection = json.loads(execute(['docker', 'exec', application['Id'], 'php', '-r', php]))
    if connection.get('driver') != 'pgsql' or not isinstance(connection.get('host'), str):
        raise ValueError('PostgreSQL is required')
    if not connection.get('host') or not isinstance(connection.get('database'), str) or not connection['database'] or not isinstance(connection.get('username'), str) or not connection['username']:
        raise ValueError('Invalid database identity')
    if connection.get('database') in ('postgres', 'template0', 'template1') or '=' in connection['database'] or any(ord(c) < 32 for c in connection['database']):
        raise ValueError('Maintenance databases are unsupported')
    candidates = []
    override = options.get('remote-db-service')
    for name, service in configuration['services'].items():
        if override and name != override:
            continue
        image = service.get('image', '')
        if not override and not re.search(r'(^|/)postgres(?:[:@]|$)', image):
            continue
        candidate = container(name, required=False)
        if candidate is None:
            continue
        for network, details in candidate['NetworkSettings']['Networks'].items():
            if network in application['NetworkSettings']['Networks']:
                aliases = (details.get('Aliases') or []) + [details.get('IPAddress'), candidate['Name'].lstrip('/')]
                if connection['host'] in aliases and int(connection.get('port') or 5432) == 5432:
                    candidates.append(candidate)
                    break
    if len(candidates) != 1:
        raise ValueError('Database service match is missing or ambiguous')
    database = candidates[0]
    version = int(pg('psql', ['-X', '--no-password', '-At', '-v', 'ON_ERROR_STOP=1', '-c', "SELECT current_setting('server_version_num') WHERE current_setting('transaction_read_only') = 'on'"]).strip())
    dump_match = re.search(r'(\d+)\.', pg('pg_dump', ['--version']))
    if version < 100000 or not dump_match or int(dump_match.group(1)) < version // 10000:
        raise ValueError('Incompatible dump client')
    metadata = {'container': database['Id'], 'database': connection['database'], 'server': version // 10000, 'dump': int(dump_match.group(1))}
    if action == 'discover':
        print(json.dumps(metadata))
    elif action == 'export' and metadata == options.get('expected'):
        pg('pg_dump', ['--no-password', '--format=custom'], stream=True)
    else:
        raise ValueError('Remote database changed since confirmation')
except BaseException:
    print('Remote database operation failed; details suppressed.', file=sys.stderr)
    sys.exit(1)
