#!/usr/bin/env node
/**
 * Generate loud WAV prompt audio for TOEIC Speaking & Writing manifests.
 *
 * Uses OpenAI Realtime over WebSocket. The API key must be supplied through
 * OPENAI_API_KEY; this script never reads keys from files and never writes
 * secrets into generated artifacts.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { Buffer } = require('buffer');
const { execFileSync } = require('child_process');

function loadWs() {
    try {
        return require('ws');
    } catch (_) {
        const tempWs = path.join(os.tmpdir(), 'toeic_sw_audio_node', 'node_modules', 'ws');
        return require(tempWs);
    }
}

const WebSocket = loadWs();
const root = path.resolve(__dirname, '..');
const packageRoot = path.join(root, 'content', 'generated', 'toeic_sw');
const args = new Set(process.argv.slice(2));
const allowRealtimeNonliteral = args.has('--allow-realtime-nonliteral');
const overwrite = args.has('--overwrite');
const dryRun = args.has('--dry-run');
const fallbackSapi = args.has('--fallback-sapi');
const fallbackOnly = args.has('--fallback-only');
const verifyOnly = args.has('--verify-only');
const limitArg = process.argv.find((arg) => arg.startsWith('--limit='));
const limit = limitArg ? Number(limitArg.split('=')[1]) : 0;
const modelArg = process.argv.find((arg) => arg.startsWith('--model='));
const model = modelArg ? modelArg.split('=')[1] : 'gpt-realtime-1.5';
const voiceArg = process.argv.find((arg) => arg.startsWith('--voice='));
const voice = voiceArg ? voiceArg.split('=')[1] : 'alloy';
const apiKey = process.env.OPENAI_API_KEY || '';

if (!allowRealtimeNonliteral && !dryRun && !verifyOnly) {
    console.error('This Realtime generator is deprecated for TOEIC SW prompt audio because it can add conversational prefaces. Use scripts/generate_toeic_sw_audio_mmx.ps1 for literal loud TTS output. Pass --allow-realtime-nonliteral only for legacy experiments.');
    process.exit(2);
}

if (!apiKey && !dryRun && !fallbackOnly && !verifyOnly) {
    console.error('OPENAI_API_KEY is required for audio generation.');
    process.exit(2);
}

function readJson(file) {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function writeJson(file, value) {
    fs.writeFileSync(file, JSON.stringify(value, null, 2) + '\n');
}

function ensureDir(dir) {
    fs.mkdirSync(dir, { recursive: true });
}

function int16FromBuffer(buffer, offset) {
    return buffer.readInt16LE(offset);
}

function writeInt16(buffer, offset, value) {
    buffer.writeInt16LE(Math.max(-32768, Math.min(32767, Math.round(value))), offset);
}

function normalizePcm16(input) {
    if (!input.length) return input;
    const out = Buffer.from(input);
    let peak = 0;
    for (let i = 0; i + 1 < out.length; i += 2) {
        peak = Math.max(peak, Math.abs(int16FromBuffer(out, i)));
    }
    if (peak === 0) return out;
    const gain = Math.min(4.0, 30000 / peak);
    for (let i = 0; i + 1 < out.length; i += 2) {
        writeInt16(out, i, int16FromBuffer(out, i) * gain);
    }
    return out;
}

function wavFromPcm16(pcm, sampleRate = 24000) {
    const header = Buffer.alloc(44);
    header.write('RIFF', 0);
    header.writeUInt32LE(36 + pcm.length, 4);
    header.write('WAVE', 8);
    header.write('fmt ', 12);
    header.writeUInt32LE(16, 16);
    header.writeUInt16LE(1, 20);
    header.writeUInt16LE(1, 22);
    header.writeUInt32LE(sampleRate, 24);
    header.writeUInt32LE(sampleRate * 2, 28);
    header.writeUInt16LE(2, 32);
    header.writeUInt16LE(16, 34);
    header.write('data', 36);
    header.writeUInt32LE(pcm.length, 40);
    return Buffer.concat([header, pcm]);
}

function parseWav(data) {
    if (data.length < 44 || data.toString('ascii', 0, 4) !== 'RIFF' || data.toString('ascii', 8, 12) !== 'WAVE') {
        throw new Error('invalid_wav_header');
    }

    let offset = 12;
    let fmt = null;
    let dataChunk = null;
    while (offset + 8 <= data.length) {
        const id = data.toString('ascii', offset, offset + 4);
        const size = data.readUInt32LE(offset + 4);
        const start = offset + 8;
        const end = start + size;
        if (end > data.length) {
            break;
        }
        if (id === 'fmt ') {
            fmt = {
                audioFormat: data.readUInt16LE(start),
                channels: data.readUInt16LE(start + 2),
                sampleRate: data.readUInt32LE(start + 4),
                bitsPerSample: data.readUInt16LE(start + 14),
            };
        } else if (id === 'data') {
            dataChunk = { start, size };
        }
        offset = end + (size % 2);
    }

    if (!fmt || !dataChunk) {
        throw new Error('missing_wav_chunks');
    }
    return { fmt, dataChunk };
}

function inspectWav(file) {
    const data = fs.readFileSync(file);
    let parsed;
    try {
        parsed = parseWav(data);
    } catch (err) {
        return { ok: false, reason: err.message || 'invalid_wav_header', bytes: data.length };
    }
    const sampleRate = parsed.fmt.sampleRate;
    const bytesPerSample = parsed.fmt.bitsPerSample / 8;
    const dataSize = parsed.dataChunk.size;
    const pcm = data.subarray(parsed.dataChunk.start, parsed.dataChunk.start + dataSize);
    let peak = 0;
    if (parsed.fmt.audioFormat === 1 && parsed.fmt.bitsPerSample === 16) {
        for (let i = 0; i + 1 < pcm.length; i += 2) {
            peak = Math.max(peak, Math.abs(int16FromBuffer(pcm, i)));
        }
    }
    const duration = dataSize / (sampleRate * parsed.fmt.channels * bytesPerSample);
    const ok = parsed.fmt.audioFormat === 1 && parsed.fmt.bitsPerSample === 16 && dataSize > 12000 && duration >= 0.35 && peak >= 12000;
    return {
        ok,
        reason: ok ? '' : 'too_short_too_quiet_or_non_pcm16',
        bytes: data.length,
        sample_rate: sampleRate,
        channels: parsed.fmt.channels,
        bits_per_sample: parsed.fmt.bitsPerSample,
        duration_seconds: Number(duration.toFixed(3)),
        peak,
    };
}

function normalizeWavFile(file) {
    const data = fs.readFileSync(file);
    const parsed = parseWav(data);
    if (parsed.fmt.audioFormat !== 1 || parsed.fmt.bitsPerSample !== 16) {
        throw new Error('fallback_wav_not_pcm16');
    }
    if (parsed.fmt.channels !== 1) {
        throw new Error('fallback_wav_not_mono');
    }
    const pcm = data.subarray(parsed.dataChunk.start, parsed.dataChunk.start + parsed.dataChunk.size);
    fs.writeFileSync(file, wavFromPcm16(normalizePcm16(pcm), parsed.fmt.sampleRate));
}

function synthesizeWithSapi(script, output) {
    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'toeic-sw-sapi-'));
    const textPath = path.join(tmpDir, 'script.txt');
    const psPath = path.join(tmpDir, 'speak.ps1');
    fs.writeFileSync(textPath, script, 'utf8');
    fs.writeFileSync(psPath, `
param([string]$TextPath, [string]$OutputPath)
$ErrorActionPreference = 'Stop'
$text = Get-Content -LiteralPath $TextPath -Raw
$voice = New-Object -ComObject SAPI.SpVoice
$voice.Volume = 100
$voice.Rate = 0
$format = New-Object -ComObject SAPI.SpAudioFormat
$format.Type = 22
$stream = New-Object -ComObject SAPI.SpFileStream
$stream.Format = $format
$stream.Open($OutputPath, 3, $false)
$voice.AudioOutputStream = $stream
[void]$voice.Speak($text, 0)
$stream.Close()
`, 'utf8');

    execFileSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', psPath, textPath, output], {
        stdio: ['ignore', 'ignore', 'pipe'],
    });
    normalizeWavFile(output);
}

function inspectLegacyWavPcm(data) {
    const sampleRate = data.readUInt32LE(24);
    const dataSize = data.readUInt32LE(40);
    const pcm = data.subarray(44);
    let peak = 0;
    for (let i = 0; i + 1 < pcm.length; i += 2) {
        peak = Math.max(peak, Math.abs(int16FromBuffer(pcm, i)));
    }
    const duration = dataSize / (sampleRate * 2);
    return {
        bytes: data.length,
        sample_rate: sampleRate,
        duration_seconds: Number(duration.toFixed(3)),
        peak,
    };
}

function collectTasks() {
    const tasks = [];
    for (let packageNumber = 1; packageNumber <= 10; packageNumber++) {
        const packageName = `package_${String(packageNumber).padStart(2, '0')}`;
        const manifestPath = path.join(packageRoot, packageName, 'manifest.json');
        const manifest = readJson(manifestPath);
        for (const task of manifest.speaking || []) {
            const rel = task.audio_path;
            if (!rel) continue;
            tasks.push({
                packageName,
                packageNumber,
                questionNumber: task.question_number,
                script: task.audio_script || task.prompt_text,
                output: path.join(packageRoot, packageName, rel.replace(/\//g, path.sep)),
            });
        }
    }
    return limit > 0 ? tasks.slice(0, limit) : tasks;
}

function synthesize(script) {
    return new Promise((resolve, reject) => {
        const url = `wss://api.openai.com/v1/realtime?model=${encodeURIComponent(model)}`;
        const ws = new WebSocket(url, {
            headers: {
                Authorization: `Bearer ${apiKey}`,
                'OpenAI-Beta': 'realtime=v1',
            },
        });

        const chunks = [];
        let done = false;
        const timer = setTimeout(() => {
            if (!done) {
                done = true;
                try { ws.close(); } catch (_) {}
                reject(new Error('Realtime synthesis timed out'));
            }
        }, 120000);

        function finish(buffer) {
            if (done) return;
            done = true;
            clearTimeout(timer);
            try { ws.close(); } catch (_) {}
            resolve(buffer);
        }

        ws.on('open', () => {
            ws.send(JSON.stringify({
                type: 'session.update',
                session: {
                    modalities: ['text', 'audio'],
                    instructions: 'You are a TOEIC test narrator. Speak clearly, professionally, and loudly. Do not add explanations, introductions, sound effects, or extra words beyond the script.',
                    voice,
                    output_audio_format: 'pcm16',
                    turn_detection: null,
                },
            }));
            ws.send(JSON.stringify({
                type: 'response.create',
                response: {
                    modalities: ['audio', 'text'],
                    voice,
                    output_audio_format: 'pcm16',
                    instructions: `Read this TOEIC test prompt exactly as written, in a loud clear professional voice:\n\n${script}`,
                },
            }));
        });

        ws.on('message', (data) => {
            let event;
            try {
                event = JSON.parse(data.toString());
            } catch (_) {
                return;
            }
            if (event.type === 'response.audio.delta' && event.delta) {
                chunks.push(Buffer.from(event.delta, 'base64'));
            } else if (event.type === 'response.done') {
                finish(Buffer.concat(chunks));
            } else if (event.type === 'error') {
                const message = event.error?.message || JSON.stringify(event.error || event);
                if (!done) {
                    done = true;
                    clearTimeout(timer);
                    try { ws.close(); } catch (_) {}
                    reject(new Error(message));
                }
            }
        });

        ws.on('error', (err) => {
            if (!done) {
                done = true;
                clearTimeout(timer);
                reject(err);
            }
        });
    });
}

async function main() {
    const tasks = collectTasks();
    const report = {
        generated_at: new Date().toISOString(),
        model,
        voice,
        overwrite,
        dry_run: dryRun,
        total_tasks: tasks.length,
        generated: 0,
        skipped: 0,
        failed: 0,
        files: [],
    };

    for (const task of tasks) {
        ensureDir(path.dirname(task.output));
        if (verifyOnly) {
            if (!fs.existsSync(task.output)) {
                report.failed++;
                report.files.push({ packageName: task.packageName, questionNumber: task.questionNumber, output: path.relative(root, task.output).replace(/\\/g, '/'), status: 'missing' });
                continue;
            }
            const qa = inspectWav(task.output);
            if (qa.ok) {
                report.skipped++;
                report.files.push({ packageName: task.packageName, questionNumber: task.questionNumber, output: path.relative(root, task.output).replace(/\\/g, '/'), status: 'verified_ok', qa });
            } else {
                report.failed++;
                report.files.push({ packageName: task.packageName, questionNumber: task.questionNumber, output: path.relative(root, task.output).replace(/\\/g, '/'), status: 'verified_failed', qa });
            }
            continue;
        }

        if (fs.existsSync(task.output) && !overwrite) {
            const qa = inspectWav(task.output);
            report.skipped++;
            report.files.push({ ...task, output: path.relative(root, task.output).replace(/\\/g, '/'), status: qa.ok ? 'skipped_ok' : 'existing_invalid', qa });
            continue;
        }

        if (dryRun) {
            report.files.push({ ...task, output: path.relative(root, task.output).replace(/\\/g, '/'), status: 'dry_run' });
            continue;
        }

        process.stdout.write(`Generating ${task.packageName} Q${task.questionNumber}... `);
        try {
            let status = 'generated';
            if (fallbackOnly) {
                synthesizeWithSapi(task.script, task.output);
                status = 'generated_sapi_fallback';
            } else {
                try {
                    const pcm = await synthesize(task.script);
                    const loud = normalizePcm16(pcm);
                    fs.writeFileSync(task.output, wavFromPcm16(loud));
                } catch (err) {
                    if (!fallbackSapi) {
                        throw err;
                    }
                    synthesizeWithSapi(task.script, task.output);
                    status = 'generated_sapi_fallback';
                }
            }
            const qa = inspectWav(task.output);
            if (!qa.ok) {
                throw new Error(`Audio QA failed: ${qa.reason}`);
            }
            report.generated++;
            report.files.push({ packageName: task.packageName, questionNumber: task.questionNumber, output: path.relative(root, task.output).replace(/\\/g, '/'), status, qa });
            console.log(`ok ${qa.duration_seconds}s peak=${qa.peak}${status.includes('fallback') ? ' fallback=sapi' : ''}`);
        } catch (err) {
            report.failed++;
            report.files.push({ packageName: task.packageName, questionNumber: task.questionNumber, output: path.relative(root, task.output).replace(/\\/g, '/'), status: 'failed', error: String(err.message || err) });
            console.log(`failed: ${err.message || err}`);
            throw err;
        }
    }

    const reportPath = path.join(packageRoot, 'audio_qa_report.json');
    writeJson(reportPath, report);
    console.log(`Audio QA report: ${reportPath}`);
    if (report.failed > 0) {
        process.exit(1);
    }
}

main().catch((err) => {
    console.error(err.message || err);
    process.exit(1);
});
