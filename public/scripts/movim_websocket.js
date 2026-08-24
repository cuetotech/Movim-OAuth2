/**
 * Movim Websocket
 * Browser <-> Movim daemon RPC transport. This is not XMPP-over-WebSocket.
 */

WebSocket.prototype.unregister = function () {
    this.send(JSON.stringify({ 'func': 'unregister' }));
};

WebSocket.prototype.register = function (host) {
    this.send(JSON.stringify({ 'func': 'register', 'host': host }));
};

function MWSs(widget, func, params) {
    MovimWebsocket.send(widget, func, params);
}

var MovimWebsocket = {
    connection: null,
    initiated: [],
    attached: [],
    started: [],
    registered: [],
    reconnectTimeout: null,
    reconnectScheduled: false,
    initializing: false,
    attempts: 1,
    pong: false,
    closed: false,
    statusBar: null,

    launchAttached: function () {
        MovimWebsocket.statusBar?.classList.add('hide');
        for (let func of MovimWebsocket.attached) func();
    },

    launchRegistered: function () {
        for (let func of MovimWebsocket.registered) func();
    },

    launchStarted: function () {
        for (let func of MovimWebsocket.started) func();
    },

    launchInitiated: function () {
        for (let func of MovimWebsocket.initiated) func();
    },

    resolvePushEndpoint: async function () {
        if (!('serviceWorker' in navigator)) return null;
        const registration = await navigator.serviceWorker.getRegistration(SW_URI);
        if (!registration || !registration.pushManager) return null;
        const pushSubscription = await registration.pushManager.getSubscription();
        return pushSubscription ? pushSubscription.endpoint : null;
    },

    init: async function () {
        if (MovimWebsocket.closed || MovimWebsocket.initializing) return;

        if (MovimWebsocket.connection !== null && MovimWebsocket.connection.readyState < WebSocket.CLOSING) {
            return;
        }

        MovimWebsocket.initializing = true;
        clearTimeout(MovimWebsocket.reconnectTimeout);
        MovimWebsocket.reconnectTimeout = null;
        MovimWebsocket.reconnectScheduled = false;

        let uri = (window.location.protocol === 'https:' ? 'wss:' : 'ws:') + BASE_URI + 'ws/';

        try {
            if (MovimWebsocket.connection !== null) {
                MovimWebsocket.connection.onclose = null;
                if (MovimWebsocket.connection.readyState !== WebSocket.CLOSED) {
                    MovimWebsocket.connection.close();
                }
            }

            const pushEndpoint = await MovimWebsocket.resolvePushEndpoint();
            if (pushEndpoint) uri += '?push=' + encodeURIComponent(pushEndpoint);
            if (MovimWebsocket.closed) return;

            const connection = new WebSocket(uri);
            MovimWebsocket.connection = connection;

            connection.onopen = function () {
                console.log('Connection established!');
                MovimWebsocket.attempts = 1;
                clearTimeout(MovimWebsocket.reconnectTimeout);
                MovimWebsocket.reconnectTimeout = null;
                MovimWebsocket.reconnectScheduled = false;
                MovimWebsocket.launchAttached();
            };

            connection.onmessage = function (e) {
                const obj = JSON.parse(e.data);
                if (obj == null) return;

                if (obj.func == 'registered') MovimWebsocket.launchRegistered();
                if (obj.func == 'started') {
                    if (!['login', 'account', 'register', 'tag', 'about', 'community'].includes(MovimUtils.urlParts().page)) {
                        MovimUtils.disconnect();
                    } else {
                        MovimWebsocket.launchStarted();
                    }
                }
                if (obj.func == 'disconnected') MovimUtils.disconnect();
                if (obj.func == 'pong') MovimWebsocket.pong = true;
                MovimRPC.handle(obj);
            };

            connection.onclose = function (e) {
                console.log('Connection closed by the server or session closed');
                if (e.code == 1008) {
                    MovimWebsocket.closed = true;
                    clearTimeout(MovimWebsocket.reconnectTimeout);
                    MovimWebsocket.reconnectTimeout = null;
                    MovimWebsocket.reconnectScheduled = false;
                    MovimWebsocket.statusBar?.classList.remove('hide', 'connect');
                } else if (e.code == 1006) {
                    MovimWebsocket.reconnect();
                } else if (e.code == 1000 && !MovimWebsocket.closed) {
                    MovimUtils.disconnect();
                }
            };

            connection.onerror = function () {
                console.log('Connection error!');
                MovimWebsocket.statusBar?.classList.remove('hide', 'connect');
                this.onclose = null;
                MovimWebsocket.reconnect();
            };
        } catch (error) {
            MovimUtils.logError(error);
            MovimWebsocket.reconnect();
        } finally {
            MovimWebsocket.initializing = false;
        }
    },

    send: function (widget, func, params) {
        if (this.connection && this.connection.readyState == WebSocket.OPEN) {
            const body = { 'w': widget, 'f': func };
            if (params) body.p = params;
            this.connection.send(JSON.stringify({ 'func': 'message', 'b': body }));
        }
    },

    attach: function (func) { if (typeof func === 'function') this.attached.push(func); },
    register: function (func) { if (typeof func === 'function') this.registered.push(func); },
    start: function (func) { if (typeof func === 'function') this.started.push(func); },
    initiate: function (func) { if (typeof func === 'function') this.initiated.push(func); },
    clearAttached: function () { this.attached = []; },
    clearInitiated: function () { this.initiated = []; },
    clear: function () { this.clearAttached(); this.clearInitiated(); },

    unregister: function () {
        if (this.connection && this.connection.readyState === WebSocket.OPEN) this.connection.unregister();
    },

    reconnect: function () {
        if (MovimWebsocket.closed || MovimWebsocket.reconnectScheduled) return;

        const interval = MovimWebsocket.generateInterval();
        console.log('Try to reconnect');
        MovimWebsocket.reconnectScheduled = true;
        MovimWebsocket.reconnectTimeout = setTimeout(function () {
            MovimWebsocket.attempts++;
            MovimWebsocket.reconnectTimeout = null;
            MovimWebsocket.reconnectScheduled = false;
            MovimWebsocket.statusBar?.classList.remove('hide');
            MovimWebsocket.statusBar?.classList.add('connect');
            MovimWebsocket.init();
        }, interval);
    },

    generateInterval: function () {
        let maxInterval = (Math.pow(2, MovimWebsocket.attempts) - 1) * 1000;
        if (maxInterval > 10 * 1000) maxInterval = 10 * 1000;
        return Math.random() * maxInterval;
    }
};

