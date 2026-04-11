/**
 * AI Proctor SDK
 * Monitors user behavior, face presence, and records evidence.
 * Integrity score is tracked server-side only — never exposed to the student.
 * Students see violation counts with clear tolerance limits instead.
 */

class ProctorSDK {
    constructor(config) {
        this.config = Object.assign({
            testSession: null,
            ajaxUrl: 'ajax_proctor.php',
            batchInterval: 10000, // 10s
            videoChunkInterval: 30000, // 30s
            snapshotInterval: 45000, // 45s periodic AI snapshot for phone/object detection
            strictness: 'strict',
            autoTerminateThreshold: 40,
            microphoneGranted: false
        }, config);

        this.state = {
            started: false,
            isRecording: false,
            eventsBuffer: [],
            facesDetected: 0,
            lastFaceTime: Date.now(),
            warnings: 0,
            consecutiveSyncFailures: 0,
            heartbeatWarningShown: false,
            terminated: false
        };

        this.mediaRecorder = null;
        this.videoStream = null;
        this.faceDetector = null;

        // Heartbeat config
        this.maxSyncFailures = 10;
        this.heartbeatTimeout = 90000;

        // Violation tracking with clear limits (shown to student)
        this.violationCounts = { critical: 0, high: 0, medium: 0, low: 0 };
        this.violationLimits = {
            critical: { max: 4, label: 'Kritis' },
            high:     { max: 6, label: 'Berat' },
            medium:   { max: 10, label: 'Sedang' },
            low:      { max: 20, label: 'Ringan' }
        };

        // Restore violation counts from sessionStorage (persists across section changes)
        this._storageKey = 'proctor_violations_' + (config.testSession || '');
        const saved = sessionStorage.getItem(this._storageKey);
        if (saved) {
            try {
                const parsed = JSON.parse(saved);
                if (parsed && typeof parsed === 'object') {
                    this.violationCounts = Object.assign(this.violationCounts, parsed);
                }
            } catch(e) {}
        }

        this._warningTimeout = null;
        this._paused = false;
    }

    /**
     * Check if active element is a SoundCloud iframe (or other audio iframe)
     */
    _isAudioIframeFocused() {
        const el = document.activeElement;
        if (!el || el.tagName !== 'IFRAME') return false;
        const src = el.src || '';
        return src.includes('soundcloud.com') || src.includes('w.soundcloud.com');
    }

    /**
     * Temporarily pause monitoring (for confirm/alert dialogs and navigation).
     * Auto-resumes after timeout to prevent permanent pause.
     */
    pause(ms = 3000) {
        this._paused = true;
        clearTimeout(this._pauseTimer);
        this._pauseTimer = setTimeout(() => { this._paused = false; }, ms);
    }

    resume() {
        this._paused = false;
        clearTimeout(this._pauseTimer);
    }

    launchGatekeeper() {
        if (typeof Gatekeeper === 'undefined') {
            console.error("Gatekeeper script not loaded");
            this.start();
            return;
        }
        new Gatekeeper(this);
    }

    async start() {
        if (this.state.started) {
            return;
        }
        this.state.started = true;
        console.log("[Proctor] AI Proctor Initializing...");

        // 1. Behavior Monitoring
        this.initBehaviorMonitoring();

        // 2. Camera & Face Detection (if not already init by Gatekeeper)
        if (!this.videoStream) {
            await this.initCamera();
        }

        // 3. Start Sync Loop (includes heartbeat)
        this.startSyncLoop();

        // 4. Start Video Recording
        this.startVideoRecording();

        // 5. Start Periodic AI Snapshots (phone/object detection)
        this.startPeriodicSnapshots();

        // 6. Show proctoring rules to student
        this.showProctoringRules();

        // 7. Face detection watchdog — catches camera disconnection mid-test
        this.startFaceWatchdog();

        // 8. Notify Server
        this.sendAction('init', {});
    }

