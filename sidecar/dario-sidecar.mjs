import { startProxy } from '@askalf/dario';

const port = Number.parseInt(process.env.DARIO_PROXY_PORT || '3456', 10);
const host = process.env.DARIO_PROXY_HOST || '127.0.0.1';
const logFile = process.env.DARIO_LOG_FILE || undefined;

process.on('unhandledRejection', (error) => {
	console.error('[wp-dario-provider] Dario sidecar failed:', error);
	process.exit(1);
});

await startProxy({
	port,
	host,
	logFile,
	verbose: process.env.DARIO_VERBOSE === '1',
});