MovimEvents.registerWindow('offline', 'movimwebsocket', () => {
    console.log('offline');
    if (MovimWebsocket.connection && typeof MovimWebsocket.connection.onerror === 'function') {
        MovimWebsocket.connection.onerror();
    }
});

MovimEvents.registerWindow('online', 'movimwebsocket', () => {
    if (MovimWebsocket.closed) return;
    if (MovimWebsocket.connection !== null && MovimWebsocket.connection.readyState < WebSocket.CLOSING) return;
    MovimWebsocket.statusBar?.classList.remove('hide');
    MovimWebsocket.statusBar?.classList.add('connect');
    MovimWebsocket.launchInitiated();
    MovimWebsocket.init();
});

window.onbeforeunload = function () {
    MovimWebsocket.closed = true;
    clearTimeout(MovimWebsocket.reconnectTimeout);
    MovimWebsocket.reconnectTimeout = null;
    MovimWebsocket.reconnectScheduled = false;
    if (MovimWebsocket.connection !== null) {
        MovimWebsocket.connection.onclose = null;
        MovimWebsocket.connection.close();
    }
};

MovimEvents.registerWindow('focus', 'movimwebsocket', () => {
    if (!MovimWebsocket.closed
        && MovimWebsocket.connection !== null
        && MovimWebsocket.connection.readyState > WebSocket.OPEN) {
        MovimWebsocket.statusBar?.classList.remove('hide');
        MovimWebsocket.statusBar?.classList.add('connect');
        MovimWebsocket.init();
    }
});

MovimEvents.registerWindow('loaded', 'movimwebsocket', () => {
    MovimWebsocket.statusBar = document.getElementById('status_websocket');
    MovimWebsocket.closed = false;
    MovimWebsocket.launchInitiated();
    MovimWebsocket.init();
});
