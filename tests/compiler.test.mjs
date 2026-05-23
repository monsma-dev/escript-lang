import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import test from 'node:test';
import assert from 'node:assert/strict';

import compiler from '../playground/compiler.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..');

function hasPhp() {
  try {
    execFileSync('php', ['-v'], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

const phpAvailable = hasPhp();

function readEs(rel) {
  return readFileSync(join(root, rel), 'utf8');
}

test('playground compiler validates mutating routes need auth', () => {
  const src = `
route POST "/x" -> @php Foo@bar;
`;
  const { errors } = compiler.compile(src);
  assert.ok(errors.some((e) => e.includes('requires @auth')));
});

test('playground compiler requires guard tier, input, and output', () => {
  const src = `
guard Bad {
    tier: @rust;
    output: B;
    fail_mode: closed;
}
`;
  const { errors, ir } = compiler.compile(src);
  assert.ok(errors.some((e) => e.includes('must declare input')));
  assert.equal(ir, null);
});

test('playground compiler emits rust_middleware from route options', () => {
  const src = `
@auth(none)
route GET "/z" -> @rust my::handler {
    rust_middlewares: [a, b];
};
`;
  const { errors, ir } = compiler.compile(src);
  assert.deepEqual(errors, []);
  assert.deepEqual(ir.routes[0].rust_middleware, ['a', 'b']);
});

test('example sources compile in PHP reference compiler', { skip: !phpAvailable }, () => {
  const php = execFileSync('php', [
    join(root, 'compiler/bin/escript'),
    'compile',
    join(root, 'examples'),
    '--validate-only',
  ], { encoding: 'utf8' });
  assert.match(php, /OK:/);
});

test('stdlib fail_closed.es validates in PHP reference compiler', { skip: !phpAvailable }, () => {
  const php = execFileSync('php', [
    join(root, 'compiler/bin/escript'),
    'compile',
    join(root, 'stdlib/fail_closed.es'),
    '--validate-only',
  ], { encoding: 'utf8' });
  assert.match(php, /OK:/);
});

test('compliance example compiles to IR with guards array', () => {
  const src = readEs('examples/compliance-automation.es');
  const { errors, ir } = compiler.compile(src, 'compliance-automation.es');
  assert.deepEqual(errors, []);
  assert.ok(Array.isArray(ir.guards) && ir.guards.length >= 2);
  assert.ok(ir.guards.some((g) => g.name === 'SpendingCeilingGuard' && g.ceiling === 20));
});