    // ============================================
    // BEHAVIOR MODULE
    // ============================================
    initBehaviorMonitoring() {
        // Tab switch — high violation (can be triggered by OS notifications)
        document.addEventListener("visibilitychange", () => {
            if (document.hidden && !this._paused) {
                this.logEvent('tab_hidden', 'high', { duration: 0 });
            }
        });

        // Window blur — medium violation (often triggered by OS notifications, taskbar clicks)
        // Skip entirely on pages with SoundCloud embeds: cross-origin iframes always steal
        // focus on click, causing unavoidable blur events that cannot be distinguished from
        // real tab-away. tab_hidden (visibilitychange) still catches actual tab switches.
        window.addEventListener("blur", () => {
            if (this._paused) return;
            if (document.querySelector('iframe[src*="soundcloud.com"]')) return;
            this.logEvent('window_blur', 'medium');
        });

        // Clipboard — block and log as medium violation
        ['copy', 'cut', 'paste'].forEach(evt => {
            document.addEventListener(evt, (e) => {
                e.preventDefault();
                this.logEvent(`clipboard_${evt}`, 'medium');
            });
        });

        // Right-click — block and log as low violation (common accidental click)
        document.addEventListener("contextmenu", (e) => {
            e.preventDefault();
            this.logEvent('right_click', 'low');
        });

        // Block keyboard shortcuts used for cheating
        document.addEventListener("keydown", (e) => {
            // Ctrl+C, Ctrl+V, Ctrl+X, Ctrl+A, Ctrl+U (view source)
            if (e.ctrlKey && ['c','v','x','a','u'].includes(e.key.toLowerCase())) {
                e.preventDefault();
                this.logEvent('blocked_shortcut', 'medium', { key: e.key });
            }
            // F12 (devtools), Ctrl+Shift+I/J/C (devtools)
            if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && ['i','j','c'].includes(e.key.toLowerCase()))) {
                e.preventDefault();
                this.logEvent('devtools_attempt', 'critical', { key: e.key });
            }
            // PrintScreen
            if (e.key === 'PrintScreen') {
                e.preventDefault();
                this.logEvent('screenshot_attempt', 'critical');
            }
        });
    }

    // ============================================
    // CAMERA & AI MODULE
    // ============================================
    async initCamera() {
        try {
            this.showPermissionModal();

            this.videoStream = await navigator.mediaDevices.getUserMedia({
                video: { width: 640, height: 480, facingMode: 'user' },
                audio: false
            });

            this.hidePermissionModal();
            this.updateStatus("Camera Active");

            const video = document.createElement('video');
            video.srcObject = this.videoStream;
            video.autoplay = true;
            video.muted = true;
            video.style.display = 'none';
            document.body.appendChild(video);

            await this.initFaceMesh(video);

            this.sendAction('update_permissions', {
                camera: true,
                microphone: !!this.config.microphoneGranted
            });

        } catch (e) {
            console.error("Camera Error:", e);
            this.logEvent('camera_denied', 'critical', { error: e.message });

            if (this.config.strictness === 'strict') {
                alert("Camera access is required for this exam.");
                window.location.href = '../index.php?error=camera_denied';
            } else {
                this.showWarning("Camera error. Continuing in Flexible Mode.");
                this.updateStatus("Camera Inactive (Safe Mode)");
            }
        }
    }

    async initFaceMesh(videoElement) {
        if (typeof FaceDetection === 'undefined') {
            console.warn("MediaPipe FaceDetection not loaded!");
            return;
        }

        const faceDetection = new FaceDetection({locateFile: (file) => {
            return `https://cdn.jsdelivr.net/npm/@mediapipe/face_detection/${file}`;
        }});

        faceDetection.setOptions({
            model: 'short',
            minDetectionConfidence: 0.5
        });

        faceDetection.onResults((results) => {
            this.processFaceResults(results);
        });

        const camera = new Camera(videoElement, {
            onFrame: async () => {
                await faceDetection.send({image: videoElement});
            },
            width: 640,
            height: 480
        });
        camera.start();
    }

    processFaceResults(results) {
        const count = results.detections.length;
        this.state.facesDetected = count;
        this.state.lastFaceCallback = Date.now();

        if (count === 0) {
            if (Date.now() - this.state.lastFaceTime > 10000) {
                this.logEvent('face_missing', 'medium');
                this.state.lastFaceTime = Date.now();
            }
        } else if (count > 1) {
            this.logEvent('multiple_faces', 'critical', { count: count });
            this.captureSnapshot('multiple_faces');
            this.state.lastFaceTime = Date.now();
        } else {
            this.state.lastFaceTime = Date.now();
        }
    }

    /**
     * Watchdog: detects when face detection loop stops (camera disconnected,
     * driver crash, browser revoked permission mid-test). Checks every 15s
     * whether processFaceResults has been called recently.
     */
    startFaceWatchdog() {
        if (!this.videoStream) return; // no camera → nothing to watch

        this.state.lastFaceCallback = Date.now();

        setInterval(() => {
            if (this.state.terminated || this._paused) return;

            const silenceMs = Date.now() - (this.state.lastFaceCallback || 0);

            // If face detection callback hasn't fired in 20s, camera likely dead
            if (silenceMs > 20000) {
                // Check if video track is still live
                const track = this.videoStream?.getVideoTracks()?.[0];
                const trackDead = !track || track.readyState !== 'live';

                if (trackDead) {
                    console.warn('[Proctor] Camera track died — logging camera_lost');
                    this.logEvent('camera_lost', 'critical', {
                        silence_ms: silenceMs,
                        track_state: track?.readyState || 'none'
                    });
                } else {
                    this.logEvent('face_missing', 'medium', {
                        source: 'watchdog',
                        silence_ms: silenceMs
                    });
                }

                // Reset timer so we don't spam every 15s
                this.state.lastFaceCallback = Date.now();
            }
        }, 15000);
    }

    // ============================================
    // RECORDING MODULE
    // ============================================
    startVideoRecording() {
        if (!this.videoStream) return;

        const options = { mimeType: 'video/webm;codecs=vp8' };
        if (!MediaRecorder.isTypeSupported(options.mimeType)) {
            console.warn("VP8 not supported, trying default");
            delete options.mimeType;
        }

        this.mediaRecorder = new MediaRecorder(this.videoStream, options);
        let chunkIndex = 0;

        this.mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                this.uploadChunk(event.data, chunkIndex++);
            }
        };

        this.mediaRecorder.start(this.config.videoChunkInterval);
        this.state.isRecording = true;
    }

    async uploadChunk(blob, index) {
        const formData = new FormData();
        formData.append('action', 'upload_chunk');
        formData.append('test_session', this.config.testSession);
        formData.append('index', index);
        formData.append('chunk', blob);

        try {
            await fetch(this.config.ajaxUrl, { method: 'POST', body: formData });
        } catch (e) {
            console.error("Chunk upload failed", e);
        }
    }

    captureSnapshot(reason) {
        if (!this.videoStream) return;

        const track = this.videoStream.getVideoTracks()[0];
        if (!track || track.readyState !== 'live') return;
        const imageCapture = new ImageCapture(track);

        imageCapture.takePhoto().then(blob => {
            const formData = new FormData();
            formData.append('action', 'upload_snapshot');
            formData.append('test_session', this.config.testSession);
            formData.append('snapshot', blob);
            formData.append('event_type', reason);

            fetch(this.config.ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(json => {
                    this.handleAIResponse(json);
                })
                .catch(() => {});
        }).catch(() => {});
    }

    // ============================================
    // PERIODIC AI SNAPSHOTS (Phone/Object Detection)
    // ============================================
    startPeriodicSnapshots() {
        // First snapshot after 30s, then every snapshotInterval
        setTimeout(() => {
            this.capturePeriodicSnapshot();
            setInterval(() => this.capturePeriodicSnapshot(), this.config.snapshotInterval);
        }, 30000);
    }

    async capturePeriodicSnapshot() {
        if (!this.videoStream || this.state.terminated) return;

        try {
            const track = this.videoStream.getVideoTracks()[0];
            if (!track || track.readyState !== 'live') return;

            const imageCapture = new ImageCapture(track);
            const blob = await imageCapture.takePhoto();

            const formData = new FormData();
            formData.append('action', 'upload_snapshot');
            formData.append('test_session', this.config.testSession);
            formData.append('snapshot', blob);
            formData.append('event_type', 'periodic_check');

            const res = await fetch(this.config.ajaxUrl, { method: 'POST', body: formData });
            const json = await res.json();
            this.handleAIResponse(json);

        } catch (e) {
            console.warn('[Proctor] Periodic snapshot failed:', e.message);
        }
    }

    /**
     * Handle AI vision analysis response from server
     */
    handleAIResponse(json) {
        if (!json) return;

        if (json.ai_detected && json.ai_detected.length > 0) {
            const items = json.ai_detected.join(', ');
            if (json.ai_verdict === 'cheating') {
                this.showViolationWarning('critical',
                    `Terdeteksi oleh AI: ${items}`);
            } else if (json.ai_verdict === 'suspicious') {
                this.showViolationWarning('high',
                    `Aktivitas mencurigakan: ${items}`);
            }
        }

        if (json.ai_action === 'terminate') {
            this.terminateExam('ai_decision');
        }
    }

    // ============================================
    // CORE & NETWORK
    // ============================================
    logEvent(type, severity, metadata = {}) {
        if (this.state.terminated) return;

        const event = { type, severity, metadata, timestamp: Date.now() };
        this.state.eventsBuffer.push(event);
        console.log(`[Proctor] ${type} (${severity})`);

        // Track violation count and show warning with limits
        if (this.violationCounts[severity] !== undefined) {
            this.violationCounts[severity]++;
            // Persist across section changes
            try { sessionStorage.setItem(this._storageKey, JSON.stringify(this.violationCounts)); } catch(e) {}
            const limit = this.violationLimits[severity];
            const count = this.violationCounts[severity];

            const typeMessages = {
                'tab_hidden': 'DILARANG berpindah tab! Pelanggaran berat.',
                'window_blur': 'Jangan keluar dari jendela ujian!',
                'face_missing': 'Wajah tidak terdeteksi! Lihat kamera.',
                'multiple_faces': 'Terdeteksi lebih dari satu wajah!',
                'clipboard_copy': 'Copy tidak diizinkan!',
                'clipboard_cut': 'Cut tidak diizinkan!',
                'clipboard_paste': 'Paste tidak diizinkan!',
                'right_click': 'Klik kanan tidak diizinkan!',
                'blocked_shortcut': 'Shortcut keyboard tidak diizinkan!',
                'devtools_attempt': 'DILARANG membuka Developer Tools!',
                'screenshot_attempt': 'Screenshot tidak diizinkan!',
                'camera_denied': 'Kamera tidak tersedia!'
            };

            const msg = typeMessages[type];
            if (msg) {
                this.showViolationWarning(severity,
                    `${msg} (Pelanggaran ${limit.label}: ${count}/${limit.max})`);
            }

            // When violation limit reached, warn and sync — let server decide termination
            if (count >= limit.max) {
                console.warn(`[Proctor] ${limit.label} violation limit reached (${count}/${limit.max}), syncing to server for decision.`);
                this.showViolationWarning('critical',
                    `Peringatan keras: Batas pelanggaran ${limit.label} tercapai (${count}/${limit.max}). Sistem sedang mengevaluasi.`);
                this.syncEvents();
                return;
            }
        }

        // Immediately send critical/high/medium severity events
        if (severity === 'critical' || severity === 'high' || severity === 'medium') {
            this.syncEvents();
        }
    }

    startSyncLoop() {
        setInterval(() => {
            if (this.state.eventsBuffer.length > 0) {
                this.syncEvents();
            }
        }, this.config.batchInterval);

        // Periodic heartbeat every 30s
        setInterval(() => this.sendHeartbeat(), 30000);
    }

    async sendHeartbeat() {
        if (this.state.terminated) return;

        try {
            const formData = new FormData();
            formData.append('action', 'heartbeat');
            formData.append('test_session', this.config.testSession);
            const res = await fetch(this.config.ajaxUrl, { method: 'POST', body: formData });
            const json = await res.json();

            this.state.consecutiveSyncFailures = 0;

            // Server signals termination when integrity score is below threshold
            if (json.ai_action === 'terminate') {
                this.terminateExam('server_terminated');
            }
        } catch (e) {
            this.state.consecutiveSyncFailures++;
        }
    }

    async syncEvents() {
        if (this.state.terminated) return;

        const events = [...this.state.eventsBuffer];
        this.state.eventsBuffer = [];

        const formData = new FormData();
        formData.append('action', 'batch_sync');
        formData.append('test_session', this.config.testSession);
        formData.append('events', JSON.stringify(events));

        try {
            const res = await fetch(this.config.ajaxUrl, { method: 'POST', body: formData });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const json = await res.json();

            this.state.consecutiveSyncFailures = 0;

            // Handle AI actions from server
            if (json.ai_action === 'terminate') {
                this.terminateExam('ai_decision');
            } else if (json.ai_action === 'warning') {
                this.showViolationWarning('high', 'Peringatan dari sistem AI: Tetap fokus pada ujian.');
            }

        } catch (e) {
            console.error(`[Proctor] Sync failed (${this.state.consecutiveSyncFailures + 1}/${this.maxSyncFailures})`, e);

            this.state.consecutiveSyncFailures++;
            this.reportSyncFailure();

            // Terminate exam after repeated sync failures
            if (this.state.consecutiveSyncFailures >= this.maxSyncFailures && !this.state.heartbeatWarningShown) {
                this.state.heartbeatWarningShown = true;
                this.terminateExam('connection_lost');
                return;
            }

            this.state.eventsBuffer = [...events, ...this.state.eventsBuffer];
        }
    }

    async reportSyncFailure() {
        try {
            const formData = new FormData();
            formData.append('action', 'sync_failure');
            formData.append('test_session', this.config.testSession);
            await fetch(this.config.ajaxUrl, { method: 'POST', body: formData });
        } catch (e) {
            console.error("[Proctor] Failed to report sync failure", e);
        }
    }

    terminateExam(reason = 'violation') {
        if (this.state.terminated) return;
        this.state.terminated = true;

        console.error(`[PROCTOR] Terminating exam - Reason: ${reason}`);

        this.state.isRecording = false;
        if (this.mediaRecorder) this.mediaRecorder.stop();

        if (this.videoStream) {
            this.videoStream.getTracks().forEach(track => track.stop());
        }

        // Log termination event (fire and forget)
        this.sendAction('terminate', { reason: reason });

        const reasonMessages = {
            'integrity_threshold': 'Skor integritas di bawah batas minimum',
            'connection_lost': 'Koneksi proctoring terputus berulang kali',
            'server_terminated': 'Dihentikan oleh sistem pengawas',
            'ai_decision': 'Terdeteksi pelanggaran oleh sistem AI',
            'violation': 'Pelanggaran integritas ujian'
        };
        const reasonText = reasonMessages[reason] || reason;
        const redirectUrl = `disqualified.php?reason=${reason}&session=${encodeURIComponent(this.config.testSession)}`;

        // Full-screen termination overlay
        const overlay = document.createElement('div');
        overlay.id = 'proctor-terminate-overlay';
        overlay.style.cssText = `
            position:fixed; inset:0; z-index:99999;
            background:rgba(10,10,20,0.97);
            display:flex; align-items:center; justify-content:center;
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
        `;
        overlay.innerHTML = `
            <div class="proctor-terminate-content" style="text-align:center; max-width:480px; padding:40px;">
                <div style="width:80px;height:80px;margin:0 auto 24px;border-radius:50%;
                    background:rgba(239,68,68,0.15);display:flex;align-items:center;justify-content:center;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round">
                        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                </div>
                <h2 style="color:#ef4444;margin:0 0 12px;font-size:1.5rem;font-weight:700;">
                    Ujian Dihentikan
                </h2>
                <p style="color:#94a3b8;margin:0 0 8px;font-size:0.95rem;">
                    Ujian Anda telah dihentikan karena pelanggaran integritas.
                </p>
                <p style="color:#64748b;margin:0 0 24px;font-size:0.85rem;">
                    Alasan: ${reasonText}
                </p>
                <p style="color:#64748b;margin:0 0 32px;font-size:0.85rem;">
                    Silakan hubungi administrator untuk informasi lebih lanjut.
                </p>
                <a href="${redirectUrl}"
                   style="display:inline-block;padding:12px 32px;background:#ef4444;color:#fff;
                   border-radius:8px;text-decoration:none;font-weight:600;font-size:0.9rem;">
                    Lihat Detail
                </a>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    sendAction(action, data) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('test_session', this.config.testSession);
        for (let k in data) formData.append(k, data[k]);

        fetch(this.config.ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(json => {
                if (json.ai_action === 'terminate') this.terminateExam();
            })
            .catch(() => {});
    }

    // ============================================
    // UI HELPERS
    // ============================================
    showViolationWarning(severity, message) {
        const colors = {
            critical: '#7c3aed',
            high: '#ef4444',
            medium: '#f59e0b',
            low: '#3b82f6'
        };
        const color = colors[severity] || '#ef4444';

        let div = document.getElementById('proctor-warning');
        if (!div) {
            div = document.createElement('div');
            div.id = 'proctor-warning';
            div.style.cssText = `
                position:fixed; top:20px; left:50%; transform:translateX(-50%);
                color:white; padding:14px 28px;
                border-radius:12px; font-weight:600; z-index:9999;
                box-shadow:0 8px 24px rgba(0,0,0,0.3); transition:all 0.4s;
                font-size:0.85rem; max-width:520px; text-align:center;
            `;
            document.body.appendChild(div);
        }
        div.style.background = color;
        div.textContent = message;
        div.style.opacity = '1';
        div.style.transform = 'translateX(-50%) translateY(0)';

        clearTimeout(this._warningTimeout);
        this._warningTimeout = setTimeout(() => {
            div.style.opacity = '0';
            div.style.transform = 'translateX(-50%) translateY(-20px)';
        }, 5000);
    }

    showWarning(msg) {
        this.showViolationWarning('high', msg);
    }

    /**
     * Show proctoring rules toast at exam start
     */
    showProctoringRules() {
        const rules = document.createElement('div');
        rules.id = 'proctor-rules-toast';
        rules.style.cssText = `
            position:fixed; bottom:20px; right:20px; z-index:9998;
            background:rgba(15,15,26,0.95); color:#e2e8f0;
            border:1px solid rgba(255,255,255,0.1);
            border-radius:12px; padding:16px 20px; max-width:340px;
            font-size:0.8rem; line-height:1.6;
            box-shadow:0 8px 32px rgba(0,0,0,0.4);
            backdrop-filter:blur(12px);
            transition:opacity 0.5s, transform 0.5s;
        `;
        rules.innerHTML = `
            <div style="font-weight:700;margin-bottom:8px;color:#60a5fa;font-size:0.85rem;">
                &#128737; Proctoring Aktif
            </div>
            <div style="color:#94a3b8;font-size:0.75rem;">
                Batas toleransi pelanggaran:<br>
                &#8226; <span style="color:#7c3aed">Kritis</span> (perangkat terlarang / orang lain / devtools): maks <b>${this.violationLimits.critical.max}x</b><br>
                &#8226; <span style="color:#ef4444">Berat</span> (pindah tab): maks <b>${this.violationLimits.high.max}x</b><br>
                &#8226; <span style="color:#f59e0b">Sedang</span> (wajah hilang / clipboard / keluar jendela): maks <b>${this.violationLimits.medium.max}x</b><br>
                &#8226; <span style="color:#3b82f6">Ringan</span> (klik kanan dll): maks <b>${this.violationLimits.low.max}x</b>
            </div>
            <div style="margin-top:10px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.08);color:#64748b;font-size:0.7rem;">
                Melewati batas pelanggaran = ujian dihentikan otomatis.<br>
                Kamera dianalisis AI secara berkala untuk mendeteksi perangkat terlarang.
            </div>
            <button onclick="this.parentElement.remove()" style="
                position:absolute;top:8px;right:10px;background:none;border:none;
                color:#64748b;cursor:pointer;font-size:1.1rem;line-height:1;">&#10005;</button>
        `;
        document.body.appendChild(rules);

        // Auto-dismiss after 15s
        setTimeout(() => {
            if (rules.parentElement) {
                rules.style.opacity = '0';
                rules.style.transform = 'translateY(20px)';
                setTimeout(() => rules.remove(), 500);
            }
        }, 15000);
    }

    showPermissionModal() {}
    hidePermissionModal() {}
    updateStatus(msg) {}
}
