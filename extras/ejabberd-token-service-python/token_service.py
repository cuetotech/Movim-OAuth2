#!/usr/bin/env python3
import argparse
import ipaddress
import json
import logging
import re
import subprocess
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


TOKEN_RE = re.compile(r"([A-Za-z0-9._~+/=-]{16,})")


def load_config(path):
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def header_to_environ_name(header):
    return header.lower()


def normalize_jid(identity, xmpp_domain):
    value = identity.strip()
    if not value:
        raise ValueError("empty identity")

    if "@" in value:
        local, domain = value.split("@", 1)
    else:
        local, domain = value, xmpp_domain

    local = local.strip().lower()
    domain = domain.strip().lower()
    if not local or not domain:
        raise ValueError("invalid jid")
    if domain != xmpp_domain.lower():
        raise ValueError("unexpected xmpp domain")
    return f"{local}@{domain}"


def ip_allowed(remote_ip, allowed_entries):
    try:
        ip = ipaddress.ip_address(remote_ip)
    except ValueError:
        return False

    for entry in allowed_entries:
        try:
            if "/" in entry:
                if ip in ipaddress.ip_network(entry, strict=False):
                    return True
            elif ip == ipaddress.ip_address(entry):
                return True
        except ValueError:
            logging.warning("Ignoring invalid allowed IP entry: %s", entry)
    return False


def issue_token(config, jid):
    ttl = max(int(config.get("token_ttl", 300)), 1)
    scope = str(config.get("token_scope", "sasl_auth")).strip()
    if not scope:
        raise RuntimeError("token_scope is empty")

    command = [
        config.get("ejabberdctl_path", "/usr/sbin/ejabberdctl"),
        "oauth_issue_token",
        jid,
        str(ttl),
        scope,
    ]

    result = subprocess.run(
        command,
        check=False,
        capture_output=True,
        text=True,
        timeout=max(int(config.get("command_timeout", 5)), 1),
    )
    output = "\n".join([result.stdout.strip(), result.stderr.strip()]).strip()
    if result.returncode != 0:
        raise RuntimeError(output or "ejabberdctl token issuance failed")

    match = TOKEN_RE.search(output)
    if not match:
        raise RuntimeError("unable to parse ejabberdctl token output")
    return match.group(1)


class TokenHandler(BaseHTTPRequestHandler):
    server_version = "EjabberdTokenServicePython/1.0"

    def do_POST(self):
        if self.path not in ("/token", "/token/"):
            self.send_json(HTTPStatus.NOT_FOUND, {
                "error": "not_found",
                "message": "Only POST /token is available.",
            })
            return

        config = self.server.config
        remote_ip = self.client_address[0]
        if not ip_allowed(remote_ip, config.get("allowed_ips", [])):
            logging.warning("Denied token request from %s", remote_ip)
            self.send_json(HTTPStatus.FORBIDDEN, {"error": "forbidden"})
            return

        expected_authorization = config.get("authorization_header", "")
        if expected_authorization:
            actual_authorization = self.headers.get("Authorization", "")
            if actual_authorization != expected_authorization:
                logging.warning("Rejected authorization from %s", remote_ip)
                self.send_json(HTTPStatus.UNAUTHORIZED, {"error": "unauthorized"})
                return

        content_length = int(self.headers.get("Content-Length", "0") or "0")
        if content_length > 8192:
            self.send_json(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, {"error": "too_large"})
            return

        try:
            body = json.loads(self.rfile.read(content_length).decode("utf-8") or "{}")
        except json.JSONDecodeError:
            self.send_json(HTTPStatus.BAD_REQUEST, {"error": "invalid_json"})
            return

        trusted_header = config.get("trusted_header", "X-Remote-User")
        identity = self.headers.get(trusted_header, "")
        body_field = config.get("jid_body_field", "jid")
        requested_jid = str(body.get(body_field, ""))

        try:
            header_jid = normalize_jid(identity, config["xmpp_domain"])
            body_jid = normalize_jid(requested_jid, config["xmpp_domain"])
            if header_jid != body_jid:
                raise ValueError("identity mismatch")
            token = issue_token(config, header_jid)
        except (KeyError, ValueError) as exc:
            logging.warning("Bad token request from %s: %s", remote_ip, exc)
            self.send_json(HTTPStatus.BAD_REQUEST, {"error": "invalid_identity"})
            return
        except Exception as exc:
            logging.exception("Token issuance failed for %s", requested_jid)
            self.send_json(HTTPStatus.BAD_GATEWAY, {
                "error": "token_unavailable",
                "message": "ejabberd could not issue a token right now.",
            })
            return

        logging.info("Issued token for %s from %s", header_jid, remote_ip)
        self.send_json(HTTPStatus.OK, {
            "access_token": token,
            "token_type": "Bearer",
            "expires_in": max(int(config.get("token_ttl", 300)), 1),
            "scope": config.get("token_scope", "sasl_auth"),
            "jid": header_jid,
        })

    def do_GET(self):
        self.send_json(HTTPStatus.METHOD_NOT_ALLOWED, {
            "error": "method_not_allowed",
            "message": "Use POST /token.",
        })

    def log_message(self, fmt, *args):
        logging.info("%s - %s", self.client_address[0], fmt % args)

    def send_json(self, status, payload):
        data = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)


def main():
    parser = argparse.ArgumentParser(description="ejabberd OAuth token service")
    parser.add_argument("--config", default="/etc/ejabberd-token-service-python/config.json")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    config = load_config(args.config)
    address = (config.get("listen_host", "127.0.0.1"), int(config.get("listen_port", 8091)))

    httpd = ThreadingHTTPServer(address, TokenHandler)
    httpd.config = config
    logging.info("Listening on http://%s:%s", address[0], address[1])
    httpd.serve_forever()


if __name__ == "__main__":
    main()
