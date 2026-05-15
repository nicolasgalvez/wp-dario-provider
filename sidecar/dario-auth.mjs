#!/usr/bin/env node
/**
 * Helper for the WordPress admin Claude-auth UI.
 *
 * Subcommands:
 *   status   — print JSON: { authenticated, status, expiresIn?, expiresAt?, hasCredentials, error? }
 *
 * The actual `dario login --manual` orchestration is driven from PHP via
 * proc_open + a FIFO so we can keep the long-lived child process state
 * across HTTP requests. This helper only wraps the read-only status query
 * so we don't have to reach into Dario's unexported internals.
 */
import process from 'node:process';

async function main() {
	const cmd = process.argv[2];
	if (cmd === 'status') {
		await printStatus();
		return;
	}

	process.stderr.write(`unknown subcommand: ${String(cmd)}\n`);
	process.exit(2);
}

async function printStatus() {
	let mod;
	try {
		mod = await import('@askalf/dario');
	} catch (err) {
		emit({ ok: false, error: `dario package not loadable: ${err && err.message ? err.message : String(err)}` });
		return;
	}

	const { getStatus, loadCredentials } = mod;
	const out = { ok: true, hasCredentials: false, authenticated: false, status: 'none' };

	try {
		const creds = await loadCredentials();
		out.hasCredentials = Boolean(creds && creds.claudeAiOauth && creds.claudeAiOauth.accessToken);
	} catch (err) {
		out.credentialsError = String(err && err.message ? err.message : err);
	}

	try {
		const s = await getStatus();
		out.authenticated = Boolean(s.authenticated);
		out.status = s.status || 'none';
		if (s.expiresAt) out.expiresAt = s.expiresAt;
		if (s.expiresIn) out.expiresIn = s.expiresIn;
		if (typeof s.refreshFailures === 'number') out.refreshFailures = s.refreshFailures;
		if (s.lastRefreshError) out.lastRefreshError = s.lastRefreshError;
	} catch (err) {
		out.statusError = String(err && err.message ? err.message : err);
	}

	emit(out);
}

function emit(obj) {
	process.stdout.write(JSON.stringify(obj) + '\n');
}

main().catch((err) => {
	process.stderr.write(`dario-auth helper failed: ${err && err.stack ? err.stack : String(err)}\n`);
	process.exit(1);
});
