"""Exercise operational public.php routes with isolated files, fake UISP and loopback HTTP only."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
SECRET = "FIXTURE-WEBHOOK-SECRET-0123456789ABCDEF"


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


SDK = r'''<?php
final class ApiUispClient {
    public static function security() {
        return new class {
            public function getUser() {
                $role = $_SERVER['HTTP_X_FIXTURE_ROLE'] ?? 'none';
                if (!in_array($role,['view','edit','client'],true)) return null;
                return (object)['isClient'=>$role==='client','permissions'=>['system/plugins'=>$role==='view'?'view':'edit']];
            }
        };
    }
    public static function crm() {
        return new class {
            public function get($path,$parameters=[]) {
                file_put_contents('crm-reads.log',$path."\n",FILE_APPEND);
                if ($path==='clients/services/42') return ['id'=>42,'clientId'=>17,'status'=>1,'servicePlanId'=>9,
                    'attributes'=>[['key'=>'site','customAttributeId'=>1,'value'=>'Fixture East'],
                        ['key'=>'account','customAttributeId'=>2,'value'=>'fixture-user'],
                        ['key'=>'manualIp','customAttributeId'=>3,'value'=>'192.0.2.42']]];
                if ($path==='custom-attributes') return [
                    ['id'=>1,'key'=>'site','attributeType'=>'service'],['id'=>2,'key'=>'account','attributeType'=>'service'],
                    ['id'=>3,'key'=>'manualIp','attributeType'=>'service']];
                if ($path==='clients/17') return ['id'=>17,'firstName'=>'Fixture','lastName'=>'Customer'];
                throw new RuntimeException('PRIVATE-CRM-ERROR');
            }
        };
    }
}
'''

SETUP = r'''<?php
function run_setup() {
    file_put_contents('setup-called','controlled fixture');
    if (($GLOBALS['webhook']??false)===true) { respond('controlled_webhook_flow'); exit; }
}
'''
CACHE = r'''<?php
function cache_setup() { file_put_contents('cache-setup-called','controlled fixture'); }
function cache_sync() { file_put_contents('cache-sync-called','unexpected'); }
'''


def populate(directory: Path) -> None:
    for tree in ("lib", "includes"):
        source = (ROOT / "src" / tree).resolve(strict=True)
        destination = directory / tree
        destination.mkdir()
        for path in source.rglob("*"):
            check(not path.is_symlink(), "Unexpected link in test source")
            relative = path.resolve(strict=True).relative_to(source)
            target = destination / relative
            if path.is_dir():
                target.mkdir(exist_ok=True)
            elif path.is_file():
                shutil.copy2(path, target)
    shutil.copy2(ROOT / "src/public.php", directory / "public.php")
    (directory / "vendor").mkdir()
    (directory / "vendor/autoload.php").write_text("<?php", encoding="utf-8")
    (directory / "lib/api_uisp_client.php").write_text(SDK, encoding="utf-8")
    (directory / "lib/api_setup.php").write_text(SETUP, encoding="utf-8")
    (directory / "lib/api_cache.php").write_text(CACHE, encoding="utf-8")
    # This GET fixture avoids the pre-existing panel's provisioning bootstrap.
    (directory / "lib/api_router.php").write_text("<?php", encoding="utf-8")
    (directory / "lib/api_backup.php").write_text("<?php", encoding="utf-8")
    (directory / "public/panel_v2").mkdir(parents=True)
    shutil.copy2(ROOT / "src/public/panel_v2/index.php", directory / "public/panel_v2/index.php")
    (directory / "router.php").write_text(
        '<?php set_include_path(__DIR__."/lib".PATH_SEPARATOR.get_include_path()); require __DIR__."/public.php";', encoding="utf-8")
    (directory / "data").mkdir()
    (directory / "data/config.json").write_text(json.dumps({"webhookSecret": SECRET, "debugEnable": False}), encoding="utf-8")
    with sqlite3.connect(directory / "data/data.db") as database:
        database.executescript((ROOT / "src/includes/schema.sql").read_text(encoding="utf-8"))
        database.executemany("INSERT INTO config(key,value) VALUES(?,?)", [
            ("device_name_attr", "site"), ("pppoe_user_attr", "account"), ("ip_addr_attr", "manualIp")])
    with sqlite3.connect(directory / "data/cache.db") as database:
        database.executescript((ROOT / "src/includes/cache.sql").read_text(encoding="utf-8"))
        database.execute("INSERT INTO clients(id,firstName,lastName) VALUES(17,'Fixture','Customer')")
        database.execute("INSERT INTO services(id,clientId,username,status,planId) VALUES(42,17,'fixture-user',1,9)")
        database.execute("INSERT INTO network(id,address) VALUES(42,'192.0.2.42')")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", type=Path, required=True)
    parser.add_argument("--extension-dir", type=Path)
    args = parser.parse_args()
    php = args.php.resolve(strict=True)
    extension = (args.extension_dir or php.parent / "ext").resolve(strict=True)
    with tempfile.TemporaryDirectory(prefix="uisp-operations-http-") as temporary:
        directory = Path(temporary)
        populate(directory)
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        base = f"http://127.0.0.1:{port}"
        (directory / "ucrm.json").write_text(json.dumps({"ucrmPublicUrl": base}), encoding="utf-8")
        command = [str(php), "-n", "-d", f"extension_dir={extension}", "-d", "extension=sqlite3", "-S",
                   f"127.0.0.1:{port}", "-t", str(directory), str(directory / "router.php")]
        flags = subprocess.CREATE_NO_WINDOW if hasattr(subprocess, "CREATE_NO_WINDOW") else 0
        with (directory / "server.log").open("w", encoding="utf-8") as log:
            server = subprocess.Popen(command, cwd=directory, stdout=log, stderr=log, stdin=subprocess.DEVNULL, creationflags=flags)
            try:
                for _ in range(100):
                    if server.poll() is not None:
                        raise RuntimeError("Isolated HTTP server failed: " + (directory / "server.log").read_text())
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=.05):
                            break
                    except OSError:
                        time.sleep(.02)
                else:
                    raise RuntimeError("Isolated HTTP server did not start")

                def request(body: dict | str | None, *, role: str = "view", origin: str | None = base,
                            content_type: str = "application/json", method: str = "POST", query: str = "",
                            headers: dict[str, str] | None = None) -> tuple[int, dict | str, dict]:
                    raw = body.encode() if isinstance(body, str) else None if body is None else json.dumps(body).encode()
                    selected = {"Content-Type": content_type, "X-Fixture-Role": role}
                    if origin is not None:
                        selected["Origin"] = origin
                    selected.update(headers or {})
                    sent = Request(base + "/public.php" + query, data=raw, headers=selected, method=method)
                    try:
                        response = urlopen(sent, timeout=6)
                    except HTTPError as rejected:
                        response = rejected
                    with response:
                        text = response.read().decode()
                        content = json.loads(text) if "application/json" in response.headers.get("Content-Type", "") else text
                        return response.status, content, dict(response.headers.items())

                def operation(target: str, action: str = "list", data: dict | None = None, change: str = "list") -> dict:
                    return {"changeType": change, "target": target, "action": action, "data": {} if data is None else data}

                def untouched() -> None:
                    for name in ("setup-called", "cache-setup-called", "cache-sync-called", "data/jobs.db", "data/queue.json"):
                        check(not (directory / name).exists(), "Operational request initialized execution/cache state: " + name)

                def protected_hashes() -> dict[str, str]:
                    return {name: hashlib.sha256((directory / name).read_bytes()).hexdigest()
                            for name in ("data/data.db", "data/cache.db", "data/config.json")}

                initial = protected_hashes()
                for target, action, data in [
                    ("automation_health", "list", {}), ("service_diagnostics", "search", {"query": "Fixture"}),
                    ("service_diagnostics", "inspect", {"serviceId": 42}), ("provisioning_verification", "list", {}),
                    ("provisioning_verification", "read", {"serviceId": 42}), ("provisioning_verification", "recheck", {"serviceId": 42})]:
                    status, body, received = request(operation(target, action, data))
                    check(status == 200 and body["status"] == "ok", f"Read-only role could not access {target}/{action}: {status} {body}")
                    check("no-store" in received.get("Cache-Control", ""), "Operational response is cacheable")
                    if target == "service_diagnostics" and action == "inspect":
                        check(body["data"]["service_id"] == 42 and body["data"]["uisp"]["available"], "Exact service inspection did not execute")
                    if target == "provisioning_verification" and action in {"read", "recheck"}:
                        check(body["data"]["service_id"] == 42 and body["data"]["reason"] == "no_verification", "Missing verification was not explicit")
                    untouched()
                check(protected_hashes() == initial, "Operational reads changed stored configuration/cache")
                check(not (directory / "data/automation-health").exists(), "Polling created a heartbeat")

                for role in ("none", "client"):
                    for target in ("automation_health", "service_diagnostics", "provisioning_verification"):
                        status, body, _ = request(operation(target), role=role)
                        check(status == 403 and body["message"] == "unauthorized", "Unauthorized operational read was accepted")
                        untouched()
                reads = (directory / "crm-reads.log").read_text()
                for origin in (None, "http://other.invalid", "https://127.0.0.1:" + str(port)):
                    status, body, _ = request(operation("automation_health"), origin=origin)
                    check(status == 403 and body["message"] == "invalid_origin", "Missing or foreign Origin accepted")
                for content_type in ("text/plain", "application/x-www-form-urlencoded", "application/jsonp", "application/json-evil"):
                    status, body, _ = request(operation("automation_health"), content_type=content_type)
                    check(status == 400 and body["message"] == "json_content_type_required", "Non-JSON operational read accepted")
                for invalid in ("{", "[]", "null"):
                    status, _, _ = request(invalid)
                    check(status in (400, 403), "Invalid JSON shape accepted")
                    untouched()
                for sid in (0, -1, "42", True, 1.5, {}, []):
                    for target, action in (("service_diagnostics", "inspect"), ("provisioning_verification", "read"), ("provisioning_verification", "recheck")):
                        status, body, _ = request(operation(target, action, {"serviceId": sid}))
                        check(status == 400 and body["message"] == "invalid_operations_request", "Invalid service ID reached diagnostic state")
                        untouched()
                for body in [operation("automation_health", data={"extra": True}), operation("service_diagnostics", "search", {"query": ""}),
                             operation("service_diagnostics", "search", {"query": []}), operation("service_diagnostics", "inspect", {"serviceId": 42, "extra": True}),
                             {**operation("automation_health"), "entity": "service"}, operation("provisioning_verification", "apply", {"serviceId": 42})]:
                    status, _, _ = request(body)
                    check(status == 400, "Unsupported operational payload accepted")
                    untouched()
                for target in ("automation_health", "service_diagnostics", "provisioning_verification"):
                    status, body, _ = request(operation(target, change="update"), role="view")
                    check(status == 403, "Read-only role obtained write permission")
                    status, body, _ = request(operation(target, change="update"), role="edit")
                    check(status == 400 and body["message"] == "invalid_operations_request", "Non-read action bypassed operational guard")
                    untouched()
                check((directory / "crm-reads.log").read_text() == reads, "Rejected requests contacted CRM")

                webhook = {"entity": "service", "entityId": 42, "changeType": "edit", "uuid": "FIXTURE-DELIVERY-00000001"}
                status, _, _ = request(webhook, role="none", origin=None, headers={"X-Webhook-Token": "wrong"})
                check(status == 403 and not (directory / "data/automation-health").exists(), "Rejected webhook created an accepted stamp")
                status, _, _ = request(webhook, role="none", origin=None, content_type="text/plain", headers={"X-Webhook-Token": SECRET})
                check(status == 400 and not (directory / "data/automation-health").exists(), "Non-JSON webhook created an accepted stamp")
                untouched()
                status, body, _ = request(webhook, role="none", origin=None, headers={"X-Webhook-Token": SECRET})
                check(status == 200 and body["message"] == "controlled_webhook_flow", "Authenticated webhook did not enter controlled flow")
                stamp = directory / "data/automation-health/state.json"
                check(stamp.is_file(), "Accepted webhook was not recorded")
                (directory / "setup-called").unlink()
                saved = stamp.read_bytes()
                status, body, _ = request(operation("automation_health"))
                check(status == 200 and body["data"]["webhook"]["state"] == "observed", "Accepted delivery not visible in health")
                check(stamp.read_bytes() == saved, "Health poll changed its observation")
                untouched()
                status, body, _ = request(None, method="GET", query="?page=panel")
                check(status == 200 and isinstance(body, str) and "Salud de la automatización" in body, "Panel GET did not serve operations UI")
                check(stamp.read_bytes() == saved and not (directory / "data/jobs.db").exists(), "Panel GET manufactured health or jobs")
                check(protected_hashes() == initial, "HTTP integration changed stored service state")
                logs = (directory / "data/plugin.log").read_text() if (directory / "data/plugin.log").exists() else ""
                for private in (SECRET, "PRIVATE-CRM-ERROR"):
                    check(private not in logs, "Operational HTTP logs exposed private material")
                print("Operations HTTP regression passed")
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)


if __name__ == "__main__":
    main()
