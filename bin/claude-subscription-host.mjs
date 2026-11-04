#!/usr/bin/env node
/**
 * Copyright © Mago Assistant
 *
 * Speaks the /v1 chat interface on the Mac, and runs the host `claude` login.
 * Magento, inside the container, calls http://host.docker.internal:3456/v1.
 * Start: app/code/MagoAssistant/ClaudeSubscription/bin/claude-subscription start
 */
import { spawn } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import http from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const HOST = process.env.HOST || '127.0.0.1';
const PORT = Number(process.env.PORT || 3456);
const CLAUDE = process.env.CLAUDE_BIN || 'claude';
const TIMEOUT_MS = 600_000;
const BODY_LIMIT = 20 * 1024 * 1024;

const MODELS = ['opus', 'fable', 'sonnet', 'haiku'];

const server = http.createServer((req, res) => {
    handle(req, res).catch((error) => {
        if (res.headersSent || res.writableEnded) {
            res.destroy();
            return;
        }
        const status = Number.isInteger(error.status) ? error.status : 500;
        sendJson(res, status, { error: { message: error.message || 'host failed' } });
    });
});

server.listen(PORT, HOST, () => {
    process.stdout.write(`Claude Subscription host listening on http://${HOST}:${PORT}/v1\n`);
});

/**
 * @param {import('node:http').IncomingMessage} req
 * @param {import('node:http').ServerResponse} res
 */
async function handle(req, res) {
    const url = new URL(req.url || '/', 'http://127.0.0.1');
    if (req.method === 'GET' && url.pathname === '/v1/models') {
        sendJson(res, 200, { data: MODELS.map((id) => ({ id })) });
        return;
    }
    if (req.method === 'POST' && url.pathname === '/v1/chat/completions') {
        await complete(req, res);
        return;
    }
    sendJson(res, 404, { error: { message: 'not found' } });
}

/**
 * @param {import('node:http').IncomingMessage} req
 * @param {import('node:http').ServerResponse} res
 */
async function complete(req, res) {
    let body;
    try {
        body = JSON.parse(await readBody(req));
    } catch (error) {
        if (Number.isInteger(error.status)) {
            throw error;
        }
        sendJson(res, 400, { error: { message: 'request body is not JSON' } });
        return;
    }
    if (!body || typeof body !== 'object' || !Array.isArray(body.messages)) {
        sendJson(res, 400, { error: { message: 'messages must be a list' } });
        return;
    }
    const model = typeof body.model === 'string' ? body.model : '';
    if (model === '') {
        sendJson(res, 400, { error: { message: 'model is required' } });
        return;
    }

    const stream = body.stream === true;
    const dir = await mkdtemp(join(tmpdir(), 'claude-subscription-host-'));
    const promptFile = join(dir, 'system-prompt.txt');
    let child = null;
    let started = false;
    const stop = () => {
        if (child && child.exitCode === null && !child.killed) {
            child.kill('SIGTERM');
        }
    };
    res.on('close', () => {
        if (!res.writableEnded) {
            stop();
        }
    });

    try {
        await writeFile(promptFile, systemPrompt(body.messages), 'utf8');
        const run = runClaude(dir, promptFile, model, transcript(body.messages), (text) => {
            if (!stream) {
                return;
            }
            if (!started) {
                started = true;
                res.writeHead(200, {
                    'Content-Type': 'text/event-stream',
                    'Cache-Control': 'no-cache',
                    Connection: 'keep-alive',
                });
            }
            res.write(`data: ${JSON.stringify({ choices: [{ delta: { content: text } }] })}\n\n`);
        });
        child = run.child;
        const result = await run.done;
        if (result.error && result.content === '') {
            sendJson(res, 502, { error: { message: result.error } });
            return;
        }
        const usage = {
            prompt_tokens: result.promptTokens,
            completion_tokens: result.completionTokens,
        };
        if (stream) {
            if (!started) {
                res.writeHead(200, {
                    'Content-Type': 'text/event-stream',
                    'Cache-Control': 'no-cache',
                    Connection: 'keep-alive',
                });
            }
            res.write(`data: ${JSON.stringify({ choices: [{ delta: {} }], usage })}\n\n`);
            res.write('data: [DONE]\n\n');
            res.end();
            return;
        }
        sendJson(res, 200, {
            choices: [{ message: { role: 'assistant', content: result.content } }],
            usage,
        });
    } finally {
        stop();
        await rm(dir, { recursive: true, force: true });
    }
}

/**
 * @param {string} dir
 * @param {string} promptFile
 * @param {string} model
 * @param {string} text
 * @param {(delta: string) => void} onDelta
 * @returns {{child: import('node:child_process').ChildProcess, done: Promise<{content: string, promptTokens: number, completionTokens: number, error: string|null}>}}
 */
