// PostToolUse hook: auto-format after Write/Edit
// Runs Prettier on Vue/TS/JS/CSS files, Pint on PHP files.
let d = '';
process.stdin.on('data', c => (d += c));
process.stdin.on('end', () => {
    try {
        const {
            tool_input: { file_path: f } = {},
        } = JSON.parse(d || '{}');
        if (!f) return;
        const cp = require('child_process');
        if (/\.(vue|ts|js|css)$/.test(f)) {
            cp.execSync('npx prettier --write ' + JSON.stringify(f), {
                stdio: 'pipe',
            });
        } else if (/\.php$/.test(f)) {
            cp.execSync('php vendor/bin/pint ' + JSON.stringify(f), {
                stdio: 'pipe',
            });
        }
    } catch (_) {}
});
