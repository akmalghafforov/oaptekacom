#!/usr/bin/env python3
import json
import os
import pathlib
import signal
import subprocess
import sys
import time

root = pathlib.Path(os.environ['PULL_TEST_ROOT'])
name = pathlib.Path(sys.argv[0]).name
args = sys.argv[1:]
side = os.environ.get('PULL_SIDE', 'local')
failure = os.environ.get('PULL_FAILURE', '')
local_id, remote_id, app_id, second_id = 'a' * 64, 'b' * 64, 'c' * 64, 'd' * 64
if failure == 'same-container':
    remote_id = local_id
with (root / 'commands').open('a') as log:
    log.write(json.dumps({'tool': name, 'args': args, 'side': side}) + '\n')


def fail():
    print('SECRET_PASSWORD postgres://user:SECRET_PASSWORD@private-host/db', file=sys.stderr)
    sys.exit(1)


def counter(key):
    path = root / key
    count = int(path.read_text()) + 1 if path.exists() else 1
    path.write_text(str(count))
    return count


def state_path():
    return root / ('production-state' if side == 'remote' else 'state')


def state():
    return json.loads(state_path().read_text())


if name == 'ssh':
    command = args[-1]
    if 'discover' in command and failure == 'ssh-discover':
        fail()
    if 'export' in command:
        if failure == 'ssh-export':
            print('PGDMPpartial', end='')
            fail()
        if failure == 'export-empty':
            sys.exit(0)
        if failure == 'export-corrupt':
            print('invalid archive')
            sys.exit(0)
    env = os.environ.copy()
    env['PULL_SIDE'] = 'remote'
    result = subprocess.run(command, shell=True, input=sys.stdin.buffer.read(), env=env)
    sys.exit(result.returncode)

if name == 'docker':
    if args[0] == 'context':
        print(json.dumps(os.environ.get('PULL_DOCKER_ENDPOINT', 'unix:///fake.sock')))
        sys.exit(0)
    if args[0] == 'compose':
        if 'config' in args:
            print(json.dumps({'services': {'app': {'image': 'app:test'}, 'postgres': {'image': 'postgres:16-alpine'}, **({'second': {'image': 'postgres:16-alpine'}} if failure in ('ambiguous', 'stopped-unrelated') else {})}}))
        else:
            service = args[-1]
            if failure == 'stopped-unrelated' and service == 'second':
                sys.exit(0)
            print(app_id if service == 'app' else second_id if service == 'second' else remote_id if side == 'remote' else local_id)
        sys.exit(0)
    if args[0] == 'inspect':
        identifier = args[-1]
        service = 'app' if identifier == app_id else 'second' if identifier == second_id else 'postgres'
        project = 'oapteka-op' if side == 'remote' else os.environ.get('PULL_LOCAL_PROJECT', 'oaptekacom')
        if failure == 'wrong-project' and side == 'local':
            project = 'production'
        print(json.dumps([{'Id': identifier, 'Name': '/' + service, 'Config': {'Labels': {'com.docker.compose.project': project, 'com.docker.compose.service': service}}, 'State': {'Running': True}, 'NetworkSettings': {'Networks': {'test': {'Aliases': [service, 'postgres'] if identifier == second_id else [service], 'IPAddress': '172.20.0.2'}}, 'Ports': {'5432/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '5432'}]}}}]))
        sys.exit(0)
    if args[0] == 'exec':
        index = next(i for i, value in enumerate(args) if value in (local_id, remote_id, app_id, second_id))
        identifier = args[index]
        name, args = args[index + 1], args[index + 2:]
        if name == 'php':
            print(json.dumps({'driver': 'mysql' if failure == 'remote-engine' else 'pgsql', 'host': 'postgres', 'port': 5432, 'database': 'changed' if failure == 'changed-source' and (root / 'discovered').exists() else 'production', 'username': 'prod-user', 'password': 'SECRET_PASSWORD'}))
            (root / 'discovered').touch()
            sys.exit(0)
        assert os.environ.get('PGHOSTADDR', '') == ''
        assert os.environ['PGPASSWORD'] == 'SECRET_PASSWORD'

if name in ('psql', 'pg_dump', 'pg_restore'):
    if side == 'remote':
        assert os.environ.get('PGOPTIONS') == '-c default_transaction_read_only=on', 'Remote clients must use read-only transactions'
    assert 'PGSERVICE' not in os.environ, 'PGSERVICE must be absent, including an empty value'
    assert 'PGHOSTADDR' not in os.environ, 'Inherited address routing must be absent'

if name in ('psql', 'pg_dump', 'pg_restore') and side == 'remote' and failure == 'remote-loopback-blocked':
    if os.environ.get('PGHOST') != '/var/run/postgresql':
        fail()

if '--version' in args:
    if args != ['--version']:
        fail()
    version = '17' if failure == 'version' and side == 'remote' or failure == 'local-version' and side == 'local' else '16'
    print(f'{name} (PostgreSQL) {version}.4')
    sys.exit(0)

if name == 'psql':
    if '-c' in args:
        sql = args[args.index('-c') + 1]
        if 'json_build_object' in sql:
            print(json.dumps({'server': 16, 'allowed': failure != 'privileges', 'template': failure == 'template', 'encoding': 'UTF8', 'collate': 'C', 'ctype': 'C', 'provider': 'c', 'locale': None, 'rules': None, 'connections': 0}))
        elif 'server_version_num' in sql:
            print('160004')
        elif 'pg_stat_activity' in sql:
            print('1' if failure == 'connections' else '0')
        else:
            print('1')
    else:
        sql = sys.stdin.read()
        with (root / 'sql').open('a') as log:
            log.write(sql)
        count = counter('recreate-count')
        if failure == 'recreate' and count == 1:
            fail()
        state_path().write_text('{}')
    sys.exit(0)

if name == 'pg_dump':
    if side == 'local' and failure == 'timeout':
        time.sleep(3)
    if side == 'local' and failure == 'interrupt':
        os.kill(os.getppid(), signal.SIGINT)
        time.sleep(3)
    if side == 'local' and failure == 'backup':
        print('PGDMPpartial', end='')
        fail()
    if side == 'local' and failure == 'backup-corrupt':
        print('invalid archive')
    else:
        data = state()
        sys.stdout.write('PGDMP\n' + json.dumps(data))
    sys.exit(0)

if name == 'pg_restore':
    archive = sys.stdin.read()
    if not archive.startswith('PGDMP\n'):
        fail()
    if '--list' in args:
        print('fake TOC')
    else:
        count = counter('restore-count')
        if failure == 'restore-timeout' and count == 1:
            time.sleep(3)
        if failure == 'restore-interrupt' and count == 1:
            os.kill(os.getppid(), signal.SIGINT)
            time.sleep(3)
        if failure == 'recovery' or failure == 'restore' and count == 1:
            fail()
        state_path().write_text(json.dumps(json.loads(archive.split('\n', 1)[1])))
    sys.exit(0)

fail()
