// Syntax check ('php -l') of every PHP file of the plugin and the tests with PHP 8.4 compiled to WebAssembly.
// Setup once:   cd tests/php-wasm && npm install
// Usage:        node tests/php-wasm/lint.mjs
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

function collectPhpFiles(directory) {
  const files = [];
  for (const item of fs.readdirSync(directory, { withFileTypes: true })) {
    if (item.name === 'node_modules' || item.name.startsWith('.')) {
      continue;
    }
    const fullPath = path.join(directory, item.name);
    if (item.isDirectory()) {
      files.push(...collectPhpFiles(fullPath));
    } else if (item.name.endsWith('.php')) {
      files.push(path.relative(repositoryRoot, fullPath).replaceAll('\\', '/'));
    }
  }
  return files;
}

const files = [...collectPhpFiles(path.join(repositoryRoot, 'kornsw-ujmwqueue')), ...collectPhpFiles(path.join(repositoryRoot, 'tests'))];
let failedCount = 0;
for (const file of files) {
  const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: 1 } }));
  php.mkdir('/repo');
  await php.mount('/repo', createNodeFsMountHandler(repositoryRoot));
  const response = await php.cli(['php', '-l', '/repo/' + file]);
  const exitCode = await response.exitCode;
  if (exitCode !== 0) {
    failedCount++;
    process.stdout.write(await response.stdoutText);
    process.stderr.write(await response.stderrText);
  }
  php.exit();
}
console.log(`${files.length - failedCount} of ${files.length} files without syntax errors`);
process.exit(failedCount > 0 ? 1 : 0);
