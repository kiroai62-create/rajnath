// Consultation call on the website (LiveKit). Used by the customer and by the astrologer (staff).
//
// What it handles so a call does not "end by itself":
// - Camera problems never end the call: the person joins with audio and can retry the camera.
// - Network drops: the SDK resumes the call; if that fails it rejoins with a fresh token (up to ~3 minutes).
// - Opening the call in a second tab or device: the older window explains why it left and does not rejoin.
// - Blocked sound (browser autoplay rules): a "Turn on sound" button appears.
// - The screen stays awake during the call (Wake Lock), and the page warns before closing an active call.
//
// The Room object is kept outside Alpine's reactive state: LiveKit and media objects must not be proxied.

const RETRY_SECONDS = [1, 2, 4, 8, 15, 30, 30, 30, 30, 30];

/** A phone or tablet held upright. */
const upright = () => window.matchMedia('(orientation: portrait)').matches && window.matchMedia('(pointer: coarse)').matches;

function micMessage(e) {
    if (e && e.name === 'NotAllowedError') { return 'Microphone is blocked. Allow it from the lock icon in the address bar, then press "Try microphone again".'; }
    if (e && e.name === 'NotFoundError') { return 'No microphone was found on this device. Connect one, then press "Try microphone again".'; }
    if (e && e.name === 'NotReadableError') { return 'Your microphone is in use by another app (for example Zoom). Close it, then press "Try microphone again".'; }
    return 'Your microphone could not start. Press "Try microphone again".';
}

function camMessage(e) {
    if (e && e.name === 'NotAllowedError') { return 'Camera is blocked. Allow it from the lock icon in the address bar, then press "Turn camera on". You are in the call with audio.'; }
    if (e && e.name === 'NotFoundError') { return 'No camera was found on this device. You are in the call with audio.'; }
    if (e && e.name === 'NotReadableError') { return 'Your camera is in use by another app or tab. Close it, then press "Turn camera on". You are in the call with audio.'; }
    return 'Your camera could not start. You are in the call with audio; press "Turn camera on" to try again.';
}

