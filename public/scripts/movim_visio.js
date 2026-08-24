var MovimVisio = {
    id: null,

    calling: false,
    pendingCall: null,

    pc: null,

    states: null,
    services: [],

    localStream: null,
    localVideo: null,
    localAudio: null,
    screenSharing: null,
    hasLocalVideo: false,

    observer: null,

    activeSpeakerIntervalId: null,

    bundleRegex: 'a=group:(\\S+) (.+)',
    msidRegex: 'a=msid:(.+)',

    load: function () {
        MovimVisio.localVideo = document.getElementById('local_video');
        MovimVisio.localVideo.addEventListener('loadeddata', () => {
            MovimVisio.localVideo.play()
        });

        MovimVisio.screenSharing = document.getElementById('screen_sharing_video');
        MovimVisio.localAudio = document.getElementById('local_audio');
    },

    activateCallUI: function (jid, id, withVideo, isMuji) {
        Visio_ajaxPrepare(jid);

        MovimVisio.id = id;
        MovimVisio.calling = false;

        // Set a lock for the current browser (in case there's several others opened)
        localStorage.setItem('callId', id);
        localStorage.setItem('callJid', jid);

        let visio = document.querySelector('#visio');
        delete visio.dataset.type;
        visio.dataset.jid = jid;
        visio.dataset.type = (withVideo) ? 'video' : 'audio';
        visio.dataset.muji = isMuji ? 'true' : 'false';
    },

    init: function (fullJid, jid, id, withVideo, isMuji, sendProceed = true) {
        MovimVisio.activateCallUI(jid, id, withVideo, isMuji);

        if (isMuji == true) {
            let pc = new RTCPeerConnection({ 'iceServers': MovimVisio.services });

            MovimVisio.requestUserMedia(withVideo).then(stream => {
                stream.getTracks().forEach(track => {
                    pc.addTrack(track, stream);
                });

                pc.createOffer().then(function (offer) {
                    Visio_ajaxMujiInit(MovimVisio.id, offer);
                    pc.close();
                });
            });

            MovimVisio.activeSpeakerIntervalId = setInterval(MovimJingles.checkActiveSpeaker, 1000);
        } else {
            MovimJingles.initSession(jid, fullJid, id);

            if (sendProceed) {
                Visio_ajaxProceed(fullJid, MovimVisio.id);
            } else {
                MovimJingles.onProceed(jid, fullJid, MovimVisio.id);
            }
        }

        MovimVisio.setState(MovimVisio.states.connecting);
    },

    startLobbyCall: function (fullJid, jid, withVideo) {
        if (MovimVisio.pendingCall || Object.keys(MovimJingles.sessions).length > 0 || !MovimVisio.localStream) {
            return;
        }

        MovimVisio.pendingCall = {
            fullJid: fullJid,
            jid: jid,
            id: crypto.randomUUID(),
            withVideo: withVideo
        };
        MovimVisio.calling = true;

        const startButton = document.getElementById('lobby_start');
        if (startButton) {
            startButton.classList.add('disabled');
        }

        MovimVisio.setLobbyStatus(MovimVisio.states.calling);
        MovimVisio.setState(MovimVisio.states.calling);
        Visio_ajaxPropose(jid, MovimVisio.pendingCall.id, withVideo);
        Notif.snackbarClear();
    },

    abortPendingCall: function (status = null) {
        MovimVisio.pendingCall = null;
        MovimVisio.calling = false;

        const startButton = document.getElementById('lobby_start');
        if (startButton) {
            startButton.classList.remove('disabled');
        }

        if (status != null) {
            MovimVisio.setLobbyStatus(status);
            MovimVisio.setState(status);
        }
    },

    onRinging: function (jid, fullJid, id) {
        if (!MovimVisio.pendingCall
            || MovimVisio.pendingCall.id !== id
            || MovimVisio.pendingCall.jid !== jid) {
            return;
        }

        MovimVisio.setLobbyStatus(MovimVisio.states.ringing);
        MovimVisio.setState(MovimVisio.states.ringing);
    },

    onProceed: function (jid, fullJid, id) {
        if (!MovimVisio.pendingCall || MovimVisio.pendingCall.id !== id) {
            if (MovimJingles.sessions[jid] != undefined) {
                MovimJingles.onProceed(jid, fullJid, id);
            }

            return;
        }

        const pendingCall = MovimVisio.pendingCall;
        MovimVisio.pendingCall = null;
        Dialog_ajaxClear();
        MovimVisio.init(fullJid, pendingCall.jid, id, pendingCall.withVideo, false, false);
    },

    setLobbyStatus: function (status) {
        const lobbyStatus = document.getElementById('visio_lobby_status');

        if (lobbyStatus) {
            lobbyStatus.innerText = status ?? '';
        }
    },

    setState: function (status) {
        const state = document.querySelector('#visio p.state');

        if (state) {
            state.innerText = status ?? '';
        }

        Notif.setCallStatus(status);
    },

    setStates: function (states) {
        MovimVisio.states = states;
    },

    setServices: function (services) {
        MovimVisio.services = services;
    },

    getUserMedia: function (withVideo) {
        MovimVisio.load();

        let lobby = document.querySelector('#visio_lobby');

        if (lobby) {
            VisioUtils.disableLobbyCallButton();
        }

        MovimTpl.loadingPage();

        MovimVisio.requestUserMedia(withVideo).then(stream => {
            MovimTpl.finishedPage();

            MovimVisio.localStream = stream;
            MovimVisio.hasLocalVideo = stream.getTracks().some(track => track.kind === 'video');
            let visio = document.querySelector('#visio');
            if (visio) {
                visio.dataset.hasLocalVideo = MovimVisio.hasLocalVideo ? 'true' : 'false';
            }

            if (lobby) {
                lobby.classList.add('configure');
            } else {
                Visio_ajaxClear();
                return;
            }

            stream.getTracks().forEach(track => {
                if (lobby) {
                    VisioUtils.enableLobbyCallButton();
                }

                if (track.kind == 'audio') {
                    MovimVisio.localAudio.srcObject = stream;
                } else if (track.kind === 'video') {
                    MovimVisio.localVideo.srcObject = stream;

                    if (lobby) {
                        let cameraPreview = lobby.querySelector('video#camera_preview');
                        if (cameraPreview) {
                            cameraPreview.addEventListener('loadeddata', () => cameraPreview.play());
                            cameraPreview.srcObject = stream;
                            cameraPreview.disablePictureInPicture = true;
                        }
                    }
                }
            });

            if (!MovimVisio.hasLocalVideo && MovimVisio.localVideo) {
                MovimVisio.localVideo.srcObject = null;
                MovimVisio.localVideo.classList.add('video_off');
                let cameraPreview = document.querySelector('video#camera_preview');
                if (cameraPreview) {
                    cameraPreview.srcObject = null;
                }
            } else if (MovimVisio.localVideo) {
                MovimVisio.localVideo.classList.remove('video_off');
            }

            VisioUtils.handleAudio();
            VisioUtils.enableScreenSharingButton();

            navigator.mediaDevices.enumerateDevices().then(devices => MovimVisio.gotDevices(withVideo, devices));
        }, (e) => {
            MovimTpl.finishedPage();
        });
    },

    requestUserMedia: function (withVideo) {
        return navigator.mediaDevices.getUserMedia(VisioUtils.getConstraints(withVideo)).catch(error => {
            if (!withVideo) {
                throw error;
            }

            return navigator.mediaDevices.getUserMedia(VisioUtils.getConstraints(false));
        });
    },

    gotDevices: function (withVideo, devicesInfo) {
        microphoneFound = false;
        cameraFound = false;

        let microphoneSelect = document.querySelector('select[name=default_microphone]');
        microphoneSelect.onchange = (e) => {
            localStorage.defaultMicrophone = e.target.value;
            MovimVisio.getUserMedia(withVideo);
        };
        microphoneSelect.innerText = '';

        VisioUtils.handleAudio();

        let cameraSelect = document.querySelector('select[name=default_camera]');

        if (cameraSelect) {
            cameraSelect.addEventListener('change', e => {
                localStorage.defaultCamera = e.target.value;

                let cameraPreview = document.querySelector('video#camera_preview');

                if (cameraPreview.srcObject) {
                    cameraPreview.srcObject.getTracks().forEach(track => track.stop());
                }

                cameraPreview.srcObject = null;

                MovimVisio.getUserMedia(withVideo);
            });
            cameraSelect.innerText = '';
        }

        for (const deviceInfo of devicesInfo) {
            if (deviceInfo.kind === 'audioinput') {
                const option = document.createElement('option');
                option.value = deviceInfo.deviceId;
                option.text = deviceInfo.label || `Microphone ${microphoneSelect.length + 1}`;

                if (deviceInfo.deviceId == localStorage.defaultMicrophone) {
                    option.selected = true;
                    microphoneFound = true;
                }

                microphoneSelect.appendChild(option);
            }

            if (withVideo && deviceInfo.kind === 'videoinput') {
                const option = document.createElement('option');
                option.value = deviceInfo.deviceId;
                option.text = deviceInfo.label || `Camera ${microphoneSelect.length + 1}`;

                if (deviceInfo.deviceId == localStorage.defaultCamera) {
                    option.selected = true;
                    cameraFound = true;
                }

                // Sometimes we can have two devices with the same id
                if (!cameraSelect.querySelector('option[value="' + deviceInfo.deviceId + '"]')) {
                    cameraSelect.appendChild(option);
                }
            }
        }

        if (microphoneFound == false) {
            localStorage.defaultMicrophone = microphoneSelect.value;
        }

        if (withVideo && cameraFound == false) {
            localStorage.defaultCamera = cameraSelect.value;
        }
    },

    goodbye: function (reason) {
        if (MovimVisio.pendingCall) {
            const pendingCall = MovimVisio.pendingCall;
            MovimVisio.pendingCall = null;
            MovimVisio.calling = false;
            Visio_ajaxGoodbye(pendingCall.fullJid, pendingCall.id, reason);
            return;
        }

        let visio = document.querySelector('#visio');
        Visio_ajaxGoodbye(visio.dataset.jid, this.id, reason);
    },

    clear: function () {
        MovimTpl.finishedPage();

        MovimVisio.id = null;
        MovimVisio.calling = false;
        MovimVisio.pendingCall = null;
        const startButton = document.getElementById('lobby_start');
        if (startButton) {
            startButton.classList.remove('disabled');
        }
        MovimVisio.setState(null);
        MovimVisio.setLobbyStatus(null);
        localStorage.removeItem('callId');
        localStorage.removeItem('callJid');

        clearInterval(MovimVisio.activeSpeakerIntervalId);

        let visio = document.querySelector('#visio');
        delete visio.dataset.type;
        delete visio.dataset.jid;
        delete visio.dataset.muji;
        delete visio.dataset.hasLocalVideo;
        MovimVisio.hasLocalVideo = false;

        if (document.fullscreenElement) {
            document.exitFullscreen();
        }

        if (VisioUtils.audioContext) {
            VisioUtils.audioContext.close();
            VisioUtils.audioContext = null;
        }

        if (MovimVisio.localAudio) {
            let stream = MovimVisio.localAudio.srcObject;

            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
        }

        if (MovimVisio.localVideo) {
            let stream = MovimVisio.localVideo.srcObject;

            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
        }

        if (MovimVisio.localStream) {
            MovimVisio.localStream.getTracks().forEach(function (track) {
                track.stop();
            });
            MovimVisio.localStream = null;
        }

        VisioUtils.disableScreenSharing();

        MovimVisio.localAudio = null;
        MovimVisio.localVideo = null;
        MovimVisio.screenSharing = null;
    },

    moveToChat: function (jid) {
        if (MovimVisio.observer != null) {
            MovimVisio.observer.disconnect();
        }

        var parts = MovimUtils.urlParts();
        if (parts.page != 'chat' || parts.params[0] != jid) {
            return;
        }

        const visio = document.getElementById('visio');
        const body = document.body;

        document.querySelector('#chat_widget header').after(visio);
        Chat.scrollRestore();

        const callback = (mutationList, observer) => {
            if (!document.getElementById('visio')) {
                document.getElementById('endcommon').before(visio);
            }
        };

        MovimVisio.observer = new MutationObserver(callback);
        MovimVisio.observer.observe(body, { childList: true, subtree: true });
    },

    callStop: function (id, jid) {
        localStorage.removeItem('callId');
        localStorage.removeItem('callJid');
    }
}

Visio_ajaxHttpGetStates();

MovimWebsocket.attach(() => {
    if (MovimVisio.services.length == 0) {
        Visio_ajaxResolveServices();
        Visio_ajaxCheckStatus(localStorage.getItem('callId'), localStorage.getItem('callJid'));
    }
});
