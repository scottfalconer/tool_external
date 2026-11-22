#!/usr/bin/env python3
"""Run module tests in an existing Drupal development project, with real HTTP."""
import argparse
import os
from pathlib import Path
import subprocess
import sys

parser = argparse.ArgumentParser()
parser.add_argument('drupal_project', type=Path, help='Composer project containing web/core and vendor/bin/phpunit')
parser.add_argument('--phpunit-arg', action='append', default=[], help='Additional PHPUnit option, repeat as needed')
parser.add_argument('--foundation-only', action='store_true', help='Only run the base module suite')
args = parser.parse_args()
project = args.drupal_project.resolve()
module = Path(__file__).resolve().parents[1]
if not (project / 'web/core/phpunit.xml.dist').is_file():
    parser.error('Install Drupal core and core-dev in the project first.')
env = dict(os.environ, PORT='0')
suite_paths = [str(module / 'tests/src')]
server_command = [env.get('NODE_BINARY', 'node'), str(module / 'tests/fixtures/http-server.mjs')]
content_tests = module / 'modules/tool_external_content/tests/src'
if content_tests.is_dir() and not args.foundation_only:
    suite_paths.append(str(content_tests))
    server_command.append(str(module / 'examples/worker.mjs'))
server = subprocess.Popen(
    server_command,
    env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
)
try:
    ready = server.stdout.readline().strip().split()
    if len(ready) != 2 or ready[0] != 'READY' or not ready[1].isdigit():
        raise RuntimeError('The local JavaScript test function did not start.')
    port = int(ready[1])
    env.update({
        'TOOL_EXTERNAL_TEST_URL': f'http://127.0.0.1:{port}',
        'SIMPLETEST_DB': env.get('SIMPLETEST_DB', 'sqlite://localhost/:memory:'),
        'BROWSERTEST_OUTPUT_DIRECTORY': str(project / 'test-output'),
    })
    (project / 'test-output').mkdir(exist_ok=True)
    command = [
        env.get('PHP_BINARY', 'php'), str(project / 'vendor/bin/phpunit'),
        '-c', str(project / 'web/core/phpunit.xml.dist'),
        *args.phpunit_arg,
        *suite_paths,
    ]
    completed = subprocess.run(command, cwd=project, env=env)
    sys.exit(completed.returncode)
finally:
    server.terminate()
    server.wait(timeout=5)

