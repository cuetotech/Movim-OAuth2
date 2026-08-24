var VisioUtils = {
    maxLevel: 0,
    remoteMaxLevel: 0,
    audioContext: null,
    remoteAudioContext: null,

    onRinging: function () {
        const status = MovimVisio.states?.ringing;
        if (!status) return;
        const state = document.querySelector('#visio p.state');
        if (state) state.innerText = status;
        Notif.setCallStatus(status);
    },

    renderAudioLevel: function (level, state) {
        let step = 0;
        if (level == 0) state.isMuteStep++;
        else state.isMuteStep = 0;

        let noMicSound = document.querySelector('#no_mic_sound');
        let defaultMicrophone = document.querySelector('#default_microphone');
        let toggleAudio = document.getElementById('toggle_audio');

        if (state.isMuteStep > 32) {
            noMicSound?.classList.remove('disabled');
            defaultMicrophone?.classList.add('muted');
        } else {
            noMicSound?.classList.add('disabled');
            defaultMicrophone?.classList.remove('muted');
            if (state.isMuteStep <= 5) {
                document.querySelectorAll('.level span').forEach(span => {
                    if (step < Math.floor(level * 10)) span.classList.remove('disabled');
                    else span.classList.add('disabled');
                    step++;
                });
                toggleAudio?.style.setProperty('--level', level.toFixed(2));
            }
        }
    },

    handleAudio: async function () {
        if (VisioUtils.audioContext) {
            await VisioUtils.audioContext.close();
            VisioUtils.audioContext = null;
        }

        VisioUtils.audioContext = new AudioContext();
        VisioUtils.maxLevel = 0;

        let microphone;
        try {
            microphone = VisioUtils.audioContext.createMediaStreamSource(MovimVisio.localAudio.srcObject);
        } catch (error) {
            MovimUtils.logError(error);
            return;
        }

        let icon = document.querySelector('#toggle_audio i');
        if (icon) icon.innerText = 'mic';
        let defaultMicrophone = document.querySelector('#default_microphone');
        defaultMicrophone?.classList.add('muted');
        const state = { isMuteStep: 251 };

        if (VisioUtils.audioContext.audioWorklet && typeof AudioWorkletNode !== 'undefined') {
            try {
                await VisioUtils.audioContext.audioWorklet.addModule(BASE_URI + 'scripts/movim_visio_audio_worklet.js');
                const worklet = new AudioWorkletNode(VisioUtils.audioContext, 'visio-audioworklet');
                const muteGain = VisioUtils.audioContext.createGain();
                muteGain.gain.value = 0;
                microphone.connect(worklet);
                worklet.connect(muteGain);
                muteGain.connect(VisioUtils.audioContext.destination);
                worklet.port.onmessage = event => VisioUtils.renderAudioLevel(event.data.level, state);
                return;
            } catch (error) {
                MovimUtils.logError(error);
            }
        }

        const javascriptNode = VisioUtils.audioContext.createScriptProcessor(128 * 64, 1, 1);
        microphone.connect(javascriptNode);
        javascriptNode.connect(VisioUtils.audioContext.destination);
        javascriptNode.onaudioprocess = function (event) {
            const input = event.inputBuffer.getChannelData(0);
            let sum = 0;
            for (let i = 0; i < input.length; ++i) sum += input[i] * input[i];
            const instant = Math.sqrt(sum / input.length);
            VisioUtils.maxLevel = Math.max(VisioUtils.maxLevel, instant, 0.005);
            const base = instant / VisioUtils.maxLevel;
            const level = base > 0.05 ? base ** .3 : 0;
            VisioUtils.renderAudioLevel(level, state);
        };
    },

    toggleFullScreen: function () {
        let button = document.querySelector('#toggle_fullscreen i');
        if (!document.fullscreenElement) {
            if (document.querySelector('#visio').requestFullscreen) document.querySelector('#visio').requestFullscreen();
            button.innerText = 'fullscreen_exit';
        } else {
            if (document.exitFullscreen) document.exitFullscreen();
            button.innerText = 'fullscreen';
        }
    },

    toggleMode: function (active) {
        let button = document.querySelector('#toggle_mode i');
        let participants = document.querySelector('#participants');
        if (button.innerText == 'tile_small' || active) {
            participants.classList.add('active');
            button.innerHTML = 'tile_large';
        } else {
            participants.classList.remove('active');
            button.innerHTML = 'tile_small';
        }
    },

    toggleAudio: function () {
        let button = document.querySelector('#toggle_audio i');
        if (button.innerText == 'mic_off') {
            MovimJingles.enableAudio(true);
            button.innerText = 'mic';
        } else {
            MovimJingles.enableAudio(false);
            button.innerText = 'mic_off';
        }
    },

    toggleVideo: async function () {
        let button = document.querySelector('#toggle_video i');
        if (MovimVisio.localVideo.srcObject == null) {
            MovimVisio.getUserMedia(true).then(e => {
                MovimJingles.enableVideo(true);
                MovimVisio.localVideo.classList.remove('video_off');
                button.innerText = 'videocam';
            });
        } else {
            if (button.innerText == 'videocam_off') {
                MovimJingles.enableVideo(true);
                MovimVisio.localVideo.classList.remove('video_off');
                button.innerText = 'videocam';
            } else {
                MovimJingles.enableVideo(false);
                MovimVisio.localVideo.classList.add('video_off');
                button.innerText = 'videocam_off';
            }
        }
    },

    toggleScreenSharing: async function () {
        MovimVisio.switchCamera = document.querySelector('#visio #switch_camera');
        let button = document.querySelector('#screen_sharing i');
        if (MovimVisio.screenSharing.srcObject == null) {
            try {
                MovimVisio.screenSharing.srcObject = await navigator.mediaDevices.getDisplayMedia({
                    video: { cursor: 'always' },
                    audio: true
                });
                MovimVisio.screenSharing.classList.add('sharing');
                VisioUtils.disableSwitchCameraButton();
                button.innerText = 'stop_screen_share';
                MovimJingles.enableScreenSharing();
                MovimVisio.mujiPublish();
            } catch (err) {
                console.error('Error: ' + err);
            }
            return;
        } else {
            VisioUtils.disableScreenSharing();
            MovimVisio.mujiPublish();
        }
    },

    disableScreenSharing: function () {
        MovimJingles.disableScreenSharing();
        if (MovimVisio.screenSharing && MovimVisio.screenSharing.srcObject) {
            MovimVisio.screenSharing.srcObject.getTracks().forEach(track => track.stop());
            MovimVisio.screenSharing.srcObject = null;
            MovimVisio.screenSharing.classList.remove('sharing');
            MovimVisio.switchCamera.classList.remove('disabled');
        }
        if (button = document.querySelector('#screen_sharing i')) button.innerText = 'screen_share';
    },

    switchChat: function () {
        let visio = document.querySelector('#visio');
        if (visio.dataset.jid) Search.chat(visio.dataset.jid, (visio.dataset.muji == 'true'));
    },

    toggleDtmf: function () { document.querySelector('#visio #dtmf').classList.toggle('hide'); },

    insertDtmf: function (s) {
        VisioDTMF.pressButton(s);
        setTimeout(() => VisioDTMF.stop(), 100);
        let insert = (s == '*') ? '🞳' : s;
        document.querySelector('#dtmf p.dtmf span').innerText += insert;
        MovimJingles.insertDtmf(s);
    },

    clearDtMf: function () { document.querySelector('#dtmf p.dtmf span').innerText = ''; },
    disableSwitchCameraButton: function () { MovimVisio.switchCamera.classList.add('disabled'); },
    enableLobbyCallButton: function () { document.querySelector('#lobby_start')?.classList.remove('disabled'); },
    disableLobbyCallButton: function () { document.querySelector('#lobby_start')?.classList.add('disabled'); },

    getConstraints: function (withVideo) {
        const cpuCores = navigator.hardwareConcurrency || 2;
        let constraints = { audio: true, video: false };
        if (withVideo) {
            if (cpuCores <= 2) constraints.video = { width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { max: 15, ideal: 15 } };
            else if (cpuCores <= 4) constraints.video = { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { max: 30, ideal: 24 } };
            else constraints.video = { width: { ideal: 1920 }, height: { ideal: 1080 }, frameRate: { max: 30, ideal: 30 } };
            constraints.video.facingMode = 'user';
            if (localStorage.defaultCamera) constraints.video.deviceId = localStorage.defaultCamera;
        }
        if (localStorage.defaultMicrophone) constraints.audio = { deviceId: localStorage.defaultMicrophone };
        return constraints;
    },

    setVideoCodecPreferences: function (transceiver) {
        let codecs = RTCRtpReceiver.getCapabilities('video').codecs;
        if (transceiver.setCodecPreferences != undefined) {
            let preferredOrder = ['video/H264', 'video/VP8', 'video/VP9'];
            codecs.sort((a, b) => {
                const indexA = preferredOrder.indexOf(a.mimeType);
                const indexB = preferredOrder.indexOf(b.mimeType);
                return (indexA >= 0 ? indexA : Number.MAX_VALUE) - (indexB >= 0 ? indexB : Number.MAX_VALUE);
            });
            transceiver.setCodecPreferences(codecs);
        }
    },

    adaptToNetworkCondition: async function (peerConnection) {
        const stats = await peerConnection.getStats();
        let packetLossRate = 0;
        let rtt = 0;
        stats.forEach(stat => {
            if (stat.type === 'outbound-rtp' && stat.kind === 'video' && stat.packetsSent && stat.packetsLost) packetLossRate = stat.packetsLost / stat.packetsSent;
            if (stat.type === 'remote-inbound-rtp') rtt = stat.roundTripTime;
        });
        let networkIcon = document.querySelector('#network_condition');
        networkIcon.classList.remove('excellent', 'good', 'bad');
        peerConnection.getSenders().filter(s => s.track && s.track.kind === 'video').forEach(sender => {
            const parameters = sender.getParameters();
            if (!parameters.encodings || parameters.encodings.length === 0) parameters.encodings = [{}];
            if (packetLossRate > 0.1 || rtt > 0.6) {
                networkIcon.classList.add('bad');
                parameters.encodings[0].maxBitrate = 250000;
                parameters.encodings[0].scaleResolutionDownBy = 4;
            } else if (packetLossRate > 0.05 || rtt > 0.3) {
                networkIcon.classList.add('good');
                parameters.encodings[0].maxBitrate = 500000;
                parameters.encodings[0].scaleResolutionDownBy = 2;
            } else {
                networkIcon.classList.add('excellent');
                parameters.encodings[0].maxBitrate = 1500000;
                parameters.encodings[0].scaleResolutionDownBy = 1;
            }
            sender.setParameters(parameters);
        });
    },

    cancelLobby: function (fullJid, id) {
        if (fullJid && id) Visio_ajaxReject(fullJid, id);
        Visio_ajaxClear(); Dialog_ajaxClear(); Notif.snackbarClear();
    }
};