export function callRoom() {
    let lk = null;
    let room = null;
    let leaving = false;
    let attempt = 0;
    let retryTimer = null;
    let wakeLock = null;

    return {
        state: 'idle', error: '', notice: '', micProblem: false, micOn: false, camOn: false, remoteCount: 0, remoteName: '', remoteCam: false,
        needsAudio: false, weak: false, retryIn: 0, portrait: false,

        get idle() { return this.state === 'idle' || this.state === 'ended'; },
        get connecting() { return this.state === 'connecting'; },
        get reconnecting() { return this.state === 'reconnecting'; },
        get live() { return this.state === 'live'; },
        get inCall() { return this.state === 'live' || this.state === 'reconnecting'; },
        get ended() { return this.state === 'ended'; },
        get joinLabel() { return this.state === 'ended' ? 'Rejoin call' : 'Join call'; },
        get micLabel() { return this.micOn ? 'Mute' : 'Unmute'; },
        get camLabel() { return this.camOn ? 'Turn camera off' : 'Turn camera on'; },
        get waiting() { return this.live && this.remoteCount === 0; },
        get remoteCamOff() { return this.live && this.remoteCount > 0 && !this.remoteCam && !this.audioOnly; },
        get remoteCamOffText() { return (this.remoteName || 'The other person') + ' is in the call with the camera off.'; },
        get audioOnly() { return this.$root.dataset.audioOnly === '1'; },
        get camControl() { return this.inCall && !this.audioOnly; },
        get micRetry() { return this.inCall && this.micProblem; },
        get stageClass() { return this.portrait ? 'aspect-[3/4] max-h-[78vh] max-w-md' : 'aspect-video max-w-4xl'; },
        get reconnectText() { return this.retryIn > 0 ? 'Connection lost. Trying again in ' + this.retryIn + ' s…' : 'Reconnecting… please keep this page open.'; },

        init() {
            window.addEventListener('online', () => { if (this.state === 'reconnecting' && retryTimer) { clearTimeout(retryTimer); retryTimer = null; this.connect(); } });
            window.addEventListener('beforeunload', (e) => { if (this.inCall) { e.preventDefault(); e.returnValue = ''; } });
            window.addEventListener('pagehide', () => { leaving = true; if (room) { room.disconnect(); } });
            document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && this.inCall) { this.keepAwake(); } });
        },

        async join() {
            leaving = false;
            attempt = 0;
            this.error = '';
            this.notice = '';
            await this.connect();
        },

        /** Call log for Admin › Bookings (best effort, never blocks the call). */
        report(event, detail) {
            const url = this.$root.dataset.eventUrl;
            if (!url) { return; }
            fetch(url, {
                method: 'POST', credentials: 'same-origin', keepalive: true,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ event, detail: detail ? String(detail).slice(0, 200) : null }),
            }).catch(() => {});
        },

        async token() {
            const r = await fetch(this.$root.dataset.tokenUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            const d = await r.json().catch(() => ({}));
            if (!r.ok) {
                const err = new Error((d.error && d.error.message) || (r.status === 419 ? 'Your session expired. Please reload this page.' : 'Could not start the call.'));
                err.final = r.status >= 400 && r.status < 500 && r.status !== 429;
                throw err;
            }
            return d;
        },

        async connect() {
            this.state = attempt > 0 ? 'reconnecting' : 'connecting';
            this.retryIn = 0;
            let d;
            try {
                d = await this.token();
                lk = lk || await import('livekit-client');
            } catch (e) {
                if (e.final || attempt === 0) { this.state = attempt > 0 ? 'ended' : 'idle'; this.error = e.message || 'Could not start the call. Check your internet and try again.'; this.report('failed', this.error); return; }
                this.scheduleRetry();
                return;
            }
            const { Room, RoomEvent, Track, VideoPresets } = lk;
            // Sharp faces both ways: 720p at up to 1.7 Mbit/s with two lower layers for weak connections, and the
            // layer chosen for the real screen pixels (phones have 2-3x density; the default assumed less).
            const r = new Room({
                adaptiveStream: { pixelDensity: 'screen' }, dynacast: true,
                // An upright phone asks for an upright picture; asking for 1280x720 there made browsers crop it.
                videoCaptureDefaults: { resolution: upright() ? { width: 720, height: 1280, frameRate: 30 } : VideoPresets.h720.resolution, facingMode: 'user' },
                audioCaptureDefaults: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                publishDefaults: {
                    simulcast: true,
                    videoEncoding: { maxBitrate: 1_700_000, maxFramerate: 30 },
                    videoSimulcastLayers: [VideoPresets.h180, VideoPresets.h360],
                    degradationPreference: 'maintain-resolution',
                },
            });
            room = r;
            this.wire(r, RoomEvent, Track);
            try {
                await r.connect(d.url, d.token, { autoSubscribe: true });
            } catch (e) {
                room = null;
                r.disconnect();
                if (attempt === 0 && !navigator.onLine) { this.state = 'idle'; this.error = 'You are offline. Check your internet and try again.'; return; }
                this.report('failed', 'connect: ' + ((e && e.message) || 'error'));
                if (attempt === 0) { attempt = 1; }
                this.scheduleRetry();
                return;
            }
            if (r !== room) { return; }
            this.report(attempt > 0 ? 'rejoined' : 'joined', navigator.userAgent);
            attempt = 0;
            this.state = 'live';
            this.error = '';
            this.notice = '';
            this.rebuildRemote(r, Track);
            this.needsAudio = !r.canPlaybackAudio;
            this.keepAwake();
            await this.startMic();
            if (!this.audioOnly) { await this.startCam(); }
        },

        wire(r, RoomEvent, Track) {
            const mine = () => r === room;
            r.on(RoomEvent.TrackSubscribed, () => { if (mine()) { this.rebuildRemote(r, Track); } })
                .on(RoomEvent.TrackUnsubscribed, (track) => { track.detach().forEach((el) => el.remove()); if (mine()) { this.rebuildRemote(r, Track); } })
                .on(RoomEvent.TrackMuted, () => { if (mine()) { this.refreshRemote(r, Track); } })
                .on(RoomEvent.TrackUnmuted, () => { if (mine()) { this.refreshRemote(r, Track); } })
                .on(RoomEvent.ParticipantConnected, () => { if (mine()) { this.refreshRemote(r, Track); } })
                .on(RoomEvent.ParticipantDisconnected, () => { if (mine()) { this.rebuildRemote(r, Track); } })
                .on(RoomEvent.Reconnecting, () => { if (mine()) { this.state = 'reconnecting'; this.report('reconnecting', 'media'); } })
                .on(RoomEvent.SignalReconnecting, () => { if (mine()) { this.state = 'reconnecting'; } })
                .on(RoomEvent.Reconnected, () => { if (mine()) { this.state = 'live'; this.retryIn = 0; this.rebuildRemote(r, Track); this.report('reconnected'); } })
                .on(RoomEvent.AudioPlaybackStatusChanged, () => { if (mine()) { this.needsAudio = !r.canPlaybackAudio; } })
                .on(RoomEvent.ConnectionQualityChanged, (q, p) => { if (mine() && p === r.localParticipant) { this.weak = q === 'poor' || q === 'lost'; } })
                .on(RoomEvent.MediaDevicesError, (e) => { if (mine()) { this.notice = camMessage(e); } })
                .on(RoomEvent.Disconnected, (reason) => { if (mine()) { this.onDisconnected(reason); } });
        },

        onDisconnected(reason) {
            const R = lk.DisconnectReason;
            room = null;
            this.clearMedia();
            this.micOn = false;
            this.camOn = false;
            if (leaving) { this.state = 'ended'; return; }
            const names = Object.keys(R).filter((k) => isNaN(Number(k)));
            this.report('dropped', names.find((k) => R[k] === reason) || String(reason));
            if (reason === R.DUPLICATE_IDENTITY) { this.state = 'ended'; this.error = 'You opened this call in another tab or device, so this window left the call. Use the other window, or press "Rejoin call" to move the call here.'; return; }
            if (reason === R.ROOM_DELETED || reason === R.PARTICIPANT_REMOVED) { this.state = 'ended'; this.error = 'The call was ended.'; return; }
            // Network, server restart or anything else: come back automatically.
            attempt = Math.max(attempt, 1);
            this.scheduleRetry();
        },

        scheduleRetry() {
            if (leaving) { this.state = 'ended'; return; }
            if (attempt > RETRY_SECONDS.length) {
                this.state = 'ended';
                this.error = 'The connection was lost and could not be restored. Check your internet, then press "Rejoin call".';
                this.report('failed', 'gave up rejoining');
                return;
            }
            this.state = 'reconnecting';
            let left = RETRY_SECONDS[attempt - 1];
            attempt++;
            this.retryIn = left;
            const tick = () => {
                left--;
                this.retryIn = Math.max(0, left);
                if (left <= 0) { retryTimer = null; this.connect(); } else { retryTimer = setTimeout(tick, 1000); }
            };
            retryTimer = setTimeout(tick, 1000);
        },

        /**
         * Shows exactly the other person's current tracks. After a reconnect the SDK may hand out new track objects
         * while old, dead video elements are still on the page; rebuilding from the room avoids a frozen picture.
         */
        rebuildRemote(r, Track) {
            const box = this.$refs.remote;
            box.querySelectorAll('video, audio').forEach((el) => { el.srcObject = null; el.remove(); });
            r.remoteParticipants.forEach((p) => p.trackPublications.forEach((pub) => {
                if (pub.track && pub.isSubscribed) { pub.track.detach(); this.attachRemote(pub.track, Track); }
            }));
            this.refreshRemote(r, Track);
        },

        attachRemote(track, Track) {
            const el = track.attach();
            el.dataset.sid = track.sid;
            if (track.kind === Track.Kind.Video) {
                // The whole picture, never cropped: a phone held upright sends a tall video, which "cover" cut down
                // to a blurry strip. The box takes the video's own shape (see fitBox).
                el.className = 'h-full w-full bg-ink-900 object-contain';
                el.setAttribute('playsinline', '');
                const fit = () => this.fitBox(el);
                el.addEventListener('loadedmetadata', fit);
                el.addEventListener('resize', fit);
            }
            this.$refs.remote.appendChild(el);
        },

        /** Tall box for an upright phone video, wide box for a landscape one. */
        fitBox(video) {
            if (!video.videoWidth || !video.videoHeight) { return; }
            this.portrait = video.videoHeight > video.videoWidth * 1.1;
        },

        refreshRemote(r, Track) {
            const people = [...r.remoteParticipants.values()];
            this.remoteCount = people.length;
            this.remoteName = people[0] ? (people[0].name || '') : '';
            this.remoteCam = people.some((p) => [...p.videoTrackPublications.values()].some((pub) => pub.isSubscribed && pub.track && !pub.isMuted && pub.source !== Track.Source.ScreenShare));
        },

        async startMic() {
            if (!room) { return; }
            try {
                await room.localParticipant.setMicrophoneEnabled(true);
                this.micOn = true;
                if (this.micProblem) { this.micProblem = false; this.notice = ''; }
            } catch (e) {
                this.micOn = false;
                this.micProblem = true;
                this.notice = micMessage(e);
                this.report('mic_error', e && e.name);
            }
        },

        async startCam() {
            if (!room) { return; }
            try {
                const pub = await room.localParticipant.setCameraEnabled(true);
                this.camOn = true;
                this.showLocal(pub);
                if (/camera/i.test(this.notice)) { this.notice = ''; }
            } catch (e) {
                this.camOn = false;
                this.notice = camMessage(e);
                this.report('camera_error', e && e.name);
                await room.localParticipant.setCameraEnabled(false).catch(() => {});
            }
        },

        showLocal(pub) {
            const local = this.$refs.local;
            local.replaceChildren();
            const p = pub || (room && [...room.localParticipant.videoTrackPublications.values()][0]);
            if (p && p.track) {
                const el = p.track.attach();
                el.muted = true;
                el.setAttribute('playsinline', '');
                el.className = 'h-full w-full rounded-xl object-cover';
                local.appendChild(el);
            }
        },

        async toggleMic() {
            if (!room) { return; }
            if (!this.micOn) { await this.startMic(); return; }
            try { await room.localParticipant.setMicrophoneEnabled(false); this.micOn = false; } catch { /* keep state */ }
        },

        async toggleCam() {
            if (!room) { return; }
            if (!this.camOn) { await this.startCam(); return; }
            try { await room.localParticipant.setCameraEnabled(false); } catch { /* already off */ }
            this.camOn = false;
            this.$refs.local.replaceChildren();
        },

        async startAudio() {
            if (room) { await room.startAudio().catch(() => {}); this.needsAudio = !room.canPlaybackAudio; }
        },

        async keepAwake() {
            try { if ('wakeLock' in navigator && !wakeLock) { wakeLock = await navigator.wakeLock.request('screen'); wakeLock.addEventListener('release', () => { wakeLock = null; }); } } catch { /* not allowed now */ }
        },

        clearMedia() {
            this.$refs.remote.replaceChildren();
            this.$refs.local.replaceChildren();
            this.remoteCount = 0;
            this.remoteCam = false;
            this.needsAudio = false;
            this.weak = false;
            if (wakeLock) { wakeLock.release().catch(() => {}); wakeLock = null; }
        },

        leave() {
            if (this.inCall) { this.report('left'); }
            leaving = true;
            if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
            const r = room;
            room = null;
            if (r) { r.disconnect(); }
            this.clearMedia();
            this.micOn = false;
            this.camOn = false;
            this.retryIn = 0;
            this.state = 'ended';
            this.notice = '';
        },
    };
}
