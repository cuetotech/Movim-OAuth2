# Airgap Patch / Protocol Matrix

This document records which restored patches are protocol requirements and which are local reliability or UX policy. It is intended to be reviewed before promoting an airgap build.

## Standards-backed patches

| Area | Specification | Status in this branch | Notes |
| --- | --- | --- | --- |
| Jingle Message Initiation | XEP-0353 v0.8.0 | Implemented | JMI messages use `type='chat'`, include XEP-0334 `<store/>`, use the proposal ID consistently, parse inbound `<ringing/>`, and carry Jingle reasons on reject/retract/finish. |
| Simultaneous call proposals | XEP-0353 §4.1 | Implemented | For simultaneous unanswered proposals to the same peer, lower session ID wins using bytewise ordering; losing proposal is rejected/retracted with `<expired/>` and `<tie-break/>`. Equal IDs fall back to JID ordering. |
| Busy rejection | XEP-0353 §3.5 + XEP-0166 reason | Implemented | A call received while already engaged is rejected with a Jingle `<busy/>` reason. |
| Ringing state | XEP-0353 §3.2 | Implemented | Inbound `<ringing/>` is dispatched only when its ID and peer match the current unanswered proposal, then surfaced to the existing localized browser call state. |
| Jingle session reasons | XEP-0166 | Preserved / used | Existing Jingle session termination behavior is retained; JMI reason elements use the Jingle reason namespace. |
| RTP / screen sharing | XEP-0167, XEP-0507, RFC 4796 | Upstream implementation retained | The older fork's screen-sharing patch is not restored because current upstream already negotiates screen media and marks slide content. |
| HTTP File Upload | XEP-0363 v1.2.0 | Implemented / hardened | PUT uses the requested `Content-Length` and `Content-Type`; only slot-provided `Authorization`, `Cookie`, and `Expires` headers are forwarded; CR/LF is stripped from header names and values; repeated allowed-header values are preserved in slot order; upload is marked ready only after HTTP 201. The HTTP client timeout is 300 seconds to align with the XEP's implementation guidance for PUT-slot validity. |

### XEP-0353 caveat

XEP-0353 is Experimental. This branch implements the portions needed by the existing Movim one-to-one call flow, including current §4.1 simultaneous-proposal tie breaking. Section 4.2 session migration (starting a replacement JMI session while an already answered session with the same peer exists) is not implemented here because it was not part of the historical patch and requires a broader call-migration design. The existing behavior rejects a second call while already engaged.

## Local implementation patches (no XMPP specification)

| Patch | Classification | Rationale |
| --- | --- | --- |
| Streaming the PHP temporary file to the upload service | Local reliability policy | Avoids reading the entire upload into PHP memory; XEP-0363 specifies the HTTP exchange, not buffering strategy. |
| Browser 0-50% / server 50-100% upload progress | Local UX policy | Represents the two physical transfers. XEP-0363 does not define UI progress. |
| WebSocket reconnect serialization | Movim transport hardening | `public/scripts/movim_websocket.js` is the browser-to-Movim daemon RPC socket, not XMPP-over-WebSocket. |
| AudioWorklet microphone meter | Browser/media hardening | XMPP/Jingle does not specify local microphone level measurement. ScriptProcessor remains as fallback. |
| Search DOM null guards | Browser robustness | No protocol mapping. |
| Web Share Target `enctype` | Web platform behavior | No XMPP mapping. |
| Apache unauthenticated bootstrap paths | Deployment policy | Required so PWA/bootstrap assets can load around enterprise authentication; no XMPP mapping. |

## OAuth authentication note

The fork's `X-OAUTH2` SASL mechanism is implementation-specific and is not an XMPP Extension Protocol. XMPP uses SASL for authentication; the standardized OAuth SASL mechanism is `OAUTHBEARER` from RFC 7628. `X-OAUTH2` is retained for compatibility with the target ejabberd deployment and must not be represented as standards-mandated XMPP behavior.

## Airgap acceptance checks

Before promotion, test at minimum:

1. Outgoing audio and video calls: calling -> ringing -> answered -> hangup.
2. Declined and unanswered outgoing calls produce the expected reject/retract state.
3. Simultaneous calls from both peers converge on one proposal without leaving either client busy.
4. Screen sharing can start/stop during an established one-to-one call.
5. Microphone level meter works in the target browser; fallback works if AudioWorklet is unavailable.
6. Small and large HTTP uploads complete against the target ejabberd upload service and return HTTP 201.
7. Upload slot headers containing disallowed names or newline characters are not forwarded, and repeated allowed headers retain their original value order.
8. Network loss/recovery does not create duplicate browser-daemon WebSocket connections or reconnect timers.
9. OAuth-only login, password fallback (if enabled), and token expiration/re-login are tested with the actual airgap reverse proxy and ejabberd token service.
