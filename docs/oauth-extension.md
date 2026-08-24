# Movim Passwordless Authentication via Delegated Token (ejabberd)

## 1. Purpose

Define a clean, maintainable architecture to enable **passwordless login** to Movim using existing **AD/Kerberos (smart-card) authentication**, while keeping **ejabberd on LDAP**.

The solution introduces a **trusted token broker** that issues short-lived credentials used by Movim to authenticate to ejabberd via `X-OAUTH2`.

---

## 2. Goals

- Eliminate password prompts for users
- Preserve existing ejabberd LDAP authentication backend
- Avoid storing user credentials in Movim
- Maintain compatibility with XMPP standards
- Ensure strong trust boundaries and auditability

---

## 3. Non-Goals

- Replacing ejabberd LDAP with JWT or external auth
- Using SASL EXTERNAL (incompatible with Movim architecture)
- Direct browser-to-XMPP connections (Converse-style)

---

## 4. High-Level Architecture

```
[ Browser ]
     │
     ▼
[ Reverse Proxy (Kerberos / Smart Card Auth) ]
     │
     ▼
[ Movim (Web + Daemon) ]
     │
     ▼
[ Token Broker ]
     │
     ▼
[ ejabberd (LDAP + OAuth/X-OAUTH2) ]
```

---

## 5. Trust Boundaries

### 5.1 Trusted Components

- Reverse Proxy (identity assertion)
- Movim backend (consumer of identity)
- Token Broker (issuer of credentials)
- ejabberd (verifier of credentials)

### 5.2 Untrusted Components

- Browser (identity must always be validated upstream)
- External clients

---

## 6. Identity Flow

### Step-by-step

1. User accesses Movim via browser
2. Reverse proxy authenticates user via Kerberos / smart card
3. Proxy injects trusted identity header:

   ```
   X-Remote-User: cueto
   ```

4. Movim reads header and constructs JID:

   ```
   cueto → cueto@cueto.tech
   ```

5. Movim requests token from broker
6. Broker issues short-lived token
7. Movim authenticates to ejabberd using:

   ```
   SASL: X-OAUTH2
   Scope: sasl_auth
   ```

8. ejabberd validates token and allows session

---

## 7. JID Mapping Rules

### Input formats supported

- `CUETO\cueto`
- `cueto`
- `cueto@cueto.tech`

### Normalization

```text
strip domain/netbios → lowercase → validate → append domain
```

### Output

```
cueto@cueto.tech
```

---

## 8. Token Broker Specification

## 8.1 Endpoint

```
POST /token
```

---

## 8.2 Request

### Headers

```
X-Remote-User: cueto
```

### Body (optional)

```json
{
  "jid": "cueto@cueto.tech"
}
```

---

## 8.3 Response

```json
{
  "access_token": "base64-or-jwt-token",
  "token_type": "Bearer",
  "expires_in": 300,
  "scope": "sasl_auth",
  "jid": "cueto@cueto.tech"
}
```

---

## 8.4 Requirements

- Only callable from Movim host (IP allowlist or mTLS)
- Must not accept arbitrary usernames
- Must validate identity source (header must be trusted)
- Tokens must be short-lived (≤ 5 minutes)

---

## 9. ejabberd Configuration Requirements

### 9.1 Authentication Backend

```yaml
auth_method: ldap
```

---

### 9.2 OAuth Support

- Enable OAuth module
- Allow `sasl_auth` scope
- Ensure token validation is active

---

### 9.3 Domain

```yaml
hosts:
  - "cueto.tech"
```

---

## 10. Movim Integration Requirements

### 10.1 Identity Source

Movim must read:

```
$_SERVER['HTTP_X_REMOTE_USER']
```

---

### 10.2 XMPP Login Method

Replace password-based login with:

```
SASL mechanism: X-OAUTH2
```

---

### 10.3 Token Usage

Movim must:

1. Request token from broker
2. Inject token into XMPP authentication flow
3. Avoid storing tokens long-term

---

## 11. Security Requirements

### 11.1 Token Security

- Short TTL (≤ 300 seconds)
- Bound to JID
- Not reusable across users

---

### 11.2 Transport Security

- All communication over TLS
- Broker endpoint must not be exposed publicly

---

### 11.3 Identity Assurance

- Only trust headers from reverse proxy
- Reject direct requests without proxy

---

## 12. Failure Modes

### 12.1 Token Failure

- Movim must retry once
- If still failing → surface login error

---

### 12.2 ejabberd Rejection

- Log SASL failure
- Do not fallback to password auth

---

### 12.3 Broker Unavailable

- Return HTTP 503
- Movim fails login cleanly

---

## 13. Observability

### Logs required

- Token issuance (jid, timestamp)
- Failed token validation
- Movim login attempts
- TLS / SASL failures

---

## 14. Migration Plan

### Phase 1

- Keep password login working
- Introduce token broker

### Phase 2

- Enable token login in Movim
- Validate end-to-end flow

### Phase 3

- Disable password login for users
- Enforce smart-card-based flow

---

## 15. Future Enhancements

- Token introspection endpoint
- Role-based scopes
- Audit integration with SIEM
- Multi-domain support

---

## 16. Summary

This design:

- Keeps ejabberd stable on LDAP
- Aligns with Movim’s daemon-based architecture
- Avoids misuse of SASL EXTERNAL
- Provides a secure, extensible passwordless login model

The critical component is the **token broker**, which bridges browser identity to XMPP authentication cleanly.
