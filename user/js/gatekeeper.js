class Gatekeeper {
    constructor(proctorSDK) {
        this.sdk = proctorSDK;
        this.step = 0;
        this.steps = ['welcome', 'hardware', 'environment', 'analysis'];
        this.snapshots = {};
        this.container = null;
        
        this.initUI();
    }
    
    initUI() {
        const html = `
        <div id="gatekeeper-overlay">
            <div class="gk-card">
                <div class="gk-steps">
                    <div class="gk-step active" id="step-0"></div>
                    <div class="gk-step" id="step-1"></div>
                    <div class="gk-step" id="step-2"></div>
                    <div class="gk-step" id="step-3"></div>
                </div>
                
                <h1 class="gk-title" id="gk-title">Security Check</h1>
                <p class="gk-subtitle" id="gk-desc">Preparing your exam environment...</p>
                
                <div class="gk-visualizer hidden" id="gk-viz">
                    <video id="gk-video" autoplay muted playsinline></video>
                    <div class="gk-scan-overlay" id="gk-overlay"></div>
                    <div class="audio-bars" id="gk-audio-bars">
                        <div class="audio-bar"></div><div class="audio-bar"></div><div class="audio-bar"></div>
                        <div class="audio-bar"></div><div class="audio-bar"></div>
                    </div>
                </div>
                
                <button class="gk-btn" id="gk-action">Begin Check</button>
            </div>
        </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', html);
        
        this.title = document.getElementById('gk-title');
        this.desc = document.getElementById('gk-desc');
        this.btn = document.getElementById('gk-action');
        this.viz = document.getElementById('gk-viz');
        this.video = document.getElementById('gk-video');
        
        this.btn.onclick = () => this.nextStep();
        
        // Block interaction with main page
        document.body.style.overflow = 'hidden';
    }
    
    async nextStep() {
        this.step++;
        this.updateStepIndicator();
        
        switch(this.step) {
            case 1: // Hardware
                this.startHardwareCheck();
                break;
            case 2: // Environment
                this.startEnvironmentScan();
                break;
            case 3: // Analysis
                this.startAIAnalysis();
                break;
            case 4: // Done
                this.finish();
                break;
        }
    }
    
    updateStepIndicator() {
        document.querySelectorAll('.gk-step').forEach((el, i) => {
            if (i < this.step) el.classList.add('done');
            if (i === this.step) el.classList.add('active');
            else el.classList.remove('active');
        });
    }
    
    async startHardwareCheck() {
        this.title.innerText = "Hardware Verification";
        this.desc.innerText = "We need to access your camera and microphone. Please allow permissions.";
        this.btn.innerText = "Request Access";
        this.btn.onclick = async () => {
            try {
                // Initialize SDK Camera
                await this.sdk.initCamera();
                
                if (this.sdk.videoStream) {
                    this.video.srcObject = this.sdk.videoStream;
                    this.viz.classList.remove('hidden');
                    this.startAudioVisualizer();
                    
                    this.title.innerText = "System Go";
                    this.desc.innerText = "Camera and Microphone are active. Please position yourself in the frame.";
                    this.btn.innerText = "Looks Good";
                    this.btn.onclick = () => this.nextStep();
                }
            } catch (e) {
                this.desc.innerText = "Error: " + e.message;
            }
        };
    }
    
    startAudioVisualizer() {
        if (!this.sdk.videoStream) return;
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        // Check if stream has audio tracks
        if (this.sdk.videoStream.getAudioTracks().length === 0) return;
        
        const source = audioCtx.createMediaStreamSource(this.sdk.videoStream);
        const analyser = audioCtx.createAnalyser();
        analyser.fftSize = 32;
        source.connect(analyser);
        
        const bufferLength = analyser.frequencyBinCount;
        const dataArray = new Uint8Array(bufferLength);
        const bars = document.querySelectorAll('.audio-bar');
        
        const draw = () => {
            requestAnimationFrame(draw);
            analyser.getByteFrequencyData(dataArray);
            
            bars.forEach((bar, i) => {
                const val = dataArray[i * 2] || 0;
                bar.style.height = Math.max(5, val / 2) + 'px';
            });
        };
        draw();
    }
    
    startEnvironmentScan() {
        this.title.innerText = "Environment Scan";
        this.desc.innerText = "Please look directly at the camera. We will analyze your environment for prohibited items.";
        this.btn.innerText = "Scan Now";
        
        this.btn.onclick = async () => {
            this.btn.disabled = true;
            this.btn.innerText = "Scanning...";
            document.getElementById('gk-overlay').classList.add('scanning');
            
            // Wait 2 seconds
            await new Promise(r => setTimeout(r, 2000));
            
            // Capture
            const blob = await this.captureFrame();
            this.snapshots['face'] = blob;
            
            document.getElementById('gk-overlay').classList.remove('scanning');
            document.getElementById('gk-overlay').classList.add('success');
            
            this.btn.disabled = false;
            this.btn.innerText = "Submit for Analysis";
            this.btn.onclick = () => this.nextStep();
        };
    }
    
    async captureFrame() {
        const track = this.sdk.videoStream.getVideoTracks()[0];
        const imageCapture = new ImageCapture(track);
        return await imageCapture.takePhoto();
    }
    
    async startAIAnalysis() {
        this.title.innerText = "AI Verification";
        this.desc.innerText = "Our Guardian AI is analyzing your environment safety...";
        this.btn.classList.add('hidden');
        this.viz.classList.add('hidden');
        
        // Upload and Analyze
        const formData = new FormData();
        formData.append('action', 'log_event'); // Use log_event to trigger save + analyze
        formData.append('test_session', this.sdk.config.testSession);
        formData.append('type', 'gatekeeper_check');
        formData.append('severity', 'high'); // Triggers analysis
        formData.append('snapshot', this.snapshots['face']);
        
        try {
            const res = await fetch(this.sdk.config.ajaxUrl, { method: 'POST', body: formData });
            const json = await res.json();
            
            if (json.ai_analysis && json.ai_analysis.verdict) {
                const v = json.ai_analysis.verdict.toLowerCase();
                const score = json.ai_analysis.risk_score || 0;
                
                if (v === 'clean' || score < 50) {
                    this.finishSuccess();
                } else {
                    this.finishFail(json.ai_analysis.detected || []);
                }
            } else {
                // Fallback if AI fails or disabled
                this.finishSuccess();
            }
        } catch (e) {
            console.error(e);
            this.finishSuccess(); // Fail open for now
        }
    }
    
    finishSuccess() {
        this.title.innerText = "Access Granted";
        this.desc.innerText = "Your environment is secure. Good luck.";
        this.btn.classList.remove('hidden');
        this.btn.innerText = "Enter Exam";
        this.btn.onclick = () => this.finish();
        
        // Green confetti or success animation could go here
    }
    
    finishFail(reasons) {
        this.title.innerText = "Security Alert";
        this.title.style.color = 'var(--accent-red)';
        this.desc.innerText = "Issues detected: " + (reasons.join(', ') || "Suspicious environment") + ". Please clear your desk and try again.";
        this.btn.classList.remove('hidden');
        this.btn.innerText = "Retry Check";
        this.btn.onclick = () => {
            this.step = 1; // Go back to env scan
            this.nextStep();
        };
    }
    
    finish() {
        document.getElementById('gatekeeper-overlay').remove();
        document.body.style.overflow = '';
        // Start Proctoring Loop
        this.sdk.start();
    }
}