function runClaude(dir, promptFile, model, text, onDelta) {
    const child = spawn(CLAUDE, [
        '--print',
        '--output-format', 'stream-json',
        '--verbose',
        '--include-partial-messages',
        '--no-session-persistence',
        '--permission-prompts', 'none',
        '--tools', '',
        '--strict-mcp-config',
        '--model', alias(model),
        '--system-prompt-file', promptFile,
    ], {
        cwd: dir,
        env: process.env,
        stdio: ['pipe', 'pipe', 'pipe'],
        timeout: TIMEOUT_MS,
    });
    child.stdin.on('error', () => {});
    child.stdin.end(text);

    const done = new Promise((resolve, reject) => {
        let buffer = '';
        let content = '';
        let sawDelta = false;
        let promptTokens = 0;
        let completionTokens = 0;
        let error = null;
        let stderr = '';
        let finished = false;

        const finish = (value) => {
            if (finished) {
                return;
            }
            finished = true;
            resolve(value);
        };

        child.stdout.on('data', (chunk) => {
            buffer += chunk.toString('utf8');
            let split = buffer.indexOf('\n');
            while (split !== -1) {
                const line = buffer.slice(0, split).trim();
                buffer = buffer.slice(split + 1);
                split = buffer.indexOf('\n');
                if (line === '') {
                    continue;
                }
                const parsed = readLine(line);
                if (parsed === null) {
                    continue;
                }
                if (parsed.kind === 'delta') {
                    sawDelta = true;
                    content += parsed.text;
                    onDelta(parsed.text);
                } else if (parsed.kind === 'final' && !sawDelta) {
                    content = parsed.text;
                } else if (parsed.kind === 'result') {
                    promptTokens = parsed.promptTokens;
                    completionTokens = parsed.completionTokens;
                    if (parsed.error) {
                        error = parsed.text || 'claude failed';
                    } else if (!sawDelta && content === '' && parsed.text !== '') {
                        content = parsed.text;
                    }
                }
            }
        });
        child.stderr.on('data', (chunk) => {
            if (stderr.length < 4000) {
                stderr += chunk.toString('utf8');
            }
        });
        child.on('error', (spawnError) => {
            if (finished) {
                return;
            }
            finished = true;
            if (spawnError.code === 'ENOENT') {
                reject(Object.assign(new Error(
                    'claude was not found on PATH. On the Mac, run claude auth login, then start this host again.'
                ), { status: 502 }));
                return;
            }
            reject(spawnError);
        });
        child.on('close', (code) => {
            if (content === '' && code !== 0) {
                const detail = (error || stderr.trim() || `claude exited ${code}`).slice(0, 500);
                finish({ content: '', promptTokens, completionTokens, error: detail });
                return;
            }
            finish({ content, promptTokens, completionTokens, error: content === '' ? error : null });
        });
    });

    return { child, done };
}

/**
 * @param {string} line
 * @returns {{kind: 'delta', text: string}|{kind: 'final', text: string}|{kind: 'result', text: string, error: boolean, promptTokens: number, completionTokens: number}|null}
 */
function readLine(line) {
    let event;
    try {
        event = JSON.parse(line);
    } catch {
        return null;
    }
    if (!event || typeof event !== 'object') {
        return null;
    }
    if (event.type === 'stream_event') {
        const delta = event.event && event.event.delta;
        if (delta && delta.type === 'text_delta' && typeof delta.text === 'string' && delta.text !== '') {
            return { kind: 'delta', text: delta.text };
        }
        return null;
    }
    if (event.type === 'assistant') {
        const parts = event.message && Array.isArray(event.message.content) ? event.message.content : [];
        const text = parts
            .filter((part) => part && part.type === 'text' && typeof part.text === 'string')
            .map((part) => part.text)
            .join('');
        return text === '' ? null : { kind: 'final', text };
    }
    if (event.type === 'result') {
        const usage = event.usage && typeof event.usage === 'object' ? event.usage : {};
        return {
            kind: 'result',
            text: typeof event.result === 'string' ? event.result : '',
            error: event.is_error === true,
            promptTokens: numberOrZero(usage.input_tokens),
            completionTokens: numberOrZero(usage.output_tokens),
        };
    }
    return null;
}

/**
 * @param {unknown} value
 * @returns {number}
 */
function numberOrZero(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}

/**
 * A saved alias is used as-is. An older versioned id still maps onto its family.
 *
 * @param {string} model
 * @returns {'opus'|'fable'|'sonnet'|'haiku'}
 */
function alias(model) {
    const id = model.toLowerCase();
    if (id === 'opus' || id === 'fable' || id === 'sonnet' || id === 'haiku') {
        return id;
    }
    if (id.includes('opus')) {
        return 'opus';
    }
    if (id.includes('fable')) {
        return 'fable';
    }
    if (id.includes('haiku')) {
        return 'haiku';
    }
    return 'sonnet';
}

/**
 * Joined system text. An empty file would leave Claude Code's own agent prompt in place,
 * so a request with no system message still gets one sentence.
 *
 * @param {Array<{role?: string, content?: string}>} messages
 * @returns {string}
 */
function systemPrompt(messages) {
    const parts = messages
        .filter((message) => message && message.role === 'system' && typeof message.content === 'string')
        .map((message) => message.content);
    if (parts.length === 0) {
        return 'Reply with text only.\n';
    }
    return `${parts.join('\n\n')}\n`;
}

/**
 * @param {Array<{role?: string, content?: string}>} messages
 * @returns {string}
 */
function transcript(messages) {
    return messages
        .filter((message) => message && message.role !== 'system' && typeof message.content === 'string')
        .map((message) => {
            const role = message.role === 'assistant' ? 'Assistant' : 'User';
            return `${role}: ${message.content}`;
        })
        .join('\n\n');
}

/**
 * @param {import('node:http').IncomingMessage} req
 * @returns {Promise<string>}
 */
function readBody(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        req.on('data', (chunk) => {
            size += chunk.length;
            if (size > BODY_LIMIT) {
                reject(Object.assign(new Error('request body is too large'), { status: 413 }));
                req.destroy();
                return;
            }
            chunks.push(chunk);
        });
        req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        req.on('error', reject);
    });
}

/**
 * @param {import('node:http').ServerResponse} res
 * @param {number} status
 * @param {unknown} payload
 */
function sendJson(res, status, payload) {
    if (res.headersSent || res.writableEnded) {
        return;
    }
    const encoded = JSON.stringify(payload);
    res.writeHead(status, {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(encoded),
    });
    res.end(encoded);
}
